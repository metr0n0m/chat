# Domain: Friends
> Audit: 2026-05-25 | All facts verified against source code.
> [UNVERIFIED] = not confirmed directly in code.

---

## Source of Truth

friendships table in MariaDB.

---

## Table: friendships

    id            INT UNSIGNED AUTO_INCREMENT PK
    requester_id  FK->users.id
    addressee_id  FK->users.id
    status        ENUM(pending/accepted/declined/blocked)
    created_at    DATETIME
    updated_at    DATETIME NULL

---

## Services / Classes

Http\Router -- handleGetFriends, handleAddFriend, handleRespondFriend
             (inline methods in Router, NO separate FriendController class)

---

## HTTP routes

GET  /api/friends              -> Router::handleGetFriends
POST /api/friends              -> Router::handleAddFriend
POST /api/friends/{id}/respond -> Router::handleRespondFriend

---

## Logic (inline in Router)

handleGetFriends:
    SELECT u.id, username, nick_color, avatar_url, last_seen_at, f.status,
           current_room subquery (first public room user is in)
    FROM friendships f JOIN users u
    WHERE (requester_id=me OR addressee_id=me) AND status='accepted'
    ORDER BY username

handleAddFriend:
    INSERT IGNORE INTO friendships (requester_id=me, addressee_id=toId)

handleRespondFriend:
    UPDATE friendships SET status=accepted|declined WHERE id=? AND addressee_id=me

---

## Consumers (read)

Router::handleGetFriends  -- GET /api/friends -- friendships JOIN users
UserManager::profile      -- GET /api/users/{id} -- friend_count subquery
chat-friends.js           -- calls loadFriends() -> GET /api/friends

---

## WS events (stub handlers)

case 'friend_online'  in chat.js line 207 -> loadFriends()
case 'friend_offline' in chat.js line 208 -> loadFriends()

These case handlers EXIST in chat.js but are NEVER sent by any PHP code.
Verified by grep: no 'friend_online' or 'friend_offline' in Server.php or EventRouter.php.
They are DEAD STUB HANDLERS.

---

## Invariants

I-F1: No UNIQUE constraint on reverse pair (requester_id, addressee_id) + (addressee_id, requester_id)
      INSERT IGNORE prevents exact duplicate only. Reverse pair (B->A after A->B) theoretically possible.
I-F2: status='blocked' -- ENUM value, no code path found that sets it (confirmed by grep across all PHP files in src/)
I-F3: friend_online / friend_offline WS events are handled in JS but never sent by PHP
I-F4: No notification WS event when friend request is sent or accepted
