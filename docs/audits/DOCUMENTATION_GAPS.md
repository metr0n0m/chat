# Documentation Gaps
> Audit: 2026-05-25 | Based on full architectural audit of the project.
> [UNVERIFIED] = not confirmed directly in code.

---

## Gap 1: No inline PHPDoc on most methods

Severity: MEDIUM

Affected files (all or most public methods lack PHPDoc):
    src/WebSocket/EventRouter.php     -- only class-level comment, no method docs
    src/Chat/RoomController.php       -- no PHPDoc
    src/Chat/NumerController.php      -- "Last updated" comments only
    src/Admin/UserManager.php         -- no method PHPDoc
    src/Admin/RoomManager.php         -- some methods have "Last updated" comments only
    src/Security/Session.php          -- isUserBlocked has a comment, others minimal
    src/Http/Router.php               -- no PHPDoc

Covered by PHPDoc:
    src/Security/AccessContext.php    -- well documented
    src/WebSocket/ConnectionManager.php -- property docs only

---

## Gap 2: AccessContext.php has 0 call sites

Severity: HIGH

SecurityAccessContext.php (186 lines) is fully implemented and documented.
It covers all MODERATION_POLICY.md invariants (I-1 through I-15).
It has 0 call sites in the entire codebase.
MODERATION_POLICY.md v1.1 exists as documentation but the implementation it describes is not wired.

Gap: The intended single RBAC implementation exists but is unused.
Three parallel RBAC implementations coexist (see RBAC.md).

---

## Gap 3: No API documentation

Severity: LOW

There is no OpenAPI/Swagger spec or any formal API documentation.
The API_MAP.md in docs/architecture/ is the only reference.
All 40+ HTTP routes documented only in code (Router.php) and in these audit files.

---

## Gap 4: moderation_events / active_restrictions undocumented lifecycle

Severity: MEDIUM

Two tables exist in production DB:
    moderation_events
    active_restrictions

Both are created by migrations but never written by any PHP code.
There is no document explaining:
    - When Phase M will be implemented
    - What the intended data flow is
    - Whether old data will be backfilled

MODERATION_POLICY.md v1.1 references these tables implicitly but does not address the gap.

---

## Gap 5: WS inline JS in NumerPage.php undocumented

Severity: LOW

NumerPage.php contains ~200 lines of inline JavaScript.
This JS implements a full WS client with its own event loop.
It handles 10 WS events that are not documented in the main JS architecture.
It has no separate file, no comments, no doc.

---

## Gap 6: Config constants not formally documented

Severity: LOW

config/config.php contains constants (APP_SECRET, WS_HOST, SESSION_LIFETIME, etc.)
but there is no config reference document describing:
    - what each constant does
    - valid values / ranges
    - which constants are required vs optional

---

## Gap 7: friend_online / friend_offline stub handlers undocumented

Severity: LOW

chat.js contains case handlers for friend_online and friend_offline.
These are never sent by PHP. The gap between the handler and the non-existent sender
is not documented anywhere.

---

## Gap 8: Numer invite timer persistence not documented

Severity: MEDIUM

ReactPHP invite expiry timers (30s) are in-memory.
ReactPHP numer countdown timers (30min) are in-memory.
Server reconnect disconnect timers (12s) are in-memory.

The behavior on WS restart (timers lost, only DB periodic cleanup survives) is not
documented in a user-facing way. Only captured in this audit.

---

## Gap 9: ONLINE USER ACTIONS section freeze not formally documented

Severity: LOW

chat.js ONLINE USER ACTIONS (~267 lines) is frozen pending PREP-B audit.
This freeze is not recorded anywhere except in conversation history.
If a new developer edits this section without knowing the freeze, they could break
the moderation action flow.

---

## Gap 10: status='cancelled' dead ENUM value

Severity: LOW

invitations.status ENUM includes 'cancelled'.
No code path ever sets this value.
No documentation explains whether it was intentional, removed, or planned.
