<?php
declare(strict_types=1);

namespace Chat\Support;

/**
 * Простая локализация серверного ядра.
 * Last updated: 2026-04-17.
 */
final class Lang
{
    /** @var array<string, string> */
    private static array $messages = [];

    /**
     * Загружает словарь текущей локали.
     * Last updated: 2026-04-17.
     */
    public static function init(string $locale): void
    {
        if (self::$messages !== []) {
            return;
        }

        $path = __DIR__ . '/../../config/lang/' . $locale . '.php';
        if (!is_file($path)) {
            $path = __DIR__ . '/../../config/lang/ru.php';
        }

        $messages = require $path;
        self::$messages = is_array($messages) ? $messages : [];
    }

    /**
     * Возвращает перевод по ключу или сам ключ, если перевод не найден.
     * Last updated: 2026-04-17.
     *
     * @param array<string, string|int|float> $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $value = self::$messages[$key] ?? $key;
        if ($replace === []) {
            return $value;
        }

        $pairs = [];
        foreach ($replace as $k => $v) {
            $pairs['{' . $k . '}'] = (string) $v;
        }
        return strtr($value, $pairs);
    }
}
