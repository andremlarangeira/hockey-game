<?php

namespace App\Support;

class TeamColors
{
    /** @return array{0: float, 1: float, 2: float} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)) / 255, hexdec(substr($hex, 3, 2)) / 255, hexdec(substr($hex, 5, 2)) / 255];
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
        return self::contrast($background, '#ffffff') >= 4.5 ? '#ffffff' : '#0f172a';
    }

    public static function adjustForLightText(string $background): string
    {
        $candidate = $background;
        for ($i = 0; $i < 20 && self::contrast($candidate, '#ffffff') < 4.5; $i++)
        {
            [$r, $g, $b] = self::rgb($candidate);
            $candidate = sprintf('#%02x%02x%02x', (int) round($r * 0.84 * 255), (int) round($g * 0.84 * 255), (int) round($b * 0.84 * 255));
        }

        return self::contrast($candidate, '#ffffff') >= 4.5 ? $candidate : '#0f172a';
    }
}
