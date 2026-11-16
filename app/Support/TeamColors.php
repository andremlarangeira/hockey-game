<?php

namespace App\Support;

class TeamColors
{
    public const DEFAULT_PRIMARY = '#2563eb';

    public const DEFAULT_SECONDARY = '#dc2626';

    /** @var list<string> */
    public const DRAW_PALETTE = [
        self::DEFAULT_PRIMARY,
        self::DEFAULT_SECONDARY,
        '#eab308',
        '#16a34a',
        '#9333ea',
        '#ea580c',
        '#0891b2',
        '#db2777',
    ];

    public static function isValidHex(?string $color): bool
    {
        return is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1;
    }

    public static function normalizeHex(?string $color, string $fallback = self::DEFAULT_PRIMARY): string
    {
        return self::isValidHex($color) ? $color : $fallback;
    }

    /**
     * @param  array<int|string, string|null>|string|null  $primaryColor
     * @return list<string>
     */
    public static function drawPalette(array|string|null $primaryColor = null, ?string $secondaryColor = null): array
    {
        if (is_array($primaryColor))
        {
            $rawPrimary = $primaryColor[0] ?? $primaryColor['primary_color'] ?? $primaryColor['primary'] ?? null;
            $rawSecondary = $primaryColor[1] ?? $primaryColor['secondary_color'] ?? $primaryColor['secondary'] ?? $secondaryColor;
        }
        else
        {
            $rawPrimary = $primaryColor;
            $rawSecondary = $secondaryColor;
        }

        $first = self::normalizeHex(is_string($rawPrimary) ? $rawPrimary : null, self::DEFAULT_PRIMARY);
        $second = self::normalizeHex(is_string($rawSecondary) ? $rawSecondary : null, self::DEFAULT_SECONDARY);
        $used = [strtolower($first), strtolower($second)];
        $candidates = [...array_slice(self::DRAW_PALETTE, 2), self::DRAW_PALETTE[0], self::DRAW_PALETTE[1]];
        $rest = array_values(array_filter(
            $candidates,
            fn (string $color): bool => ! in_array(strtolower($color), $used, true),
        ));

        return array_slice([$first, $second, ...$rest], 0, count(self::DRAW_PALETTE));
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function rgb(string $hex): array
    {
        $valid = self::normalizeHex($hex, self::DEFAULT_PRIMARY);

        return [hexdec(substr($valid, 1, 2)) / 255, hexdec(substr($valid, 3, 2)) / 255, hexdec(substr($valid, 5, 2)) / 255];
    }

    private static function contrast(string $a, string $b): float
    {
        $luminance = function (string $color): float {
            $rgb = array_map(fn (float $channel): float => $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4, self::rgb($color));

            return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
        };
        $light = max($luminance($a), $luminance($b));
        $dark = min($luminance($a), $luminance($b));

        return ($light + 0.05) / ($dark + 0.05);
    }

    public static function readableText(string $background): string
    {
        $valid = self::normalizeHex($background, self::DEFAULT_PRIMARY);

        return self::contrast($valid, '#ffffff') >= 4.5 ? '#ffffff' : '#0f172a';
    }

    public static function adjustForLightText(string $background): string
    {
        $candidate = self::normalizeHex($background, self::DEFAULT_PRIMARY);
        for ($i = 0; $i < 20 && self::contrast($candidate, '#ffffff') < 4.5; $i++)
        {
            [$r, $g, $b] = self::rgb($candidate);
            $candidate = sprintf('#%02x%02x%02x', (int) round($r * 0.84 * 255), (int) round($g * 0.84 * 255), (int) round($b * 0.84 * 255));
        }

        return self::contrast($candidate, '#ffffff') >= 4.5 ? $candidate : '#0f172a';
    }
}
