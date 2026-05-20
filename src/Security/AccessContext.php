<?php
declare(strict_types=1);

namespace Chat\Security;

use Chat\DB\Connection;

/**
 * Per-request moderation context resolver.
 *
 * getModerationContext() returns a snapshot of a user's effective moderation
 * level for a given room (or globally if room_id is null).
 *
 * Results are cached for the lifetime of this object.
 * Call flush() at the start of each WS event to avoid stale data.
 *
 * Does NOT accept $actor array — reads directly from DB by userId.
 * This prevents decisions based on stale session snapshots.
 */
class AccessContext
{
    /** @var array<string, array> */
    private array $cache = [];

    /**
     * Discard all cached contexts.
     * Must be called at the start of each WS route() invocation.
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * Resolve moderation context for a user.
     *
     * Returns:
     *   level:   int    numeric level (see scale below)
     *   source:  string 'global' | 'room'
     *   role:    string identifier of the role
     *   room_id: int|null  null for global source
     *
     * Numeric scale:
     *   7  root_owner      (reserved, not yet in DB)
     *   6  platform_owner
     *   5  admin
     *   4  moderator
     *   3  owner           (room local)
     *   2  local_admin     (room local)
     *   1  local_moderator (room local)
     *   0  member
     *  -1  not in room / no context
     */
    public function getModerationContext(int $userId, ?int $roomId): array
    {
        $key = $userId . ':' . ($roomId ?? 'null');

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $db = Connection::getInstance();

        $globalRole = (string) ($db->fetchOne(
            'SELECT global_role FROM users WHERE id = ?',
            [$userId]
        )['global_role'] ?? 'user');

        $result = match ($globalRole) {
            'platform_owner' => ['level' => 6, 'source' => 'global', 'role' => 'platform_owner', 'room_id' => null],
            'admin'          => ['level' => 5, 'source' => 'global', 'role' => 'admin',          'room_id' => null],
            'moderator'      => ['level' => 4, 'source' => 'global', 'role' => 'moderator',      'room_id' => null],
            default          => null,
        };

        if ($result !== null) {
            return $this->cache[$key] = $result;
        }

        // Not a global role — resolve room role
        if ($roomId === null) {
            return $this->cache[$key] = ['level' => -1, 'source' => 'room', 'role' => 'none', 'room_id' => null];
        }

        $roomRole = (string) ($db->fetchOne(
            'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        )['room_role'] ?? '');

        $level = match ($roomRole) {
            'owner'           => 3,
            'local_admin'     => 2,
            'local_moderator' => 1,
            'member'          => 0,
            default           => -1,
        };

        return $this->cache[$key] = [
            'level'   => $level,
            'source'  => 'room',
            'role'    => $roomRole !== '' ? $roomRole : 'none',
            'room_id' => $roomId,
        ];
    }

    /**
     * Determine whether actor can apply a moderation action to target.
     *
     * Implements MODERATION_POLICY.md §3 matrices and §4 invariants:
     *
     * I-1: actor and target must not be the same user.
     * I-3: platform_owner cannot be moderated by anyone in normal hierarchy.
     * Scope: local actors (source=room) only act within their room.
     * Level: actor.level must be strictly greater than target.level.
     */
    public function canModerate(array $actorCtx, array $targetCtx): bool
    {
        // I-1: self-action forbidden
        // Note: caller must pass userId separately if needed; contexts don't carry userId.
        // Self-check by level equality is NOT sufficient — use canModerateUser() below.

        // I-3: platform_owner is untouchable (root_owner reserved, level 7)
        if ($targetCtx['role'] === 'platform_owner') {
            return false;
        }

        // Scope: local actor cannot act outside their room
        if ($actorCtx['source'] === 'room') {
            if ($actorCtx['room_id'] !== $targetCtx['room_id']) {
                return false;
            }
        }

        // Level: strictly greater
        return $actorCtx['level'] > $targetCtx['level'];
    }

    /**
     * Full moderation check including self-action guard.
     * Preferred over canModerate() when both userIds are available.
     */
    public function canModerateUser(int $actorId, int $targetId, ?int $roomId): bool
    {
        // I-1: self-action forbidden
        if ($actorId === $targetId) {
            return false;
        }

        $actorCtx  = $this->getModerationContext($actorId,  $roomId);
        $targetCtx = $this->getModerationContext($targetId, $roomId);

        return $this->canModerate($actorCtx, $targetCtx);
    }

    /**
     * Maximum allowed duration_type for a given actor context.
     * Returns null if unrestricted (permanent allowed).
     *
     * Based on MODERATION_POLICY.md §5.
     */
    public function maxDurationType(array $actorCtx, string $sanctionType): ?string
    {
        $role = $actorCtx['role'];

        // local_mod: max 24h for both ban and mute
        if ($role === 'local_moderator' || $role === 'moderator') {
            return '24h';
        }

        // local_admin: max 7d for mute, unlimited for ban_room
        if ($role === 'local_admin') {
            return $sanctionType === 'mute' ? '7d' : null;
        }

        // global_admin, platform_owner, owners: unlimited
        return null;
    }
}
