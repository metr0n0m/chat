<?php
declare(strict_types=1);

namespace Chat\Moderation;

use Chat\DB\Connection;

/**
 * Приёмник срабатываний автодетекторов — этапы S3 (тень) и S4 (боевой) движка санкций.
 *
 * Детектор сообщает о нарушении; репортёр по правилам (sanction_rules) вычисляет
 * решение (тип санкции + ступень лестницы по рецидиву) и:
 *  - mode='shadow' (по умолчанию) — пишет решение в moderation_shadow_log, не банит;
 *  - mode='live' + autonomy_state='active' — применяет санкцию через SanctionService
 *    (origin='system', авточитаемая причина) с защитами S4:
 *      • circuit-breaker: не больше N систем-санкций/мин → автоном на паузу + тень;
 *      • kill-switch: autonomy_state='paused' возвращает в тень одним флагом;
 *      • иммунитет стаффа и I-1/I-3 — на уровне SanctionService;
 *      • цель только с известным аккаунтом (IP-бан отложен по дизайну §8.2).
 *
 * Включение боевого режима: UPDATE sanction_rules SET value_json='"live"'
 * WHERE rule_key='mode'; пауза/возобновление — ключ autonomy_state ('paused'/'active').
 * Только решение владельца после калибровки тени.
 */
final class ViolationReporter
{
    /** Какую санкцию выдала бы система по типу триггера. */
    private const SANCTION_BY_TRIGGER = [
        'bruteforce' => 'ban_global',
        'spoof'      => 'ban_global',
        'stopword'   => 'ban_room',
        'flood'      => 'ban_room',
    ];

    private static ?array $rulesCache = null;
    private static float $rulesLoadedAt = 0.0;
    private const RULES_TTL_SEC = 30.0;

    /**
     * @param array{
     *   trigger_code: string,
     *   target_user_id?: int|null,
     *   target_ip?: string|null,
     *   room_id?: int|null,
     *   details?: string|null
     * } $v
     * @return array{mode:string, would_sanction:string, would_duration:?string}
     */
    public static function report(array $v): array
    {
        $trigger  = (string) $v['trigger_code'];
        $targetId = isset($v['target_user_id']) ? (int) $v['target_user_id'] : null;
        $targetIp = isset($v['target_ip']) ? (string) $v['target_ip'] : null;
        $roomId   = isset($v['room_id']) ? (int) $v['room_id'] : null;
        $details  = isset($v['details']) ? mb_substr((string) $v['details'], 0, 500) : null;

        $rules      = self::rules();
        $escalation = $rules['escalation'][$trigger] ?? [];
        $ladder     = (array) ($escalation['ladder'] ?? ['1h', '24h', '7d', '30d', 'permanent']);
        $threshold  = max(1, (int) ($escalation['threshold'] ?? $escalation['attempts'] ?? 1));

        $live = (string) ($rules['mode'] ?? 'shadow') === 'live'
             && (string) ($rules['autonomy_state'] ?? 'active') === 'active';

        // Ступень лестницы по рецидиву: счётчик раздельный по типу нарушения.
        // В тени рецидив считается по теневым срабатываниям, в бою — по реальным
        // санкциям системы (moderation_events) за 30 дней.
        $prior = self::priorHits($trigger, $targetId, $targetIp, $live);
        $step  = min(intdiv($prior, $threshold), count($ladder) - 1);

        $wouldSanction = self::SANCTION_BY_TRIGGER[$trigger] ?? 'ban_room';
        $wouldDuration = (string) $ladder[$step];

        // ── Тень (по умолчанию) ────────────────────────────────────────────
        if (!$live) {
            self::shadowRow($trigger, $targetId, $targetIp, $roomId, $wouldSanction, $wouldDuration, $details);
            return ['mode' => 'shadow', 'would_sanction' => $wouldSanction, 'would_duration' => $wouldDuration];
        }

        // ── Боевой режим (S4) ──────────────────────────────────────────────

        // IP-бан отложен (§8.2): без известного аккаунта санкция невозможна — тень.
        if ($targetId === null) {
            self::shadowRow($trigger, null, $targetIp, $roomId, $wouldSanction, $wouldDuration,
                trim('live: цель только по IP, бан отложен. ' . (string) $details));
            return ['mode' => 'live_skipped', 'would_sanction' => $wouldSanction, 'would_duration' => $wouldDuration];
        }

        // Circuit-breaker (§9.3): всплеск систем-санкций → автоном на паузу.
        $maxPerMin = max(1, (int) ($rules['circuit_breaker']['max_system_sanctions_per_min'] ?? 10));
        if (self::systemSanctionsLastMinute() >= $maxPerMin) {
            self::setRule('autonomy_state', 'paused');
            self::flushRules();
            error_log('[sanctions] CIRCUIT BREAKER: >' . $maxPerMin
                . ' автосанкций/мин — автоном поставлен на паузу, требуется ручное включение.');
            self::shadowRow('circuit_breaker', $targetId, $targetIp, $roomId, $wouldSanction, $wouldDuration,
                'предохранитель сработал, автоном на паузе');
            return ['mode' => 'paused', 'would_sanction' => $wouldSanction, 'would_duration' => $wouldDuration];
        }

        // Применение санкции единой точкой движка (иммунитеты — внутри apply).
        $intent = [
            'type'           => $wouldSanction,
            'target_user_id' => $targetId,
            'origin'         => 'system',
            'trigger_code'   => $trigger,
            'target_ip'      => $targetIp,
            'reason'         => self::autoReason($wouldSanction, $wouldDuration, $trigger, $prior),
        ];
        if ($wouldSanction === 'ban_room') {
            $intent['room_id'] = $roomId;
        }
        $minutes = self::durationToMinutes($wouldDuration);
        if ($minutes !== null) {
            $intent['minutes'] = $minutes;
        }

        $applied = SanctionService::apply($intent);
        if (isset($applied['error'])) {
            // отказ движка (иммунитет стаффа и т.п.) — фиксируем в тени для статистики
            self::shadowRow($trigger, $targetId, $targetIp, $roomId, 'none', null,
                'live: отказ движка — ' . $applied['error']);
            return ['mode' => 'live_refused', 'would_sanction' => 'none', 'would_duration' => null];
        }

        return [
            'mode'           => 'live',
            'would_sanction' => $wouldSanction,
            'would_duration' => $wouldDuration,
            'event_id'       => (int) $applied['event_id'],
        ];
    }

    /** Авточитаемая причина (§10): человеку понятно, за что и почему такой срок. */
    private static function autoReason(string $sanction, string $duration, string $trigger, int $prior): string
    {
        $labels = [
            'bruteforce' => 'перебор паролей',
            'stopword'   => 'запрещённое слово',
            'flood'      => 'флуд',
            'spoof'      => 'подмена сессии',
        ];
        $what = $labels[$trigger] ?? $trigger;
        $durText = $duration === 'permanent' ? 'бессрочно' : 'на ' . $duration;
        $history = $prior > 0
            ? ' Нарушений этого типа за 30 дней: ' . ($prior + 1) . '. Эскалация по рецидиву.'
            : '';
        return 'Автобан ' . $durText . ': ' . $what . '.' . $history;
    }

    /** Срок лестницы → минуты; null = бессрочно. */
    private static function durationToMinutes(string $duration): ?int
    {
        return match ($duration) {
            '1h'  => 60,
            '3h'  => 180,
            '24h' => 1440,
            '7d'  => 10080,
            '30d' => 43200,
            default => null, // permanent
        };
    }

    private static function shadowRow(
        string $trigger,
        ?int $targetId,
        ?string $targetIp,
        ?int $roomId,
        string $wouldSanction,
        ?string $wouldDuration,
        ?string $details
    ): void {
        Connection::getInstance()->execute(
            'INSERT INTO moderation_shadow_log
                (trigger_code, target_user_id, target_ip, room_id, would_sanction, would_duration, details)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$trigger, $targetId, $targetIp, $roomId, $wouldSanction, $wouldDuration, $details]
        );
    }

    /** Счётчик систем-санкций за последнюю минуту (для circuit-breaker). */
    private static function systemSanctionsLastMinute(): int
    {
        $row = Connection::getInstance()->fetchOne(
            "SELECT COUNT(*) AS c FROM moderation_events
             WHERE origin = 'system'
               AND act IN ('ban_room', 'ban_global')
               AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)"
        );
        return (int) ($row['c'] ?? 0);
    }

    /** Запись/обновление правила конфигурации движка. */
    public static function setRule(string $key, string|array $value): void
    {
        Connection::getInstance()->execute(
            'REPLACE INTO sanction_rules (rule_key, value_json) VALUES (?, ?)',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** Конфигурация движка из sanction_rules (кэш на 30 секунд). */
    public static function rules(): array
    {
        $now = microtime(true);
        if (self::$rulesCache !== null && $now - self::$rulesLoadedAt < self::RULES_TTL_SEC) {
            return self::$rulesCache;
        }

        $rules = [];
        foreach (Connection::getInstance()->fetchAll('SELECT rule_key, value_json FROM sanction_rules') as $row) {
            $decoded = json_decode((string) $row['value_json'], true);
            if ($decoded !== null) {
                $rules[(string) $row['rule_key']] = $decoded;
            }
        }

        self::$rulesCache    = $rules;
        self::$rulesLoadedAt = $now;
        return $rules;
    }

    /** Сброс кэша правил (для тестов и смены конфигурации на лету). */
    public static function flushRules(): void
    {
        self::$rulesCache = null;
        self::$rulesLoadedAt = 0.0;
    }

    private static function priorHits(string $trigger, ?int $targetId, ?string $targetIp, bool $live = false): int
    {
        if ($targetId === null && $targetIp === null) {
            return 0;
        }
        $table  = $live ? 'moderation_events' : 'moderation_shadow_log';
        $where  = ['trigger_code = ?', 'created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)'];
        $params = [$trigger];
        if ($live) {
            $where[] = "origin = 'system'";
        }
        if ($targetId !== null) {
            $where[]  = 'target_user_id = ?';
            $params[] = $targetId;
        } else {
            $where[]  = 'target_ip = ?';
            $params[] = $targetIp;
        }
        $row = Connection::getInstance()->fetchOne(
            'SELECT COUNT(*) AS c FROM ' . $table . ' WHERE ' . implode(' AND ', $where),
            $params
        );
        return (int) ($row['c'] ?? 0);
    }
}
