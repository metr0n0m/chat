<?php
declare(strict_types=1);

namespace Chat\Admin;

use Chat\Http\JsonResponse;
use Chat\Moderation\SanctionStats;
use Chat\Moderation\StopWordRules;
use Chat\Moderation\ViolationReporter;
use Chat\Security\CSRF;
use Chat\Security\Session;

/**
 * HTTP-контроллеры вкладки «Санкции» админ-панели — этап S5.
 * Тонкий слой: проверка прав + делегирование в SanctionStats / ViolationReporter.
 *
 * Маршруты под /api/admin уже проходят AdminPanel::requireAdmin() (глобальный
 * админ). Дополнительно:
 *   - журнал событий и статистика — глобальный админ (наследует requireAdmin);
 *   - теневой журнал и управление движком — только platform_owner (requireOwner).
 */
final class SanctionPanel
{
    private static function requireOwner(): array
    {
        $actor = Session::current();
        if (!$actor || !Access::isOwner($actor)) {
            JsonResponse::error('Только владелец платформы.', 403);
        }
        return $actor;
    }

    /** Лента журнала санкций (глобальный админ). */
    public static function events(array $query): void
    {
        JsonResponse::success(SanctionStats::events($query));
    }

    /** Теневой журнал — калибровочные данные (только владелец платформы). */
    public static function shadow(array $query): void
    {
        self::requireOwner();
        JsonResponse::success(SanctionStats::shadow($query));
    }

    /** Сводная статистика по санкциям (глобальный админ). */
    public static function stats(array $query): void
    {
        $days = (int) ($query['days'] ?? 30);
        JsonResponse::success(['stats' => SanctionStats::stats($days)]);
    }

    // ── S5b: управление движком (только владелец платформы) ──────────────────

    /** Текущая конфигурация движка санкций. */
    public static function getConfig(): void
    {
        self::requireOwner();
        JsonResponse::success(['config' => ViolationReporter::rules()]);
    }

    /**
     * Обновление конфигурации движка. Допустимые ключи строго ограничены.
     * Переход в боевой режим (mode=live) требует явного подтверждения confirm=1.
     */
    public static function updateConfig(array $post): void
    {
        self::requireOwner();
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('CSRF.', 403);
        }

        $key = (string) ($post['key'] ?? '');
        $allowed = ['mode', 'autonomy_state', 'circuit_breaker', 'escalation', 'danger_weights', 'danger_thresholds'];
        if (!in_array($key, $allowed, true)) {
            JsonResponse::error('Недопустимый параметр конфигурации.', 400);
        }

        // Скалярные ключи приходят строкой, остальные — JSON-объектом
        if ($key === 'mode') {
            $value = (string) ($post['value'] ?? '');
            if (!in_array($value, ['shadow', 'live'], true)) {
                JsonResponse::error('Режим должен быть shadow или live.', 400);
            }
            // Боевой режим — необратимое влияние на пользователей: подтверждение обязательно
            if ($value === 'live' && (int) ($post['confirm'] ?? 0) !== 1) {
                $shadow = SanctionStats::stats(30)['shadow_30d'];
                JsonResponse::error(
                    'Включение боевого режима требует подтверждения. За 30 дней теневой режим '
                    . 'насчитал ' . $shadow . ' срабатываний — столько санкций выдала бы система. '
                    . 'Передайте confirm=1 для включения.',
                    409
                );
            }
            ViolationReporter::setRule('mode', $value);
        } elseif ($key === 'autonomy_state') {
            $value = (string) ($post['value'] ?? '');
            if (!in_array($value, ['active', 'paused'], true)) {
                JsonResponse::error('Состояние должно быть active или paused.', 400);
            }
            ViolationReporter::setRule('autonomy_state', $value);
        } else {
            $decoded = json_decode((string) ($post['value'] ?? ''), true);
            if (!is_array($decoded)) {
                JsonResponse::error('Значение должно быть корректным JSON-объектом.', 400);
            }
            ViolationReporter::setRule($key, $decoded);
        }

        ViolationReporter::flushRules();
        JsonResponse::success(['config' => ViolationReporter::rules()]);
    }

    /** Возобновление автонома после срабатывания предохранителя. */
    public static function resumeAutonomy(): void
    {
        self::requireOwner();
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('CSRF.', 403);
        }
        ViolationReporter::setRule('autonomy_state', 'active');
        ViolationReporter::flushRules();
        JsonResponse::success(['autonomy_state' => 'active']);
    }

    // ── S5b: стоп-слова ──────────────────────────────────────────────────────

    /** Глобальные стоп-слова (только владелец платформы). */
    public static function globalStopWords(): void
    {
        self::requireOwner();
        JsonResponse::success(['stop_words' => StopWordRules::listGlobal()]);
    }

    /** Добавление глобального стоп-слова (только владелец платформы). */
    public static function addGlobalStopWord(array $post): void
    {
        $actor = self::requireOwner();
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('CSRF.', 403);
        }
        self::respondAdd(StopWordRules::add('global', null, (string) ($post['pattern'] ?? ''), (string) ($post['duration'] ?? '1h'), (int) $actor['id']));
    }

    /**
     * Стоп-слова комнаты (владелец комнаты или глобальный админ).
     * Маршрут вне /api/admin — права проверяются здесь.
     */
    public static function roomStopWords(int $roomId): void
    {
        self::requireRoomManager($roomId);
        JsonResponse::success(['stop_words' => StopWordRules::listRoom($roomId)]);
    }

    /** Добавление стоп-слова комнаты (владелец комнаты или глобальный админ). */
    public static function addRoomStopWord(int $roomId, array $post): void
    {
        $actor = self::requireRoomManager($roomId);
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('CSRF.', 403);
        }
        self::respondAdd(StopWordRules::add('room', $roomId, (string) ($post['pattern'] ?? ''), (string) ($post['duration'] ?? '1h'), (int) $actor['id']));
    }

    /**
     * Удаление стоп-слова. Право зависит от области слова:
     * глобальное — platform_owner, комнатное — владелец этой комнаты.
     */
    public static function removeStopWord(int $id): void
    {
        $actor = Session::current();
        if (!$actor) {
            JsonResponse::error('Доступ запрещён.', 403);
        }
        if (!CSRF::verifyRequest()) {
            JsonResponse::error('CSRF.', 403);
        }
        $word = StopWordRules::find($id);
        if ($word === null) {
            JsonResponse::error('Стоп-слово не найдено.', 404);
        }
        if ($word['scope'] === 'global') {
            if (!Access::isOwner($actor)) {
                JsonResponse::error('Только владелец платформы.', 403);
            }
        } elseif (!Access::canManageRoom($actor, (int) $word['room_id'])) {
            JsonResponse::error('Недостаточно прав для этой комнаты.', 403);
        }
        StopWordRules::remove($id);
        JsonResponse::success(['deleted' => true]);
    }

    private static function requireRoomManager(int $roomId): array
    {
        $actor = Session::current();
        if (!$actor || !Access::canManageRoom($actor, $roomId)) {
            JsonResponse::error('Управлять стоп-словами комнаты может только её владелец.', 403);
        }
        return $actor;
    }

    /** @param array{added: true}|array{error: string, code: int} $result */
    private static function respondAdd(array $result): void
    {
        if (isset($result['error'])) {
            JsonResponse::error($result['error'], (int) $result['code']);
        }
        JsonResponse::success(['added' => true]);
    }
}
