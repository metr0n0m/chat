<?php
declare(strict_types=1);

namespace Chat\Moderation;

use Chat\DB\Connection;

/**
 * Приёмник срабатываний автодетекторов — этап S3 движка санкций (теневой режим).
 *
 * Детектор сообщает о нарушении; репортёр по правилам (sanction_rules) вычисляет,
 * ЧТО БЫ сделала система (тип санкции + ступень лестницы по рецидиву), и пишет
 * решение в moderation_shadow_log. РЕАЛЬНАЯ санкция не применяется:
 * боевой режим — этап S4 (circuit-breaker, kill-switch, ревью калибровки).
 * Если в правилах mode='live', репортёр остаётся в тени и пишет предупреждение
 * в error_log — защита от преждевременного включения.
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

        $rules      = self::rules();
        $escalation = $rules['escalation'][$trigger] ?? [];
        $ladder     = (array) ($escalation['ladder'] ?? ['1h', '24h', '7d', '30d', 'permanent']);
        $threshold  = max(1, (int) ($escalation['threshold'] ?? $escalation['attempts'] ?? 1));

        // Ступень лестницы по рецидиву: счётчик раздельный по типу нарушения.
        // В тени рецидив считается по прошлым теневым срабатываниям за 30 дней.
        $prior = self::priorHits($trigger, $targetId, $targetIp);
        $step  = min(intdiv($prior, $threshold), count($ladder) - 1);

        $wouldSanction = self::SANCTION_BY_TRIGGER[$trigger] ?? 'ban_room';
        $wouldDuration = (string) $ladder[$step];

        Connection::getInstance()->execute(
            'INSERT INTO moderation_shadow_log
                (trigger_code, target_user_id, target_ip, room_id, would_sanction, would_duration, details)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $trigger, $targetId, $targetIp, $roomId,
                $wouldSanction, $wouldDuration,
                isset($v['details']) ? mb_substr((string) $v['details'], 0, 500) : null,
            ]
        );

        if ((string) ($rules['mode'] ?? 'shadow') === 'live') {
            error_log('[sanctions] mode=live запрошен, но боевой автоном — этап S4; работаю в shadow.');
        }

        return ['mode' => 'shadow', 'would_sanction' => $wouldSanction, 'would_duration' => $wouldDuration];
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

    private static function priorHits(string $trigger, ?int $targetId, ?string $targetIp): int
    {
        if ($targetId === null && $targetIp === null) {
            return 0;
        }
        $where  = ['trigger_code = ?', 'created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)'];
        $params = [$trigger];
        if ($targetId !== null) {
            $where[]  = 'target_user_id = ?';
            $params[] = $targetId;
        } else {
            $where[]  = 'target_ip = ?';
            $params[] = $targetIp;
        }
        $row = Connection::getInstance()->fetchOne(
            'SELECT COUNT(*) AS c FROM moderation_shadow_log WHERE ' . implode(' AND ', $where),
            $params
        );
        return (int) ($row['c'] ?? 0);
    }
}
