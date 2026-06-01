# Domain: Rooms
> Audit: 2026-05-25 | All facts verified against source code.
> [UNVERIFIED] = not confirmed directly in code.

---

## Source of Truth

rooms table + room_members table in MariaDB.

---

## Tables

### rooms
    id            INT UNSIGNED AUTO_INCREMENT PK
    name          VARCHAR(100)
    description   TEXT NULL
    type          ENUM(public/numer)
    owner_id      FK->users.id SET NULL
    is_closed     TINYINT(1) DEFAULT 0
    close_reason  VARCHAR(50) NULL
    max_members   INT NULL   -- NULL=unlimited; numer=4
    is_default    TINYINT(1) DEFAULT 0
    room_category ENUM(permanent/user/commercial/...) -- values from DB ENUM
    created_at    DATETIME
    closed_at     DATETIME NULL

### room_members
    room_id     FK->rooms.id CASCADE DELETE  -- composite PK with user_id
    user_id     FK->users.id CASCADE DELETE  -- composite PK with room_id
    room_role   ENUM(owner/local_admin/local_moderator/member/banned)
    joined_at   DATETIME
    banned_at   DATETIME NULL
    banned_by   FK->users.id NULL
    ban_reason  TEXT NULL
    muted_until DATETIME NULL
    mute_reason TEXT NULL

---

## Services / Classes

Chat\RoomController    -- list, create, join, manage (rename/delete/kick/ban/mute/set_role), numera, members
Chat\RoomDeletionService -- deleteWithDependencies (used by RoomController + RoomManager)
Admin\RoomManager      -- admin CRUD: list, rename, delete, members, setMemberRole, changeOwner,
                           numeraActive, closeNumer, numeraArchive, numeraMessages, roomMessages,
                           clearMessages, clearUserMessages, clearNumerArchive, setCategory
WebSocket\EventRouter  -- onJoinRoom, onLeaveRoom, onRoomAction, handleRoomLeave

---

## Writers (write)

RoomController::create        -- rooms INSERT, room_members INSERT (owner)
RoomController::join          -- room_members INSERT (member)
RoomController::manage/kick   -- room_members DELETE
RoomController::manage/ban    -- room_members UPDATE room_role=banned
RoomController::manage/mute   -- room_members UPDATE muted_until
RoomController::manage/delete -- via RoomDeletionService::deleteWithDependencies
RoomManager::closeNumer       -- rooms UPDATE is_closed=1, close_reason=admin
RoomManager::changeOwner      -- room_members UPDATE room_role + rooms UPDATE owner_id (in transaction)
RoomManager::setMemberRole    -- room_members UPDATE room_role
NumerController::leave        -- room_members DELETE, rooms UPDATE is_closed=1 (if last)
EventRouter::startNumerCountdown -- rooms UPDATE is_closed=1, close_reason=idle (after 30min)

---

## Consumers (read)

RoomController::list          -- GET /api/rooms -- rooms + room_members (my_role, member_count)
RoomController::numera        -- GET /api/numera -- rooms JOIN room_members (userId)
EventRouter::onJoinRoom       -- join_room WS -- rooms (type, is_closed) + room_members (role, ban check)
NumerController::findOrCreateOwnedNumer -- invite accept -- rooms WHERE owner_id + type=numer
RoomManager::list             -- GET /api/admin/rooms -- rooms with stats

---

## Invariants

I-R1: rooms.owner_id and room_members.room_role=owner must be in sync -- no DB trigger enforces this
I-R2: type=numer max_members=4 (set at creation by findOrCreateOwnedNumer)
I-R3: is_closed=1 rooms hidden from API (WHERE is_closed=0)
I-R4: room_category=permanent cannot be deleted (checked in RoomController::manage AND RoomManager::delete)
I-R5: Public rooms: user is auto-joined on first join_room WS (RoomController::join called if not member)
I-R6: Numera: user is NOT auto-joined -- must be in room_members already (from invite accept)

---

## Room type differences

| Aspect | public | numer |
|--------|--------|-------|
| Max members | NULL (unlimited) | 4 |
| Auto-join on WS join_room | YES | NO |
| Appears in GET /api/rooms | YES | NO |
| Appears in GET /api/numera | NO | YES |
| Popup window | NO | YES (/numer/{id}) |
| Idle auto-close | NO | YES (30min if 1 participant) |
| Owner transfer | NOT IMPLEMENTED | YES (on explicit leave) |

---

## Room deletion paths

Path 1 -- WS room_action:delete (owner/admin via chat UI):
  EventRouter::onRoomAction -> RoomController::manage(delete)
  -> RoomDeletionService::deleteWithDependencies(db, roomId)
  -> sendToRoom(room_deleted)

Path 2 -- HTTP DELETE /api/admin/rooms/{id} (admin panel):
  Router -> RoomManager::delete
  -> RoomDeletionService::deleteWithDependencies(db, roomId)
  [No WS event sent -- no IPC]

Path 3 -- HTTP POST /api/admin/numera/{id}/close (admin panel, numera only):
  Router -> RoomManager::closeNumer
  -> UPDATE rooms SET is_closed=1, close_reason=admin
  [No WS numer_destroyed sent -- no IPC]

Path 4 -- WS leave_numer last member:
  EventRouter::onLeaveNumer -> NumerController::leave -> remaining=0
  -> UPDATE rooms SET is_closed=1, close_reason=last_left
  -> sendToRoom(numer_destroyed)

Path 5 -- ReactPHP 30min idle timeout:
  EventRouter::startNumerCountdown callback
  -> UPDATE rooms SET is_closed=1, close_reason=idle
  -> sendToRoom(numer_destroyed)