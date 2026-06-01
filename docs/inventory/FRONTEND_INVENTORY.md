# Frontend Inventory
> Audit: 2026-05-25 | All facts verified against source code.
> Line counts verified by wc -l.

---

## Shell

public/index.php -- PHP-rendered HTML shell, injects CURRENT_USER JSON + CSRF_TOKEN

---

## JS Layer Architecture

Layer 0 -- CDN: jQuery 3.x, dayjs+plugins, Bootstrap 5.x, autosize, Font Awesome
Layer 1 -- Pure utilities (no local deps): chat-utils.js, chat-display.js, chat-input.js, chat-time.js
Layer 2 -- Shell: chat-shell.js
Layer 3 -- Core (exposes window globals): chat.js
Layer 4 -- Feature modules (depend on chat.js globals):
    chat-numer.js, chat-roomevents.js, chat-messages.js, chat-input-send.js
    chat-friends.js, chat-settings.js, chat-sidebar.js, chat-admin.js
Standalone: chat-auth.js (login/register page)
Numer popup: NumerPage.php inline JS (separate WS connection, no dep on chat.js)

MODULE SYSTEM: NONE. No import/export. All functions are window-level globals.
Load order enforced by script tag order in public/index.php.

---

## Total JS line count
chat-utils.js      88
chat-display.js    22
chat-input.js      25
chat-time.js        7
chat-shell.js     161
chat.js           741
chat-numer.js      73
chat-roomevents.js  55
chat-messages.js  114
chat-input-send.js 110
chat-friends.js    24
chat-settings.js   97
chat-sidebar.js    88
chat-admin.js     767
chat-auth.js       62
TOTAL            2434 lines

---

## chat-utils.js (88 lines)
No local deps. Functions:
esc, displayName, isDarkTheme, hexToHsl, hslToHex, wcagLuminance, wcagContrast
roleLabel, roomRoleLabel, showToast, systemAlert, shouldShowSystemMessages, shouldShowSystemMessage

## chat-display.js (22 lines)
No local deps. Functions: avatarMarkup, visibleRoleLabel, visibleRoleClass

## chat-input.js (25 lines)
No local deps. Functions: normalizeWhisperContent, wrapSelection

## chat-time.js (7 lines)
Depends on: window.ChatConfig.timeFormat, window.ChatConfig.datetimeFormat
Functions: formatChatTime, formatChatDateTime

## chat-shell.js (161 lines)
Depends on: jQuery (CDN)
Exports: window.ChatShell.renderMobileUsersRail

## chat.js (741 lines) [GOD OBJECT]
Depends on: jQuery, dayjs, Bootstrap (CDN), chat-utils.js, chat-display.js, chat-time.js
Exports (window-level):
  STATE: ws, currentRoomId, currentPublicRoomId, currentRoomRole, rooms[], numera[]
         onlineUsers[], currentOnlineUsers[], ignoredUserIds[], CURRENT_USER, CSRF_TOKEN
         isScrolledToBottom
  WS: wsSend, handleWS
  Rooms: loadRooms, joinPublicRoom, openNumerWindow, loadHistory, updateRoomBadge, onRoomCountChanged
  Sidebar: removeNumerFromSidebar, removePublicRoomFromSidebar
  Online: renderOnlineList, addToOnlineList, removeFromOnlineList, updateOnlineUser, buildOnlineUser
  FROZEN actions: openUserInfo, canModerateCurrentRoom, canAssignLocalModerator,
                  canAssignLocalAdmin, executeRoomAction, executeGlobalBan, toggleIgnoreUser
  Helpers: scrollToBottom, effectiveColor, _effectiveColorCache

FROZEN SECTION: ONLINE USER ACTIONS (~267 lines)
  Includes: buildOnlineUser, openUserInfo, canModerateCurrentRoom, canAssignLocalModerator,
            canAssignLocalAdmin, executeRoomAction, executeGlobalBan, toggleIgnoreUser
  Frozen: requires PREP-B (showUserCtxMenu audit) before extraction is safe.

## chat-numer.js (73 lines)
Depends on: chat.js globals (openNumerWindow, wsSend, numera, esc, displayName, showToast), Bootstrap Modal
Functions: upsertNumerInSidebar, onNumerJoined, onInviteSent, onInviteAccepted,
           onInviteDeclined, onInviteExpired, onInviteReceived

## chat-roomevents.js (55 lines)
Depends on: chat.js globals (showToast, loadRooms, currentPublicRoomId, currentRoomId, removePublicRoomFromSidebar)
Functions: onKickedFromRoom, onBannedFromRoom, onMutedInRoom, onRoomDeleted

## chat-messages.js (114 lines)
Depends on: chat.js (effectiveColor, scrollToBottom, isScrolledToBottom, currentRoomId,
            currentRoomRole, ignoredUserIds, CURRENT_USER)
            chat-utils.js (esc, displayName, showToast, shouldShowSystemMessage)
            chat-time.js (formatChatTime)
            chat-display.js (avatarMarkup)
Functions: buildMessage, buildWhisperMessage, appendMessage, onNewMessage,
           onMessageDeleted, onSystemMessage, onWhisperMessage, shouldRenderMessage, canDeleteMessage

## chat-input-send.js (110 lines)
Depends on: chat.js (wsSend, currentRoomId, whisperToId, whisperToName)
            chat-input.js (normalizeWhisperContent, wrapSelection)
            chat-utils.js (showToast)
Functions: appendInputToken, insertDirectAddress, initInput, sendMessage,
           activateWhisperMode, clearWhisperMode

## chat-friends.js (24 lines)
Depends on: chat-utils.js (esc, displayName, showToast), dayjs (CDN)
Functions: loadFriends, renderFriends

## chat-settings.js (97 lines)
Depends on: chat.js (CURRENT_USER, CSRF_TOKEN, ws, initUser), chat-utils.js (shouldShowSystemMessages)
Functions: openSettingsModal, initSettings

## chat-sidebar.js (88 lines)
Depends on: chat.js globals, chat-settings.js (openSettingsModal)
            chat-display.js (avatarMarkup, visibleRoleLabel), chat-utils.js
Functions: initSidebar

## chat-admin.js (767 lines) [GOD OBJECT]
Depends on: chat.js (openUserInfo, CURRENT_USER, CSRF_TOKEN, rooms, numera)
            chat-utils.js (esc, showToast), chat-time.js (formatChatDateTime), dayjs
Functions: initAdmin, loadAdminDash, loadAdminUsers, renderUsersTable,
           loadAdminRooms, renderRoomsTable, loadAdminBans, loadAdminNumera,
           loadNumerArchive, loadOwnerWhisperSessions, loadAdminSettings,
           loadRoomMessages, ban/unban/unmute handlers, user edit/create modals,
           room rename/delete/category handlers

## chat-auth.js (62 lines)
Standalone. No runtime dependency on other local modules.
Handles: login form submit, register form submit

---

## Dependency matrix

              utils  display  input  time  chat.js  notes
chat-numer      Y                              Y
chat-roomevents Y                              Y
chat-messages   Y      Y              Y        Y
chat-input-send Y              Y               Y
chat-friends    Y                              Y
chat-settings   Y                              Y
chat-sidebar    Y      Y                       Y   + chat-settings.js
chat-admin      Y                      Y       Y

---

## God Objects JS

chat-admin.js  767 lines  HIGH     15 responsibilities
chat.js        741 lines  HIGH     15 responsibilities, frozen ONLINE USER ACTIONS section
