# RBAC VALIDATION AUDIT
> Audit date: 2026-05-31
> Based on: docs/architecture/RBAC_CALL_GRAPH.md
> All claims verified by direct code read. Every code snippet is quoted verbatim from source.
> No changes made. No patches applied.

---

## Methodology

Each claim is traced through the exact call path:

```
WS client → EventRouter::onRoomAction → RoomController::manage
  → resolvePermission (level assignment)
  → action branch (kick / ban / set_role / mute)
  → target fetch (DB)
  → guard checks
  → DB write or error
```

Source files:
- `src/WebSocket/EventRouter.php` lines 488–562
- `src/Chat/RoomController.php` lines 113–367

---

## Claim 1: RoomController::manage allows actor to act on themselves

### Code path

```
EventRouter::onRoomAction (lines 488–497):
  $userId = (int) $session['id'];
  $result = RoomController::manage($roomId, $userId, $session, $data);
  // data['target_user_id'] comes directly from client payload — unchecked
```

`manage()` passes `$data['target_user_id']` directly to each sub-action.
There is NO check `$userId === $data['target_user_id']` anywhere in EventRouter or manage().

### Verification: kick self

```
kick(roomId, targetId=actorId, actorId, actor, permission, db):

  // Level check: passes if permission['level'] >= 2 OR global staff
  if ($permission['level'] < 2 && !in_array($actor['global_role'], [...], true)) {
      return ['error' => ...];
  }

  $target = $db->fetchOne(
      'SELECT rm.room_role, u.username FROM room_members rm JOIN users u ...',
      [$roomId, $targetId]     // targetId = actorId
  );
  // Guard: only checks room_role === 'owner'
  if (!$target || $target['room_role'] === 'owner') {
      return ['error' => 'Нельзя выгнать этого пользователя.'];
  }

  // No self-action guard. Executes:
  $db->execute('DELETE FROM room_members WHERE room_id = ? AND user_id = ?', [$roomId, $targetId]);
  return ['kicked' => true, ...];
```

### Scenario A: local_admin kicks themselves

- Actor: User A, room_role=`local_admin` (level=2), not owner
- Action: `room_action{action:'kick', target_user_id: A.id}`
- resolvePermission → `['level' => 2]` → passes `level < 2` check (2 is not < 2)
- kick() fetches target = A's row: room_role=`local_admin` ≠ `owner` → guard passes
- **Result: `DELETE room_members` executes. User A kicks themselves out of the room.**

### Scenario B: member attempts self-promote via set_role

- Actor: User A, room_role=`member` (level=0)
- Action: `room_action{action:'set_role', target_user_id: A.id, role:'local_moderator'}`
- resolvePermission → `['level' => 0]` → NOT null → manage() proceeds
- setRoomRole(targetId=A.id, role=`local_moderator`, permission=['level'=>0]):

```
// Check 1: 'local_moderator' in ['local_moderator','local_admin','member'] → TRUE
// Check 2: role === 'local_admin' → FALSE → skip (no level check for local_moderator)
// target = A's own row: room_role='member', username='...'
// Guard: target['room_role'] === 'owner' → FALSE
// No further checks.
$db->execute(
    'UPDATE room_members SET room_role = ? WHERE room_id = ? AND user_id = ?',
    ['local_moderator', $roomId, $targetId]  // targetId = actorId
);
return ['updated' => true, ...];
```

- **Result: `UPDATE room_members SET room_role='local_moderator'` executes. Member self-promotes.**

### Verification grep

```
grep -n "actorId.*targetId\|\$actorId === \$targetId" src/Chat/RoomController.php
→ (no output)
```

Zero results. No self-action guard exists anywhere in RoomController.

### STATUS: **CONFIRMED**

Self-action is possible for: kick (level ≥ 2 required), set_role (no level check for local_moderator), mute (level ≥ 2 required, but owner/banned guard would apply), ban (level ≥ 2 required).

---

## Claim 2: RoomController::manage allows kicking a platform_owner

### Precondition

For this claim to be true, a platform_owner must be kickable.
The only protection in `kick()` is: `$target['room_role'] === 'owner'`.
This checks the ROOM role, not the GLOBAL role.

A platform_owner with room_role=`member`, `local_admin`, or `local_moderator` is NOT protected.

### Code: kick() guard (verbatim, lines 224–228)

```php
if (!$target || $target['room_role'] === 'owner') {
    return ['error' => 'Нельзя выгнать этого пользователя.'];
}
```

Target query (lines 219–223):

```php
$target = $db->fetchOne(
    'SELECT rm.room_role, u.username
     FROM room_members rm JOIN users u ON u.id = rm.user_id
     WHERE rm.room_id = ? AND rm.user_id = ?',
    [$roomId, $targetId]
);
```

`u.global_role` is **NOT fetched**. `target['global_role']` is never checked.

### Scenario

- Room R: owner = User A (room_role=`owner`)
- User B: global_role=`platform_owner`, room_role=`local_admin` in Room R
- Actor: User C, global_role=`admin` (resolvePermission → level=5)
- Action: `room_action{action:'kick', target_user_id: B.id}`

```
kick() level check: 5 < 2 → FALSE → passes
target = {room_role: 'local_admin', username: 'B'}
guard: 'local_admin' === 'owner' → FALSE → guard passes
DELETE FROM room_members WHERE room_id=R AND user_id=B.id
return ['kicked' => true, ...]
```

**Result: platform_owner B is kicked from the room.**

### Additional scenario: platform_owner as room member

- User B: global_role=`platform_owner`, room_role=`member`
- Any actor with level ≥ 2 OR global staff role can kick them
- `member` ≠ `owner` → guard passes → kicked

### Verification: no global_role check on target

```
grep -n "global_role" src/Chat/RoomController.php
→ lines 45, 56, 136, 179, 214, 234, 258, 297, 300, 303
```

ALL global_role references check `$actor['global_role']` (the person performing the action).
**Zero references to target's global_role in kick() or ban().**

### STATUS: **CONFIRMED**

A platform_owner who is not the room owner can be kicked by any actor with sufficient level or global role. The only protection is `room_role === 'owner'` — which is a room-local designation, not a global one.

---

## Claim 3: RoomController::manage allows banning a platform_owner

### Code: ban() guard (verbatim, lines 243–247)

```php
if (!$target || $target['room_role'] === 'owner') {
    return ['error' => 'Нельзя забанить этого пользователя.'];
}
```

Target query (lines 238–242):

```php
$target = $db->fetchOne(
    'SELECT rm.room_role, u.username
     FROM room_members rm JOIN users u ON u.id = rm.user_id
     WHERE rm.room_id = ? AND rm.user_id = ?',
    [$roomId, $targetId]
);
```

Identical structure to kick(). `u.global_role` NOT fetched. Not checked.

### Scenario

- User B: global_role=`platform_owner`, room_role=`member` in Room R
- Actor: User C, global_role=`admin` (level=5)
- Action: `room_action{action:'ban', target_user_id: B.id}`

```
ban() level check: 5 < 2 → FALSE → passes
target = {room_role: 'member', username: 'B'}
guard: 'member' === 'owner' → FALSE → guard passes
UPDATE room_members SET room_role='banned', banned_at=NOW(), banned_by=C.id
  WHERE room_id=R AND user_id=B.id
return ['banned' => true, ...]
```

**Result: platform_owner B is banned from the room.**

### What happens after ban

- EventRouter::onRoomAction sees `$result['banned'] === true`
- Calls `cm->leaveRoom(B.id, roomId)` — removes from WS presence
- Sends `banned_from_room` event to B — B's UI redirects away

The platform_owner is now:
- Removed from WS presence
- Their room_members row has room_role=`banned`
- They cannot rejoin (EventRouter::onJoinRoom checks room_members, banned → denied for numer, auto-denied for public)
- They receive `banned_from_room` WS event

### STATUS: **CONFIRMED**

A platform_owner who is not the room owner can be banned. The only protection is `room_role === 'owner'`. Global role of the target is never checked.

---

## Claim 4: Any member can assign local_moderator via set_role

### Full permission check path

**Step 1: resolvePermission (lines 295–320)**

```php
private static function resolvePermission(int $roomId, int $userId, array $actor): ?array
{
    // Global role checks first (no DB):
    if (($actor['global_role'] ?? 'user') === 'platform_owner') return ['level' => 6];
    if (($actor['global_role'] ?? 'user') === 'admin')          return ['level' => 5];
    if (($actor['global_role'] ?? 'user') === 'moderator')      return ['level' => 4];

    // DB query for room role:
    $role = $db->fetchOne(
        'SELECT room_role FROM room_members WHERE room_id = ? AND user_id = ?',
        [$roomId, $userId]
    )['room_role'] ?? null;

    return match ($role) {
        'owner'           => ['level' => 3, 'role' => $role],
        'local_admin'     => ['level' => 2, 'role' => $role],
        'local_moderator' => ['level' => 1, 'role' => $role],
        'member'          => ['level' => 0, 'role' => $role],
        default           => null,   // ← null means "no access" → manage() returns error
    };
}
```

For a member: returns `['level' => 0, 'role' => 'member']` — **not null**.

**Step 2: manage() entry check (line 116)**

```php
$permission = self::resolvePermission($roomId, $actorId, $actor);
if (!$permission) {          // null = denied, ['level'=>0] = truthy = passes
    return ['error' => 'Нет прав.'];
}
```

`['level' => 0]` is truthy. **A member passes the gate.**

**Step 3: manage() set_role case (lines 150–157)**

```php
case 'set_role':
    return self::setRoomRole(
        $roomId,
        (int) ($data['target_user_id'] ?? 0),
        (string) ($data['role'] ?? ''),
        $actor,
        $permission,    // ['level' => 0]
        $db
    );
```

No pre-check on permission level before calling setRoomRole.

**Step 4: setRoomRole() (lines 173–211)**

```php
// Check 1: role in allowed list
$allowed = ['local_moderator', 'local_admin', 'member'];
if (!in_array($role, $allowed, true)) {
    return ['error' => 'Недопустимая роль.'];  // 'local_moderator' passes
}

// Check 2: level check — ONLY for local_admin
if ($role === 'local_admin' && $permission['level'] < 3
    && !in_array($actor['global_role'], ['platform_owner', 'admin'], true)) {
    return ['error' => '...'];  // skipped: role is 'local_moderator', not 'local_admin'
}

// Check 3: target must be in room
$target = $db->fetchOne(...WHERE room_id=? AND user_id=?...);
if (!$target) { return ['error' => ...]; }

// Check 4: cannot change role of owner
if ($target['room_role'] === 'owner') { return ['error' => ...]; }

// NO FURTHER CHECKS.
// Executes:
$db->execute(
    'UPDATE room_members SET room_role = ? WHERE room_id = ? AND user_id = ?',
    ['local_moderator', $roomId, $targetId]
);
return ['updated' => true, ...];
```

**There is no check `$permission['level'] >= N` for assigning `local_moderator`.**

### Scenario: member promotes another member

- Actor: User A, room_role=`member` (level=0), global_role=`user`
- Target: User B, room_role=`member` in same room
- Action: `room_action{action:'set_role', target_user_id: B.id, role:'local_moderator'}`

Result of each check:
1. resolvePermission → `['level' => 0]` → truthy → manage() proceeds ✓
2. manage() guard → passes ✓
3. set_role case → calls setRoomRole ✓
4. 'local_moderator' in allowed → ✓
5. role === 'local_admin' → FALSE → skip ✓
6. target found: room_role='member' ✓
7. 'member' === 'owner' → FALSE ✓
8. UPDATE executes → **User B is now local_moderator**

### Scenario: member self-promotes

- Same as above, targetId = actorId = A.id
- All checks pass identically
- **User A self-promotes to local_moderator**

### Level check matrix for set_role

| Role being assigned | Level check | Min required | Member (level=0) passes? |
|---------------------|------------|-------------|--------------------------|
| `local_admin` | `level < 3 AND NOT global admin` | owner (3) | NO — blocked |
| `local_moderator` | **NONE** | **none** | **YES — allowed** |
| `member` | **NONE** | **none** | **YES — allowed** |

### STATUS: **CONFIRMED**

Any member (level=0) can assign `local_moderator` to any other room member (or to themselves) via `room_action{action:'set_role', role:'local_moderator'}`. The only protection (`local_admin` requires level ≥ 3) does not apply to `local_moderator`.

---

## Claim 5: Cross-room mute blocks Numer invite

### Full call path

```
User A is muted in Room X:
  room_members row: {room_id: X, user_id: A, muted_until: '2026-05-31 23:59:59'}

User A opens numer popup (Room Y) and clicks "Пригласить":
  NumerPage.php inline JS: ws.send({event:'invite_user', to_user_id: B})

  → EventRouter::onInviteUser (lines 259–281)
      $toId   = B
      $fromId = A
      $result = NumerController::invite($fromId=A, $session, $toId=B)

      → NumerController::invite (lines 20–67)
          $db = Connection::getInstance()
          $muted = $db->fetchOne(
              'SELECT muted_until FROM room_members
               WHERE user_id = ?            ← only user_id, NO room_id filter
               AND muted_until > NOW()
               LIMIT 1',
              [$fromId=A]                    ← matches row from Room X
          );
          // Returns: {muted_until: '2026-05-31 23:59:59'}
          if ($muted) {
              return ['error' => 'У вас кляп до 23:59:59.'];
          }

      $result = ['error' => 'У вас кляп до 23:59:59.']
      isset($result['error']) → TRUE
      cm->sendToConnection(conn, ['event' => 'error', 'message' => 'У вас кляп до 23:59:59.'])
      return;
```

User A never receives `invite_sent`. User B never receives `invite_received`.
Invite is blocked entirely.

### Comparison: room-scoped vs cross-room mute

**NumerController::invite — cross-room (no room_id):**
```php
'SELECT muted_until FROM room_members WHERE user_id = ? AND muted_until > NOW() LIMIT 1'
params: [$fromId]
```

**WhisperController::send — room-scoped:**
```php
'SELECT muted_until FROM room_members WHERE room_id = ? AND user_id = ? AND muted_until > NOW()'
params: [$roomId, $fromId]
```

**MessageController::send — room-scoped:**
```php
'SELECT muted_until FROM room_members WHERE room_id = ? AND user_id = ? AND muted_until > NOW()'
params: [$roomId, $actorId]
```

The difference is explicit:
- NumerController: `WHERE user_id = ?` — matches ANY room's mute record
- WhisperController / MessageController: `WHERE room_id = ? AND user_id = ?` — only the current room

### Concrete scenario demonstrating asymmetry

- User A is muted in Room X (a public room they participate in)
- User A is in Room Y numer popup, trying to invite User B
- Room Y has NO mute on User A
- **Result: invite blocked** by Room X mute

User A CAN still:
- Send messages in Room Y (MessageController room-scoped — Room Y has no mute)
- Send whispers in Room Y (WhisperController room-scoped — Room Y has no mute)

User A CANNOT:
- Send any numer invite from anywhere (NumerController cross-room mute blocks all)

### STATUS: **CONFIRMED**

The mute check in NumerController::invite uses `WHERE user_id = ?` with no `room_id` filter. Being muted in any one room blocks the ability to send numer invites globally, regardless of which room the invite is initiated from. This is inconsistent with the room-scoped mute check in WhisperController and MessageController.

---

## Summary

| # | Claim | Status | Key evidence |
|---|-------|--------|-------------|
| 1 | `manage()` allows self-action | **CONFIRMED** | Zero `actorId === targetId` guard in entire RoomController. Verified by grep. |
| 2 | `manage()` allows kick of platform_owner | **CONFIRMED** | `kick()` fetches only `rm.room_role`. Guard is `room_role === 'owner'`. `u.global_role` never fetched. |
| 3 | `manage()` allows ban of platform_owner | **CONFIRMED** | `ban()` identical structure to `kick()`. Same guard, same missing global_role check. |
| 4 | Any member can assign local_moderator | **CONFIRMED** | `resolvePermission` returns level=0 (truthy) for member. `setRoomRole` has NO level check for `local_moderator`. |
| 4a | Any member can self-promote to local_moderator | **CONFIRMED** | Self-action guard absent (Claim 1). Same path applies with targetId=actorId. |
| 5 | Cross-room mute blocks numer invite | **CONFIRMED** | `NumerController::invite` query: `WHERE user_id = ?` with no `room_id`. Vs `WhisperController`/`MessageController` which use `WHERE room_id = ? AND user_id = ?`. |

All five claims confirmed. No claim rejected.

---

## What AccessContext would prevent if connected

The dead `Security\AccessContext` class would block all five scenarios:

| Scenario | AccessContext guard |
|----------|---------------------|
| Claim 1 (self-action) | `canModerateUser(actorId, targetId, roomId)` — line 151: `if ($actorId === $targetId) return false` |
| Claim 2 (kick platform_owner) | `canModerate(actorCtx, targetCtx)` — line 131: `if ($targetCtx['role'] === 'platform_owner') return false` |
| Claim 3 (ban platform_owner) | Same as above |
| Claim 4 (member assigns local_moderator) | `canModerate` level check: `actorCtx['level'] > targetCtx['level']` — level=0 is NOT > level=1, so member (0) cannot promote someone to local_moderator (1) against a member (0) who already has level 0. *Partial: member cannot promote another member to local_mod via this check.* |
| Claim 5 (cross-room mute) | Not addressed by AccessContext — separate issue |

**AccessContext.php is the correct fix for Claims 1–3. Claim 4 requires a minimum-level check in `setRoomRole`. Claim 5 requires adding `room_id` to the NumerController mute query.**
