# Ownership Model
> Audit: 2026-05-25 | All facts verified against source code.
> [UNVERIFIED] = not confirmed directly in code.

---

## Public Rooms

Source of truth: room_members.room_role = 'owner' + rooms.owner_id (must match)
No DB trigger enforces the sync. Both must be updated together.

ACL numeric levels (AdminAccess::resolveLevel and RoomController::resolvePermission):
    platform_owner = 6
    admin          = 5
    moderator      = 4
    owner (room)   = 3
    local_admin    = 2
    local_moderator= 1
    member         = 0
    banned         = -1 (blocked before permission check)

Create room:
    POST /api/rooms -> RoomController::create
    -> INSERT rooms (owner_id=userId)
    -> INSERT room_members (room_role='owner')

Delete room:
    WS room_action:delete -> RoomController::manage(delete)
    -> resolvePermission check (level >= 3 OR global admin)
    -> RoomDeletionService::deleteWithDependencies
    -> sendToRoom(room_deleted)

Transfer public room ownership:
    NOT IMPLEMENTED through normal user flow.
    Admin only: POST /api/admin/rooms/{id}/owner -> RoomManager::changeOwner
    -> begins transaction:
       UPDATE room_members SET room_role='member' WHERE room_role='owner'
       INSERT ... ON DUPLICATE KEY UPDATE room_role='owner' for newOwnerId
       UPDATE rooms SET owner_id=newOwnerId
    -> commits

---

## Numera

Source of truth: room_members.room_role = 'owner' + rooms.owner_id
Created by: findOrCreateOwnedNumer(fromUserId) on invite accept

Initial owner: from_user_id (user who sent the invitation)

Transfer on explicit leave:
    NumerController::leave(roomId, userId)
    if wasOwner AND remaining > 0:
        guard: check no other 'owner' row exists
        SELECT user_id FROM room_members
          WHERE room_id=? AND room_role != 'banned'
          ORDER BY joined_at ASC, user_id ASC LIMIT 1
        UPDATE room_members SET room_role='owner' WHERE room_id=? AND user_id=newOwnerId
        UPDATE rooms SET owner_id=newOwnerId WHERE id=?
    EventRouter sends numer_owner_changed {owner: newOwnerData} to room

Transfer on disconnect: NOT triggered.
Transfer on idle timeout: NOT triggered (numer is destroyed entirely).

---

## Room role permission gates

| Action | Min level | Additional exception |
|--------|----------|---------------------|
| rename | 3 (owner) | — |
| delete | 3 (owner) | OR global platform_owner/admin |
| set_role to local_admin | 3 (owner) | OR global platform_owner/admin |
| set_role to local_mod/member | 1 | — |
| kick | 2 (local_admin) | OR global platform_owner/admin/moderator |
| ban | 2 (local_admin) | OR global platform_owner/admin/moderator |
| mute | 2 (local_admin) | OR global platform_owner/admin/moderator |

Invariants:
    Cannot change role of 'owner' (protected in setRoomRole)
    Cannot kick/ban 'owner' (protected in kick/ban)
    platform_owner is untouchable in AccessContext (not enforced in RoomController -- gap)

---

## Risks

R1: rooms.owner_id and room_members.room_role='owner' out of sync -- no DB trigger
R2: No ownership transfer for public rooms via user flow (admin-only)
R3: platform_owner protection not enforced in RoomController::kick/ban (gap vs AccessContext)
