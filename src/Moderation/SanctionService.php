<?php
declare(strict_types=1);

namespace Chat\Moderation;

use Chat\DB\Connection;
use Chat\Security\AccessContext;
use Chat\Security\Session;
use Chat\Support\Timestamp;
use Chat\WebSocket\Outbox;

/**
 * Единая точка выдачи (apply) и снятия (lift) санкций — этап S1 движка санкций.
 * Дизайн: docs/architecture/SANCTIONS_ENGINE.md v0.1.
 *
 * Через этот сервис проходят ВСЕ изменения санкционного состояния:
 *  - ручные действия модераторов через WS (RoomController: mute/unmute/ban/kick),
 *  - действия админ-панели по HTTP (UserManager: глобальный бан/разбан, room unban/unmute),
 *  - в будущем (S3/S4) — автономные детекторы с origin='system'.
 *
 * Каждый вызов в ОДНОЙ транзакции:
 *  1) меняет рабочее состояние (muted_until / room_role / is_banned — как раньше),
 *  2) пишет вечную запись в журнал moderation_events (кто, кого, где, на сколько, почему),
 *  3) обновляет горячую таблицу active_restrictions (что действует прямо сейчас).
 *
 * Этап перехода (expand → migrate → contract): рабочие проверки пока читают
 * старые поля; перевод чтения на active_restrictions — этап S2. Поэтому
 * сервис обязан писать в обе модели согласованно.
 *
 * Проверка прав остаётся в вызывающем коде (политика протестирована в
 * tests/Integration/RoomModerationRbacTest.php). Сервис дополнительно
 * страхует два жёстких инварианта политики (MODERATION_POLICY.md):
 *  I-1 — нельзя применить санкцию к самому себе;
 *  I-3 — platform_owner неприкасаем (для ЛЮБОГО типа санкции, включая mute).
 * Контексты ролей резолвятся через AccessContext (по базе, не по снапшоту сессии).
 */
final class SanctionService
{
    /** Санкция → act в журнале. */
    private const APPLY_ACTS = [
        'mute'       => 'mute',
        'ban_room'   => 'ban_room',
        'ban_global' => 'ban_global',
        'kick'       => 'kick',
    ];

    /** Снятие → [act в журнале, тип ограничения в active_restrictions]. */
    private const LIFT_ACTS = [
        'unmute'      => ['unmute', 'mute'],
        'unban_room'  => ['unban_room', 'ban_room'],
        'unban_global' => ['unban_global', 'ban_global'],
    ];

    /**
     * Свежий резолвер на каждую операцию: кэш AccessContext живёт в пределах
     * одного apply/lift. Долгоживущий WS-процесс не должен накапливать
     * устаревшие роли между событиями.
     */
    private static function access(): AccessContext
    {
        return new AccessContext();
    }

    /**
     * Применяет санкцию.
     *
     * @param array{
     *   type: string,                 // mute | ban_room | ban_global | kick
     *   target_user_id: int,
     *   actor_id?: int|null,          // null для system
     *   room_id?: int|null,           // обязателен для room-санкций
     *   minutes?: int|null,           // срок в минутах (mute)
     *   hours?: int|null,             // срок в часах (ban_global)
     *   reason?: string|null,
     *   origin?: string,              // realtime | system
     *   trigger_code?: string|null,
     *   actor_ip?: string|null,
     *   target_ip?: string|null
     * } $intent
     * @return array{event_id:int, expires_at:?string}|array{error:string}
     */
    public static function apply(array $intent): array
    {
        $type = (string) $intent['type'];
        if (!isset(self::APPLY_ACTS[$type])) {
            return ['error' => 'Неизвестный тип санкции.'];
        }

        $targetId = (int) $intent['target_user_id'];
        $actorId  = isset($intent['actor_id']) ? (int) $intent['actor_id'] : null;
        $roomId   = isset($intent['room_id']) ? (int) $intent['room_id'] : null;
        $isGlobal = $type === 'ban_global';

        if (!$isGlobal && $roomId === null) {
            return ['error' => 'Не указана комната.'];
        }

        // I-1: санкция самому себе невозможна ни по какому пути.
        if ($actorId !== null && $actorId === $targetId) {
            return ['error' => 'Нельзя применить это действие к самому себе.'];
        }

        // I-3: platform_owner неприкасаем (DB-first проверка через AccessContext,
        // не доверяем снапшоту сессии вызывающего кода).
        $targetCtx = self::access()->getModerationContext($targetId, $roomId);
        if ($targetCtx['role'] === 'platform_owner' || $targetCtx['role'] === 'root_owner') {
            return ['error' => 'Этого пользователя нельзя ограничить.'];
        }

        // Иммунитет стаффа от автонома (S4, дизайн §9.1): система не санкционирует
        // глобальный стафф — admin/moderator может тронуть только человек.
        if (($intent['origin'] ?? 'realtime') === 'system'
            && in_array($targetCtx['role'], ['admin', 'moderator'], true)) {
            return ['error' => 'Автоном не применяет санкции к персоналу.'];
        }

        $actorRole = self::resolveActorRole($actorId, $roomId, $intent);
        $expiresAt = self::computeExpiresAt($intent);
        $reason    = self::normalizeReason($intent['reason'] ?? null);

        $db = Connection::getInstance();
        $db->beginTransaction();
        try {
            $previousRoomRole = null;

            switch ($type) {
                case 'mute':
                    $db->execute(
                        'UPDATE room_members SET muted_until = ?, mute_reason = ? WHERE room_id = ? AND user_id = ?',
                        [$expiresAt, $reason, $roomId, $targetId]
                    );
                    break;

                case 'ban_room':
                    $previousRoomRole = self::roomRole($db, (int) $roomId, $targetId);
                    $db->execute(
                        "UPDATE room_members SET room_role = 'banned', banned_at = NOW(), banned_by = ?, ban_reason = ? WHERE room_id = ? AND user_id = ?",
                        [$actorId, $reason, $roomId, $targetId]
                    );
                    break;

                case 'ban_global':
                    $db->execute(
                        'UPDATE users SET is_banned = 1, banned_at = NOW(), banned_by = ?, ban_reason = ?, banned_until = ? WHERE id = ?',
                        [$actorId, $reason, $expiresAt, $targetId]
                    );
                    break;

                case 'kick':
                    $previousRoomRole = self::roomRole($db, (int) $roomId, $targetId);
                    $db->execute(
                        'DELETE FROM room_members WHERE room_id = ? AND user_id = ?',
                        [$roomId, $targetId]
                    );
                    break;
            }

            $eventId = self::insertEvent($db, [
                'act'                => self::APPLY_ACTS[$type],
                'origin'             => (string) ($intent['origin'] ?? 'realtime'),
                'parent_event_id'    => null,
                'actor_id'           => $actorId,
                'actor_role'         => $actorRole,
                'actor_ip'           => $intent['actor_ip'] ?? self::requestIp(),
                'target_user_id'     => $targetId,
                'target_ip'          => $intent['target_ip'] ?? null,
                'scope'              => $isGlobal ? 'global' : 'room',
                'room_id'            => $isGlobal ? null : $roomId,
                'duration_type'      => $type === 'kick' ? null : self::durationType($expiresAt),
                'expires_at'         => $type === 'kick' ? null : $expiresAt,
                'previous_room_role' => $previousRoomRole,
                'reason'             => $reason,
                'trigger_code'       => $intent['trigger_code'] ?? null,
            ]);

            // kick — разовое действие, не «активное ограничение»
            if ($type !== 'kick') {
                $db->execute(
                    'INSERT INTO active_restrictions (type, target_user_id, room_id, expires_at, event_id)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at), event_id = VALUES(event_id)',
                    [$type, $targetId, $isGlobal ? null : $roomId, $expiresAt, $eventId]
                );
            }

            // Эффект I-9: глобальный бан рвёт сессии всегда (любой канал).
            if ($type === 'ban_global') {
                Session::destroyAllForUser($targetId);
            }

            // Доставка событий клиентам через мост S2:
            //  - канал http (PHP-FPM): EventRouter недоступен, шлём через outbox;
            //  - origin system: автоном всегда шлёт через outbox — у него нет
            //    WS-контекста, даже когда детектор сработал внутри WS-процесса.
            // В одной транзакции с санкцией — событие не уедет без записи.
            if (self::channel($intent) === 'http' || ($intent['origin'] ?? 'realtime') === 'system') {
                self::emitApplied($type, $targetId, $roomId, $expiresAt, $reason);
            }

            $db->commit();
            return ['event_id' => $eventId, 'expires_at' => $expiresAt];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Снимает санкцию.
     *
     * @param array{
     *   type: string,                 // unmute | unban_room | unban_global
     *   target_user_id: int,
     *   actor_id?: int|null,
     *   room_id?: int|null,
     *   reason?: string|null,
     *   origin?: string,
     *   actor_ip?: string|null
     * } $intent
     * @return array{event_id:int}|array{error:string}
     */
    public static function lift(array $intent): array
    {
        $type = (string) $intent['type'];
        if (!isset(self::LIFT_ACTS[$type])) {
            return ['error' => 'Неизвестный тип снятия.'];
        }
        [$act, $restrictionType] = self::LIFT_ACTS[$type];

        $targetId = (int) $intent['target_user_id'];
        $actorId  = isset($intent['actor_id']) ? (int) $intent['actor_id'] : null;
        $roomId   = isset($intent['room_id']) ? (int) $intent['room_id'] : null;
        $isGlobal = $type === 'unban_global';

        if (!$isGlobal && $roomId === null) {
            return ['error' => 'Не указана комната.'];
        }

        $actorRole = self::resolveActorRole($actorId, $roomId, $intent);

        $db = Connection::getInstance();

        // Ссылка на исходное событие выдачи — из горячей таблицы
        $restriction = $db->fetchOne(
            'SELECT id, event_id FROM active_restrictions
             WHERE type = ? AND target_user_id = ? AND scope_key = ?',
            [$restrictionType, $targetId, $isGlobal ? 'global' : 'room:' . $roomId]
        );

        // §4 дизайна: permanent-санкцию системы снимает ТОЛЬКО platform_owner.
        if ($restriction !== null) {
            $origin = $db->fetchOne(
                'SELECT actor_role, expires_at FROM moderation_events WHERE id = ?',
                [(int) $restriction['event_id']]
            );
            if ($origin !== null
                && $origin['actor_role'] === 'system'
                && $origin['expires_at'] === null
                && $actorRole !== 'platform_owner'
            ) {
                return ['error' => 'Бессрочную санкцию системы может снять только владелец платформы.'];
            }
        }

        $db->beginTransaction();
        try {

            switch ($type) {
                case 'unmute':
                    $db->execute(
                        'UPDATE room_members SET muted_until = NULL, mute_reason = NULL WHERE room_id = ? AND user_id = ?',
                        [$roomId, $targetId]
                    );
                    break;

                case 'unban_room':
                    $db->execute(
                        "DELETE FROM room_members WHERE room_id = ? AND user_id = ? AND room_role = 'banned'",
                        [$roomId, $targetId]
                    );
                    break;

                case 'unban_global':
                    $db->execute(
                        'UPDATE users SET is_banned = 0, banned_at = NULL, banned_by = NULL, banned_until = NULL, ban_reason = NULL WHERE id = ?',
                        [$targetId]
                    );
                    break;
            }

            $eventId = self::insertEvent($db, [
                'act'                => $act,
                'origin'             => (string) ($intent['origin'] ?? 'realtime'),
                'parent_event_id'    => $restriction !== null ? (int) $restriction['event_id'] : null,
                'actor_id'           => $actorId,
                'actor_role'         => $actorRole,
                'actor_ip'           => $intent['actor_ip'] ?? self::requestIp(),
                'target_user_id'     => $targetId,
                'target_ip'          => null,
                'scope'              => $isGlobal ? 'global' : 'room',
                'room_id'            => $isGlobal ? null : $roomId,
                'duration_type'      => null,
                'expires_at'         => null,
                'previous_room_role' => null,
                'reason'             => self::normalizeReason($intent['reason'] ?? null),
                'trigger_code'       => null,
            ]);

            if ($restriction !== null) {
                $db->execute('DELETE FROM active_restrictions WHERE id = ?', [(int) $restriction['id']]);
            }

            if (self::channel($intent) === 'http') {
                self::emitLifted($type, $targetId, $roomId);
            }

            $db->commit();
            return ['event_id' => $eventId];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Канал доставки WS-событий: 'ws' — вызывающий код сам в WS-процессе
     * (EventRouter шлёт события напрямую), 'http' — PHP-FPM, события идут
     * через мост ws_outbox. По умолчанию определяется по SAPI.
     */
    private static function channel(array $intent): string
    {
        return (string) ($intent['channel'] ?? (PHP_SAPI === 'cli' ? 'ws' : 'http'));
    }

    /** WS-события для санкций, применённых вне WS-процесса (контракт = EventRouter). */
    private static function emitApplied(string $type, int $targetId, ?int $roomId, ?string $expiresAt, ?string $reason): void
    {
        switch ($type) {
            case 'ban_global':
                Outbox::toUser($targetId, 'force_logout', ['reason' => 'banned_global']);
                break;

            case 'mute':
                $iso = Timestamp::isoUtc($expiresAt);
                Outbox::toUser($targetId, 'muted_in_room', [
                    'room_id' => $roomId, 'target_user_id' => $targetId,
                    'muted_until' => $iso, 'reason' => $reason,
                ]);
                Outbox::toRoomStaff($roomId, 'room_updated', [
                    'room_id' => $roomId,
                    'data' => ['muted' => true, 'unmuted' => false, 'target_user_id' => $targetId, 'muted_until' => $iso],
                ], $targetId);
                break;

            case 'ban_room':
                // контракт EventRouter: цель — banned_from_room, остальные — user_left;
                // диспетчер по banned_from_room дополнительно чистит presence комнаты
                Outbox::toUser($targetId, 'banned_from_room', [
                    'room_id' => $roomId, 'target_user_id' => $targetId,
                ]);
                Outbox::toRoom($roomId, 'user_left', [
                    'room_id' => $roomId, 'user_id' => $targetId,
                ], $targetId);
                break;
        }
    }

    /** WS-события для снятий, выполненных вне WS-процесса. */
    private static function emitLifted(string $type, int $targetId, ?int $roomId): void
    {
        if ($type === 'unmute') {
            Outbox::toUser($targetId, 'unmuted_in_room', [
                'room_id' => $roomId, 'target_user_id' => $targetId,
            ]);
            Outbox::toRoomStaff($roomId, 'room_updated', [
                'room_id' => $roomId,
                'data' => ['muted' => false, 'unmuted' => true, 'target_user_id' => $targetId, 'muted_until' => null],
            ], $targetId);
        }
        // unban_room / unban_global: клиентского контракта на уведомление нет.
    }

    /**
     * Обслуживание: переводит истёкшие записи active_restrictions в события
     * restriction_expired и удаляет их из горячей таблицы.
     * Старые поля (muted_until / banned_until) истекают лениво, как и раньше —
     * их этот метод не трогает. Вызывается периодически из ws-server.php.
     */
    public static function expireLapsed(): int
    {
        $db = Connection::getInstance();
        $lapsed = $db->fetchAll(
            'SELECT id, type, target_user_id, room_id, event_id FROM active_restrictions
             WHERE expires_at IS NOT NULL AND expires_at <= NOW()'
        );
        if ($lapsed === []) {
            return 0;
        }

        $db->beginTransaction();
        try {
            foreach ($lapsed as $row) {
                self::insertEvent($db, [
                    'act'                => 'restriction_expired',
                    'origin'             => 'system',
                    'parent_event_id'    => (int) $row['event_id'],
                    'actor_id'           => null,
                    'actor_role'         => 'system',
                    'actor_ip'           => null,
                    'target_user_id'     => (int) $row['target_user_id'],
                    'target_ip'          => null,
                    'scope'              => $row['room_id'] === null ? 'global' : 'room',
                    'room_id'            => $row['room_id'] !== null ? (int) $row['room_id'] : null,
                    'duration_type'      => null,
                    'expires_at'         => null,
                    'previous_room_role' => null,
                    'reason'             => null,
                    'trigger_code'       => null,
                ]);
                $db->execute('DELETE FROM active_restrictions WHERE id = ?', [(int) $row['id']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return count($lapsed);
    }

    /**
     * Есть ли активное ограничение данного типа (чтение новой модели).
     * На S1 — для проверок и статистики; горячие пути переключаются на S2.
     */
    public static function isRestricted(string $type, int $targetUserId, ?int $roomId = null): bool
    {
        $row = Connection::getInstance()->fetchOne(
            'SELECT 1 AS x FROM active_restrictions
             WHERE type = ? AND target_user_id = ? AND scope_key = ?
               AND (expires_at IS NULL OR expires_at > NOW())',
            [$type, $targetUserId, $roomId === null ? 'global' : 'room:' . $roomId]
        );
        return $row !== null;
    }

    // ── внутреннее ──────────────────────────────────────────────────────────

    private static function insertEvent(Connection $db, array $e): int
    {
        $db->execute(
            'INSERT INTO moderation_events
                (act, origin, parent_event_id, actor_id, actor_role, actor_ip,
                 target_user_id, target_ip, scope, room_id,
                 duration_type, expires_at, previous_room_role, reason, trigger_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $e['act'], $e['origin'], $e['parent_event_id'], $e['actor_id'], $e['actor_role'], $e['actor_ip'],
                $e['target_user_id'], $e['target_ip'], $e['scope'], $e['room_id'],
                $e['duration_type'], $e['expires_at'], $e['previous_room_role'], $e['reason'], $e['trigger_code'],
            ]
        );
        return (int) $db->lastInsertId();
    }

    /** Роль актора для журнала: DB-first через AccessContext; 'system' без актора. */
    private static function resolveActorRole(?int $actorId, ?int $roomId, array $intent): string
    {
        if (($intent['origin'] ?? 'realtime') === 'system' || $actorId === null) {
            return 'system';
        }
        $ctx = self::access()->getModerationContext($actorId, $roomId);
        return (string) $ctx['role'];
    }

    /** Срок из intent (minutes/hours) → DATETIME окончания или NULL (бессрочно). */
    private static function computeExpiresAt(array $intent): ?string
    {
        $minutes = isset($intent['minutes']) ? (int) $intent['minutes'] : 0;
        $hours   = isset($intent['hours']) ? (int) $intent['hours'] : 0;
        $total   = $minutes + $hours * 60;
        return $total > 0 ? date('Y-m-d H:i:s', time() + $total * 60) : null;
    }

    /** Классификация срока по лестнице 1h/3h/24h/7d/30d/permanent. */
    private static function durationType(?string $expiresAt): string
    {
        if ($expiresAt === null) {
            return 'permanent';
        }
        $minutes = (int) ceil((strtotime($expiresAt) - time()) / 60);
        return match (true) {
            $minutes <= 60     => '1h',
            $minutes <= 180    => '3h',
            $minutes <= 1440   => '24h',
            $minutes <= 10080  => '7d',
            $minutes <= 43200  => '30d',
            default            => 'permanent',
        };
    }

    private static function roomRole(Connection $db, int $roomId, int $userId): ?string
    {
        $row = $db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        );
        $role = $row['room_role'] ?? null;
        // В журнале допустимы только роли из ENUM (без 'banned')
        return in_array($role, ['owner', 'local_admin', 'local_moderator', 'member'], true) ? $role : null;
    }

    private static function normalizeReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);
        return $reason === '' ? null : mb_substr($reason, 0, 500);
    }

    private static function requestIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
