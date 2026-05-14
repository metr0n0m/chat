<?php
declare(strict_types=1);

namespace Chat\Validation;

final class UsernameRules
{
    public const PATTERN = '/^[a-zA-Zа-яёА-ЯЁ0-9_\-\.]+$/u';
    public const MIN     = 3;
    public const MAX     = 25;

    private function __construct() {}

    public static function validate(string $username): ?string
    {
        $username = trim($username);
        if (mb_strlen($username) < self::MIN || mb_strlen($username) > self::MAX) {
            return 'Имя пользователя: от ' . self::MIN . ' до ' . self::MAX . ' символов.';
        }
        if (!preg_match(self::PATTERN, $username)) {
            return 'Имя пользователя: только буквы (рус/лат), цифры, _ - .';
        }
        return null;
    }
}
