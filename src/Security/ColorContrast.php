<?php
declare(strict_types=1);

namespace Chat\Security;

class ColorContrast
{
    private const LIGHT_BG = '#f8f9fa';
    private const DARK_BG  = '#212529';

    private static function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $linearize = static fn(float $c): float =>
            $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $linearize($r)
             + 0.7152 * $linearize($g)
             + 0.0722 * $linearize($b);
    }

    public static function contrastRatio(string $hex1, string $hex2): float
    {
        $l1 = self::relativeLuminance($hex1);
        $l2 = self::relativeLuminance($hex2);
        $lighter = max($l1, $l2);
        $darker  = min($l1, $l2);
        return round(($lighter + 0.05) / ($darker + 0.05), 2);
    }

    public static function isValidHex(string $hex): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $hex);
    }

    public static function validate(string $hex): ?string
    {
        if (!self::isValidHex($hex)) {
            return 'Недопустимый формат цвета.';
        }

        $contrastLight = self::contrastRatio($hex, self::LIGHT_BG);
        $contrastDark  = self::contrastRatio($hex, self::DARK_BG);

        if ($contrastLight < 2.0) {
            return 'Цвет плохо виден на светлой теме. Выберите другой.';
        }
        if ($contrastDark < 2.0) {
            return 'Цвет плохо виден на тёмной теме. Выберите другой.';
        }
        if ($contrastLight < 4.5 && $contrastDark < 4.5) {
            return 'Цвет не достигает минимального контраста ни на одной из тем. Выберите другой.';
        }

        return null;
    }

    public static function ratios(string $hex): array
    {
        return [
            'light' => self::contrastRatio($hex, self::LIGHT_BG),
            'dark'  => self::contrastRatio($hex, self::DARK_BG),
        ];
    }
}
