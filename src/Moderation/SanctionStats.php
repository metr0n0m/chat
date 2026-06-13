<?php
declare(strict_types=1);

namespace Chat\Moderation;

use Chat\DB\Connection;
use Chat\Support\Timestamp;

/**
 * Читающий слой движка санкций — этап S5a (мониторинг).
 * Только SELECT-запросы к moderation_events / active_restrictions /
 * moderation_shadow_log / sanction_rules. Источник данных для админ-панели.
 *
 * Контроль доступа выполняет вызывающий код (SanctionPanel):
 * глобальный журнал/статистика — platform_owner/admin, журнал комнаты —
 * её owner. Этот класс ограничивает выборку переданным room_id, но прав
 * не проверяет.
 */
final class SanctionStats
{
    private const PAGE_SIZE = 50;

    /**
     * Лента событий журнала с фильтрами и пагинацией.
     *
     * @param array{
     *   page?: int, act?: string, trigger_code?: string, scope?: string,
     *   origin?: string, actor_id?: int, room_id?: int, from?: string, to?: string
     * } $filters
     * @param int|null $restrictRoomId если задан — выборка строго по этой комнате (для owner)
     * @return array{events: list<array>, page: int, has_more: bool}
     */
    public static function events(array $filters, ?int $restrictRoomId = null): array
    {
        $where  = ['1=1'];
        $params = [];

        if ($restrictRoomId !== null) {
            $where[]  = 'me.room_id = ?';
            $params[] = $restrictRoomId;
        } elseif (!empty($filters['room_id'])) {
            $where[]  = 'me.room_id = ?';
            $params[] = (int) $filters['room_id'];
        }

        foreach (
            [
                'act'          => 'me.act',
                'trigger_code' => 'me.trigger_code',
                'scope'        => 'me.scope',
                'origin'       => 'me.origin',
            ] as $key => $col
        ) {
            if (!empty($filters[$key])) {
                $where[]  = $col . ' = ?';
                $params[] = (string) $filters[$key];
            }
        }
        if (!empty($filters['actor_id'])) {
            $where[]  = 'me.actor_id = ?';
            $params[] = (int) $filters['actor_id'];
        }
        if (!empty($filters['from'])) {
            $where[]  = 'me.created_at >= ?';
            $params[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]  = 'me.created_at <= ?';
            $params[] = (string) $filters['to'];
        }

        $page   = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * self::PAGE_SIZE;
        $limit  = self::PAGE_SIZE + 1; // +1 для определения has_more

        $rows = Connection::getInstance()->fetchAll(
            'SELECT me.id, me.created_at, me.act, me.origin, me.parent_event_id,
                    me.actor_id, me.actor_role, me.actor_ip,
                    me.target_user_id, me.target_ip,
                    me.scope, me.room_id, me.duration_type, me.expires_at,
                    me.previous_room_role, me.reason, me.trigger_code,
                    a.username AS actor_username,
                    t.username AS target_username,
                    r.name     AS room_name
             FROM moderation_events me
             LEFT JOIN users a ON a.id = me.actor_id
             LEFT JOIN users t ON t.id = me.target_user_id
             LEFT JOIN rooms r ON r.id = me.room_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY me.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows    = array_slice($rows, 0, self::PAGE_SIZE);
        $rows    = Timestamp::normalizeRows($rows, ['created_at', 'expires_at']);

        return ['events' => $rows, 'page' => $page, 'has_more' => $hasMore];
    }

    /**
     * Теневой журнал — что система выдала БЫ (калибровка перед боевым режимом).
     *
     * @param array{page?: int, trigger_code?: string, would_sanction?: string} $filters
     * @return array{shadow: list<array>, page: int, has_more: bool}
     */
    public static function shadow(array $filters): array
    {
        $where  = ['1=1'];
        $params = [];
        foreach (['trigger_code' => 'sl.trigger_code', 'would_sanction' => 'sl.would_sanction'] as $key => $col) {
            if (!empty($filters[$key])) {
                $where[]  = $col . ' = ?';
                $params[] = (string) $filters[$key];
            }
        }

        $page   = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * self::PAGE_SIZE;
        $limit  = self::PAGE_SIZE + 1;

        $rows = Connection::getInstance()->fetchAll(
            'SELECT sl.id, sl.created_at, sl.trigger_code, sl.target_user_id, sl.target_ip,
                    sl.room_id, sl.would_sanction, sl.would_duration, sl.details,
                    t.username AS target_username, r.name AS room_name
             FROM moderation_shadow_log sl
             LEFT JOIN users t ON t.id = sl.target_user_id
             LEFT JOIN rooms r ON r.id = sl.room_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY sl.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows    = array_slice($rows, 0, self::PAGE_SIZE);
        $rows    = Timestamp::normalizeRows($rows, ['created_at']);

        return ['shadow' => $rows, 'page' => $page, 'has_more' => $hasMore];
    }

    /**
     * Агрегаты для дашборда санкций.
     *
     * @return array{
     *   by_trigger: list<array>, top_actors: list<array>, by_day: list<array>,
     *   active_restrictions: array, shadow_30d: int, autonomy: array
     * }
     */
    public static function stats(int $days = 30): array
    {
        $db   = Connection::getInstance();
        $days = max(1, min(365, $days));

        // По типу триггера (атаки) за период
        $byTrigger = $db->fetchAll(
            "SELECT COALESCE(trigger_code, '(ручное)') AS trigger_code, COUNT(*) AS count
             FROM moderation_events
             WHERE act IN ('ban_room', 'ban_global', 'mute', 'kick')
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY COALESCE(trigger_code, '(ручное)')
             ORDER BY count DESC",
            [$days]
        );

        // Кто из модераторов чаще действует (исключая систему)
        $topActors = $db->fetchAll(
            "SELECT me.actor_id, u.username AS actor_username, me.actor_role, COUNT(*) AS count
             FROM moderation_events me
             LEFT JOIN users u ON u.id = me.actor_id
             WHERE me.origin <> 'system' AND me.actor_id IS NOT NULL
               AND me.act IN ('ban_room', 'ban_global', 'mute', 'kick')
               AND me.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY me.actor_id, u.username, me.actor_role
             ORDER BY count DESC
             LIMIT 20",
            [$days]
        );

        // Динамика по датам
        $byDay = $db->fetchAll(
            "SELECT DATE(created_at) AS day, COUNT(*) AS count
             FROM moderation_events
             WHERE act IN ('ban_room', 'ban_global', 'mute', 'kick')
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY day",
            [$days]
        );

        // Текущие активные ограничения по типам
        $activeRows = $db->fetchAll(
            'SELECT type, COUNT(*) AS count FROM active_restrictions
             WHERE expires_at IS NULL OR expires_at > NOW()
             GROUP BY type'
        );
        $active = ['mute' => 0, 'ban_room' => 0, 'ban_global' => 0];
        foreach ($activeRows as $r) {
            $active[(string) $r['type']] = (int) $r['count'];
        }

        $shadow30d = (int) $db->fetchOne(
            'SELECT COUNT(*) AS c FROM moderation_shadow_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND would_sanction <> ?',
            [$days, 'none']
        )['c'];

        return [
            'by_trigger'          => $byTrigger,
            'top_actors'          => $topActors,
            'by_day'              => $byDay,
            'active_restrictions' => $active,
            'shadow_30d'          => $shadow30d,
            'autonomy'            => self::autonomy(),
        ];
    }

    /** Текущий режим движка: shadow/live + состояние автонома + ключевые пороги. */
    public static function autonomy(): array
    {
        $rules = ViolationReporter::rules();
        return [
            'mode'            => (string) ($rules['mode'] ?? 'shadow'),
            'autonomy_state'  => (string) ($rules['autonomy_state'] ?? 'active'),
            'circuit_breaker' => $rules['circuit_breaker'] ?? ['max_system_sanctions_per_min' => 10],
            'escalation'      => $rules['escalation'] ?? [],
        ];
    }

    /**
     * Интел «волк в овечьей шкуре» (S5c): IP, за которым есть и забанённый,
     * и другой (активный) аккаунт. Только сигнал стаффу, не авто-действие.
     *
     * @return list<array{ip: string, banned: list<string>, others: list<string>}>
     */
    public static function ipIntel(int $days = 30): array
    {
        $db   = Connection::getInstance();
        $days = max(1, min(365, $days));

        // IP, по которым в журнале есть бан-события за период
        $ips = $db->fetchAll(
            "SELECT DISTINCT target_ip
             FROM moderation_events
             WHERE target_ip IS NOT NULL AND target_ip <> ''
               AND act IN ('ban_room', 'ban_global')
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );

        $alerts = [];
        foreach ($ips as $ipRow) {
            $ip = (string) $ipRow['target_ip'];

            // Забаненные с этого IP (из журнала)
            $banned = $db->fetchAll(
                "SELECT DISTINCT t.username
                 FROM moderation_events me
                 JOIN users t ON t.id = me.target_user_id
                 WHERE me.target_ip = ? AND me.act IN ('ban_room', 'ban_global')",
                [$ip]
            );

            // Другие аккаунты, действовавшие с этого IP как акторы (актив)
            $others = $db->fetchAll(
                "SELECT DISTINCT a.username
                 FROM moderation_events me
                 JOIN users a ON a.id = me.actor_id
                 WHERE me.actor_ip = ? AND a.is_banned = 0",
                [$ip]
            );

            if ($banned !== [] && $others !== []) {
                $alerts[] = [
                    'ip'     => $ip,
                    'banned' => array_column($banned, 'username'),
                    'others' => array_column($others, 'username'),
                ];
            }
        }

        return $alerts;
    }
}
