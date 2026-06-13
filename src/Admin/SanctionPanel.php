<?php
declare(strict_types=1);

namespace Chat\Admin;

use Chat\Http\JsonResponse;
use Chat\Moderation\SanctionStats;
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
}
