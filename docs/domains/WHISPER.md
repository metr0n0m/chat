# Domain: Whisper
> Audit: 2026-05-25 | All facts verified against source code.
> [UNVERIFIED] = not confirmed directly in code.

---

## Source of Truth

messages table (type='whisper') in MariaDB.

---

## Storage

Whispers are stored in the same messages table as regular messages:
    type       = 'whisper'
    whisper_to = FK->users.id (recipient)

No separate whisper table. Whispers are excluded from:
- clearMessages (WHERE type != 'whisper')
- public message history (MessageController::history filters by default [UNVERIFIED - filter logic not traced])

---

## Services / Classes

ChatWhisperController -- send, ownerSessionList, ownerSessionDetail, archive, deleteWhisper, clearWhispers
WebSocketEventRouter  -- onSendWhisper (calls WhisperController::send, sends whisper_sent + whisper_received)

---

## WS flow

    send_whisper WS (client, from User A to User B in room R)
      -> EventRouter::onSendWhisper
           checks: cm->isInRoom(A, roomId)     -- A must be in room
           checks: cm->isInRoom(B, roomId)     -- B must be in same room
           checks: cm->checkWhisperLimit(A)    -- WHISPER_RATE_LIMIT_MIN per minute
           calls: WhisperController::send(roomId, fromId=A, session, toId=B, content)
                    INSERT messages (type=whisper, whisper_to=B, content, content_hmac)
           sends: whisper_sent    -> cm->sendToUser(A, {event:whisper_sent, message})
           sends: whisper_received -> cm->sendToUser(B, {event:whisper_received, message})
                  (only if A != B)

---

## Admin access (platform_owner only)

All whisper admin routes require Access::requireOwnerPrivateArchive (verified).

GET /api/admin/whispers/sessions       -> WhisperController::ownerSessionList
GET /api/admin/whispers/sessions/{id}  -> WhisperController::ownerSessionDetail
GET /api/admin/whispers                -> WhisperController::archive
DELETE /api/admin/whispers/{id}        -> WhisperController::deleteWhisper
POST /api/admin/whispers/clear         -> WhisperController::clearWhispers

---

## Rate limiting

cm->checkWhisperLimit(userId):
    Tracks whisper timestamps per user in ConnectionManager::whisperTimes[]
    Rejects if count in last 60s >= WHISPER_RATE_LIMIT_MIN constant
    In-memory only -- lost on WS restart

---

## Invariants

I-W1: Both sender and recipient must be in same room at send time (cm->isInRoom check, verified in EventRouter)
I-W2: Whisper rate limit via cm->checkWhisperLimit (WHISPER_RATE_LIMIT_MIN constant)
I-W3: Archive accessible only to platform_owner (Access::requireOwnerPrivateArchive, verified)
I-W4: sender_session_id stored for session-level audit [UNVERIFIED -- not traced through all insert paths]
I-W5: Whispers excluded from bulk clearMessages operations (WHERE type != 'whisper', verified in RoomManager)
