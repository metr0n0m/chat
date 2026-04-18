<?php
declare(strict_types=1);

namespace Chat\Http;

use Chat\Security\{Session, CSRF};
use Chat\Auth\{LoginHandler, RegisterHandler, VKOAuth, GoogleOAuth};
use Chat\Chat\{RoomController, MessageController, WhisperController, NumerPage};
use Chat\Admin\{AdminPanel, UserManager, RoomManager};
use Chat\DB\Connection;

class Router
{
    private string $method;
    private string $path;
    private ?array $user = null;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->path   = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    }

    public function dispatch(): void
    {
        if (str_starts_with($this->path, '/storage/avatars/')) {
            $this->serveAvatar();
        }

        $this->dispatchAuth();

        $this->user = Session::current();

        if ($this->path === '/auth/logout') {
            if ($this->user) {
                Session::destroy($_COOKIE['chat_session'] ?? '');
            }
            Session::clearCookie();
            header('Location: /');
            exit;
        }

        if ($this->path === '/api/csrf') {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['token' => CSRF::token()]);
            exit;
        }

        if (!$this->user) {
            return;
        }

        header('Vary: Accept');
        $this->dispatchApi();
        $this->dispatchAdmin();
    }

    public function getUser(): ?array
    {
        return $this->user;
    }

    private function serveAvatar(): never
    {
        $file = AVATAR_PATH . '/' . basename($this->path);
        if (is_file($file)) {
            header('Content-Type: image/jpeg');
            header('Cache-Control: public, max-age=604800');
            readfile($file);
        } else {
            http_response_code(404);
        }
        exit;
    }

    private function dispatchAuth(): void
    {
        if ($this->method === 'POST' && $this->path === '/auth/login')    LoginHandler::handle();
        if ($this->method === 'POST' && $this->path === '/auth/register') RegisterHandler::handle();
        if ($this->path === '/auth/vk')                                   VKOAuth::redirect();
        if ($this->path === '/auth/vk/callback')                          VKOAuth::callback();
        if ($this->path === '/auth/google')                               GoogleOAuth::redirect();
        if ($this->path === '/auth/google/callback')                      GoogleOAuth::callback();
    }

    private function dispatchApi(): void
    {
        $m = [];

        if ($this->method === 'GET'  && $this->path === '/api/rooms')  RoomController::list((int) $this->user['id'], $this->user['global_role']);
        if ($this->method === 'GET'  && $this->path === '/api/numera') RoomController::numera((int) $this->user['id']);
        if ($this->method === 'POST' && $this->path === '/api/rooms')  RoomController::create((int) $this->user['id'], $this->user);

        if ($this->method === 'GET' && preg_match('~^/numer/(\d+)$~', $this->path, $m)) NumerPage::render((int) $m[1], $this->user);

        if ($this->method === 'GET' && preg_match('~^/api/rooms/(\d+)/members$~', $this->path, $m))  RoomController::members((int) $m[1], (int) $this->user['id']);

        if ($this->method === 'GET' && preg_match('~^/api/rooms/(\d+)/messages$~', $this->path, $m)) {
            $before = isset($_GET['before']) ? (int) $_GET['before'] : null;
            MessageController::history((int) $m[1], (int) $this->user['id'], $this->user['global_role'], $before);
        }

        if ($this->method === 'GET'  && preg_match('~^/api/users/(\d+)$~', $this->path, $m)) UserManager::profile((int) $m[1]);
        if ($this->method === 'POST' && $this->path === '/api/settings')                       UserManager::updateSettings((int) $this->user['id'], $_POST, $_FILES);

        if ($this->method === 'GET'  && $this->path === '/api/friends') $this->handleGetFriends();
        if ($this->method === 'POST' && $this->path === '/api/friends') $this->handleAddFriend();
        if ($this->method === 'POST' && preg_match('~^/api/friends/(\d+)/respond$~', $this->path, $m)) $this->handleRespondFriend((int) $m[1]);

        if ($this->method === 'GET'  && $this->path === '/api/users/check') $this->handleUsernameCheck();
        if ($this->method === 'GET'  && $this->path === '/api/users/find')  $this->handleFindUser();
    }

    private function dispatchAdmin(): void
    {
        if (!str_starts_with($this->path, '/admin') && !str_starts_with($this->path, '/api/admin')) {
            return;
        }

        $admin = AdminPanel::requireAdmin();
        $m     = [];

        if ($this->method === 'GET'    && $this->path === '/api/admin/dashboard')                                AdminPanel::dashboard();
        if ($this->method === 'GET'    && $this->path === '/api/admin/users')                                   UserManager::list((int) ($_GET['page'] ?? 1), $_GET['search'] ?? '');
        if ($this->method === 'POST'   && $this->path === '/api/admin/users')                                   AdminPanel::createUser($admin, $_POST);
        if ($this->method === 'POST'   && preg_match('~^/api/admin/users/(\d+)$~', $this->path, $m))            UserManager::update((int) $m[1], $_POST);
        if ($this->method === 'DELETE' && preg_match('~^/api/admin/users/(\d+)$~', $this->path, $m))            UserManager::delete((int) $m[1]);
        if ($this->method === 'GET'    && $this->path === '/api/admin/rooms')                                   RoomManager::list((int) ($_GET['page'] ?? 1));
        if ($this->method === 'POST'   && preg_match('~^/api/admin/rooms/(\d+)/rename$~', $this->path, $m))     RoomManager::rename((int) $m[1], $_POST['name'] ?? '');
        if ($this->method === 'POST'   && preg_match('~^/api/admin/rooms/(\d+)/category$~', $this->path, $m))   RoomManager::setCategory((int) $m[1], $_POST['category'] ?? '');
        if ($this->method === 'DELETE' && preg_match('~^/api/admin/rooms/(\d+)$~', $this->path, $m))            RoomManager::delete((int) $m[1]);
        if ($this->method === 'GET'    && preg_match('~^/api/admin/rooms/(\d+)/members$~', $this->path, $m))    RoomManager::members((int) $m[1]);
        if ($this->method === 'GET'    && preg_match('~^/api/admin/rooms/(\d+)/messages$~', $this->path, $m))   RoomManager::roomMessages((int) $m[1], (int) ($_GET['page'] ?? 1), $_GET['user'] ?? '');
        if ($this->method === 'POST'   && preg_match('~^/api/admin/rooms/(\d+)/clear$~', $this->path, $m))      RoomManager::clearMessages((int) $m[1]);
        if ($this->method === 'POST'   && preg_match('~^/api/admin/rooms/(\d+)/clear-user/(\d+)$~', $this->path, $m)) RoomManager::clearUserMessages((int) $m[1], (int) $m[2]);
        if ($this->method === 'GET'    && $this->path === '/api/admin/numera')                                  RoomManager::numeraActive((int) ($_GET['page'] ?? 1));
        if ($this->method === 'GET'    && $this->path === '/api/admin/numera/archive')                          RoomManager::numeraArchive((int) ($_GET['page'] ?? 1), $_GET);
        if ($this->method === 'POST'   && preg_match('~^/api/admin/numera/(\d+)/close$~', $this->path, $m))    RoomManager::closeNumer((int) $m[1]);
        if ($this->method === 'GET'    && preg_match('~^/api/admin/numera/(\d+)/messages$~', $this->path, $m))  RoomManager::numeraMessages((int) $m[1]);
        if ($this->method === 'GET'    && $this->path === '/api/admin/whispers')                                WhisperController::archive((int) ($_GET['page'] ?? 1), $_GET);
        if ($this->method === 'DELETE' && preg_match('~^/api/admin/whispers/(\d+)$~', $this->path, $m))         WhisperController::deleteWhisper((int) $m[1]);
        if ($this->method === 'POST'   && $this->path === '/api/admin/whispers/clear')                          WhisperController::clearWhispers($_POST);
        if ($this->method === 'GET'    && $this->path === '/api/admin/bans')                                    UserManager::listBanned();
        if ($this->method === 'POST'   && preg_match('~^/api/admin/rooms/(\d+)/unban/(\d+)$~', $this->path, $m))  UserManager::roomUnban((int) $m[1], (int) $m[2]);
        if ($this->method === 'POST'   && preg_match('~^/api/admin/rooms/(\d+)/unmute/(\d+)$~', $this->path, $m)) UserManager::roomUnmute((int) $m[1], (int) $m[2]);
        if ($this->method === 'GET'    && $this->path === '/api/admin/moderators')                              AdminPanel::globalModerators();
        if ($this->method === 'GET'    && $this->path === '/api/admin/room-creators')                           AdminPanel::roomCreators();
        if ($this->method === 'GET'    && $this->path === '/api/admin/status-override-settings')                AdminPanel::statusOverrideSettings();
        if ($this->method === 'POST'   && $this->path === '/api/admin/status-override-settings') {
            if (!CSRF::verifyRequest()) { http_response_code(403); echo json_encode(['error' => 'CSRF']); exit; }
            AdminPanel::updateStatusOverrideSettings($admin, $_POST);
        }
        if ($this->method === 'GET'    && $this->path === '/api/admin/system-settings')   AdminPanel::getSystemSettings();
        if ($this->method === 'POST'   && $this->path === '/api/admin/system-settings')   AdminPanel::updateSystemSettings($admin, $_POST);
    }

    private function handleGetFriends(): never
    {
        $db     = Connection::getInstance();
        $userId = (int) $this->user['id'];
        $friends = $db->fetchAll(
            "SELECT u.id, u.username, u.nick_color, u.avatar_url, u.last_seen_at,
                    f.status,
                    (SELECT r.name FROM rooms r
                     JOIN room_members rm2 ON rm2.room_id = r.id AND rm2.user_id = u.id
                     WHERE r.type = 'public' AND r.is_closed = 0 LIMIT 1) AS current_room
             FROM friendships f
             JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
             WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted'
             ORDER BY u.username",
            [$userId, $userId, $userId]
        );
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'friends' => $friends]);
        exit;
    }

    private function handleAddFriend(): never
    {
        if (!CSRF::verifyRequest()) { http_response_code(403); echo json_encode(['error' => 'CSRF']); exit; }
        $toId = (int) ($_POST['to_user_id'] ?? 0);
        Connection::getInstance()->execute(
            'INSERT IGNORE INTO friendships (requester_id, addressee_id) VALUES (?, ?)',
            [(int) $this->user['id'], $toId]
        );
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true]);
        exit;
    }

    private function handleRespondFriend(int $friendshipId): never
    {
        if (!CSRF::verifyRequest()) { http_response_code(403); echo json_encode(['error' => 'CSRF']); exit; }
        $status = ($_POST['response'] ?? '') === 'accept' ? 'accepted' : 'declined';
        Connection::getInstance()->execute(
            'UPDATE friendships SET status = ?, updated_at = NOW() WHERE id = ? AND addressee_id = ?',
            [$status, $friendshipId, (int) $this->user['id']]
        );
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true]);
        exit;
    }

    private function handleFindUser(): never
    {
        $username = trim((string) ($_GET['username'] ?? ''));
        $found = null;
        if (mb_strlen($username) >= 3) {
            $found = Connection::getInstance()->fetchOne(
                'SELECT id, username, nick_color FROM users WHERE username = ? AND is_banned = 0 LIMIT 1',
                [$username]
            );
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['user' => $found ?: null]);
        exit;
    }

    private function handleUsernameCheck(): never
    {
        $username = trim((string) ($_GET['username'] ?? ''));
        $available = false;
        if (mb_strlen($username) >= 3 && mb_strlen($username) <= 50) {
            $row = Connection::getInstance()->fetchOne(
                'SELECT id FROM users WHERE username = ? AND id != ?',
                [$username, (int) $this->user['id']]
            );
            $available = $row === null;
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['available' => $available]);
        exit;
    }

}
