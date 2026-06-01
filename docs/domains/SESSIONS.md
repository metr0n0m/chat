# Domain: Sessions
> Audit: 2026-05-25 | All facts verified against source code.
> [UNVERIFIED] = not confirmed directly in code.

---

## Source of Truth

sessions table in MariaDB.

---

## Table: sessions

    id          INT UNSIGNED AUTO_INCREMENT PK
    user_id     FK->users.id CASCADE DELETE
    token_hash  VARCHAR(64) UNIQUE   -- SHA-256 of raw token
    ip_ua_hash  VARCHAR(64)          -- stored but NOT checked in validate() (verified)
    expires_at  DATETIME

---

## Services / Classes

Security\Session -- generate, hash, ipUaHash, create, validate, destroy,
                    destroyAllForUser, isUserBlocked, current, setCookie, clearCookie

---

## Session lifecycle

### Create (on login/register/oauth)
    Session::create(userId, ip, userAgent):
      DELETE FROM sessions WHERE expires_at <= NOW()  -- cleanup expired on every create
      INSERT INTO sessions (user_id, token_hash, ip_ua_hash, expires_at)
      setcookie('chat_session', rawToken, ...) -- httponly, samesite=Lax, secure if HTTPS

### Validate (on every HTTP request + every WS connect)
    Session::validate(token, ip, userAgent):
      SELECT sessions JOIN users WHERE token_hash=SHA256(token) AND expires_at > NOW()
      if not found OR isUserBlocked: return null
      UPDATE users SET last_seen_at = NOW()
      return session array (user snapshot)

    NOTE: ip_ua_hash is stored but NOT compared in validate().
    The ip and userAgent parameters are passed but only used for ipUaHash() which
    is only called in create(). Verified in Session.php: validate() does not call ipUaHash().

### Destroy (on logout)
    Session::destroy(token):
      DELETE FROM sessions WHERE token_hash = SHA256(token)

### Force destroy (on ban/force logout)
    Session::destroyAllForUser(userId):
      DELETE FROM sessions WHERE user_id = ?
    Called by: EventRouter::executeForceLogout, EventRouter::route (ban check)

---

## Cookie settings

    name     = 'chat_session'
    expires  = time() + SESSION_LIFETIME
    path     = '/'
    domain   = COOKIE_DOMAIN (config constant)
    secure   = true if HTTPS
    httponly = true
    samesite = 'Lax'

---

## WS session validation

WS connect (Server::onOpen):
    Parses Cookie header from HTTP upgrade request
    Extracts 'chat_session' token
    Calls Session::validate(token, ip, ua) -- same as HTTP
    On failure: rejects connection
    On success: cm->add(conn, session) -- session stored in ConnectionManager::sessions[connId]

The WS session is a SNAPSHOT. It does not auto-refresh.
username/role/color changes are NOT reflected until user reconnects.

---

## Invariants

I-S1: DB-backed tokens (not JWT) -- can be revoked server-side at any time
I-S2: No IP/UA hard lock -- ip_ua_hash stored but NOT checked in validate() (verified)
      This is an explicit product decision.
I-S3: SESSION_LIFETIME constant controls expiry
I-S4: Force logout = Session::destroyAllForUser(userId) + cm->closeUser(userId, {force_logout, reason})
      Order: destroyAllForUser first (so reconnect attempts fail), then closeUser
I-S5: Expired sessions are cleaned up on every Session::create() call
