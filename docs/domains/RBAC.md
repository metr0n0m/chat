# Domain: RBAC
> Audit: 2026-05-25 | All facts verified against source code.
> [UNVERIFIED] = not confirmed directly in code.

---

## Source of Truth

users.global_role + room_members.room_role in MariaDB.

---

## Three parallel implementations (all verified in code, 0 of them use AccessContext)

---

## Implementation 1: AdminAccess.php (162 lines)

Path: src/Admin/Access.php

Public methods:
    isOwner(user)                  -- global_role = platform_owner
    isGlobalAdmin(user)            -- global_role in (platform_owner, admin)
    isGlobalModerator(user)        -- global_role = moderator
    isGlobalStaff(user)            -- global_role in (platform_owner, admin, moderator)
    canOpenAdminPanel(user)        -- isGlobalAdmin
    canOpenOwnerPanel(user)        -- isOwner
    canAccessRoom(user, roomId)    -- isGlobalAdmin OR not banned in room
    canModerateRoom(user, roomId)  -- resolveLevel >= 1
    canManageRoom(user, roomId)    -- resolveLevel >= 3
    canAssignRoomRole(user, roomId, targetRole) -- resolveLevel >= 1 (>= 3 for local_admin)
    canDeleteMessage(user, message) -- isGlobalAdmin OR isGlobalModerator(public room) OR room_role in owner/local_admin/local_moderator
    requireOwnerOnly(user)         -- deny with 404 if not platform_owner
    requireOwnerPrivateArchive(user) -- deny with 404 if not platform_owner

Private:
    resolveLevel(roomId, userId, actor) -- returns numeric level (see scale below)

Used by:
    AdminPanel::requireAdmin
    NumerPage::render
    RoomManager (requireOwnerPrivateArchive)

---

## Implementation 2: ChatRoomController::resolvePermission() (private, ~25 lines)

Path: src/Chat/RoomController.php (private static method)

Identical numeric scale to Access::resolveLevel.
Duplicated logic. No shared base class.
Used ONLY within RoomController::manage().
NOT used by EventRouter directly -- EventRouter calls RoomController::manage() which calls this.

---

## Implementation 3: SecurityAccessContext.php (186 lines) -- NOT CONNECTED

Path: src/Security/AccessContext.php

getModerationContext(userId, roomId):
    Single JOIN query: global_role + room_role in one round-trip
    Returns {level, source:global|room, role, room_id}
    Results cached per request (flush() must be called per WS event)

canModerate(actorCtx, targetCtx):
    I-1: NOT checked here (use canModerateUser for self-action guard)
    I-3: platform_owner is untouchable
    Scope: local actor cannot act outside their room
    Level: actor.level must be strictly greater than target.level

canModerateUser(actorId, targetId, roomId):
    I-1: self-action forbidden (actorId === targetId returns false)
    Calls getModerationContext for both + canModerate

maxDurationType(actorCtx, sanctionType):
    local_moderator/moderator: max 24h for both ban and mute
    local_admin: max 7d for mute, unlimited for ban_room
    global_admin/platform_owner/owners: unlimited

STATUS: 0 call sites in entire codebase (verified by grep: no 'AccessContext', 'new AccessContext', 'accessContext' anywhere except the file itself)

---

## Numeric scale (consistent across all 3 implementations)

| Level | Role | Scope |
|-------|------|-------|
| 7 | root_owner | reserved in AccessContext, not in DB |
| 6 | platform_owner | global |
| 5 | admin | global |
| 4 | moderator | global |
| 3 | owner | room local |
| 2 | local_admin | room local |
| 1 | local_moderator | room local |
| 0 | member | room local |
| -1 | no access / banned / not in room | — |

---

## Permission decision matrix (RoomController::manage)

| Action | Required level | Exception |
|--------|---------------|-----------|
| rename | >= 3 (owner) | — |
| delete | >= 3 (owner) | OR global_role in platform_owner/admin |
| set_role (local_admin) | >= 3 (owner) | OR global_role in platform_owner/admin |
| set_role (local_moderator/member) | >= 1 | — |
| kick | >= 2 (local_admin) | OR global_role in platform_owner/admin/moderator |
| ban | >= 2 (local_admin) | OR global_role in platform_owner/admin/moderator |
| mute | >= 2 (local_admin) | OR global_role in platform_owner/admin/moderator |

---

## RBAC risk

THREE parallel implementations means a change to the permission model
requires synchronized updates in 3 places:
1. AdminAccess.php
2. ChatRoomController::resolvePermission()
3. SecurityAccessContext.php (not connected, but exists)

The correct fix is to wire AccessContext as the single implementation.
This is blocked pending PREP-C (full caller audit).
