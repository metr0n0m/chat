# Domain: Messages
> Audit: 2026-05-25 | All facts verified against source code.
> [UNVERIFIED] = not confirmed directly in code.

---

## Source of Truth

messages table in MariaDB.

---

## Table: messages

    id                BIGINT UNSIGNED AUTO_INCREMENT PK
    room_id           FK->rooms.id  -- NO CASCADE DELETE (orphan risk)
    user_id           FK->users.id
    type              ENUM(text/system/whisper)
    content           TEXT
    content_hmac      VARCHAR(64)   -- HMAC signature of content
    system_importance ENUM(normal/optional/important) NULL
    system_scope      VARCHAR(50) NULL   -- e.g. room_join, room_leave, room_kick, room_ban, moderation_call
    whisper_to        FK->users.id NULL  -- only for type=whisper
    sender_session_id FK->sessions.id NULL
    embed_data        JSON NULL
    is_deleted        TINYINT(1) DEFAULT 0
    deleted_by        FK->users.id NULL
    deleted_at        DATETIME NULL
    created_at        DATETIME

---

## Services / Classes

Chat\MessageController    -- send (INSERT type=text), delete (UPDATE is_deleted=1), history (SELECT)
Chat\SystemMessageService -- emitRoomLifecycle (INSERT type=system + sendToRoom), emitModerationCall (sendToUser)
Chat\EmbedProcessor       -- URL embed detection, called from MessageController::send, result in embed_data
Chat\WhisperController    -- send (INSERT type=whisper), archive, ownerSessionList, ownerSessionDetail, deleteWhisper, clearWhispers

---

## Consumers (read)

MessageController::history   -- GET /api/rooms/{id}/messages -- messages JOIN users, paginated, filtered
RoomManager::roomMessages    -- GET /api/admin/rooms/{id}/messages -- admin view
RoomManager::numeraMessages  -- GET /api/admin/numera/{id}/messages -- admin archive

---

## Writers (write)

MessageController::send              -- send_message WS         -- messages INSERT (type=text)
MessageController::delete            -- delete_message WS        -- messages UPDATE is_deleted=1
SystemMessageService::emitRoomLifecycle -- join/leave/kick/ban WS -- messages INSERT (type=system)
WhisperController::send              -- send_whisper WS          -- messages INSERT (type=whisper)
RoomManager::clearMessages           -- POST /api/admin/rooms/{id}/clear -- messages UPDATE is_deleted=1 (bulk, exclude whisper)
RoomManager::clearUserMessages       -- POST /api/admin/rooms/{id}/clear-user/{uid} -- messages UPDATE is_deleted=1 (user in room)
RoomManager::clearNumerArchive       -- POST /api/admin/numera/{id}/clear-archive -- messages UPDATE is_deleted=1

---

## Invariants

I-M1: messages.room_id has NO CASCADE DELETE -- orphan rows remain if room is deleted
I-M2: content_hmac set via HMAC (verified: MessageController uses HMAC)
I-M3: is_deleted=1 is soft-delete; content preserved in DB
I-M4: type=whisper excluded from clearMessages (WHERE type != 'whisper', verified in RoomManager)
I-M5: system_importance ENUM controls visibility filtering in client (shouldShowSystemMessage in chat-utils.js)
I-M6: system_scope values: room_join, room_leave, room_kick, room_ban, room_role_changed, moderation_call (verified in SystemMessageService)

---

## system_message visibility rules (SystemMessageService::visibilityForScope)

    moderation_call  -> global_moderators, global_admins, platform_owners (NOT all users)
    room_kick        -> all
    room_ban         -> all
    default (any)    -> all

---

## WS flow: text message

    send_message WS (client)
      -> EventRouter::onSendMessage
           checks: cm->isInRoom(userId, roomId)
           checks: cm->checkRateLimit(userId)
           calls: MessageController::send(roomId, userId, session, data)
                    validates content length (MAX_MESSAGE_LENGTH constant)
                    calls EmbedProcessor to detect URLs
                    INSERT messages (content, content_hmac=HMAC::sign(content), type=text, embed_data)
           sends: new_message event to room (cm->sendToRoom)
           checks: content contains @! -> SystemMessageService::emitModerationCall

---

## WS flow: delete message

    delete_message WS (client)
      -> EventRouter::onDeleteMessage
           calls: MessageController::delete(msgId, userId, session)
                    SELECT messages WHERE id=?
                    checks permission: own message OR global_role in admin/platform_owner/moderator
                                       OR room_role in owner/local_admin/local_moderator
                    UPDATE messages SET is_deleted=1, deleted_by=?, deleted_at=NOW()
           sends: message_deleted event to room (cm->sendToRoom, by room_id from result)
