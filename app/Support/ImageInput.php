<?php

namespace App\Support;

final class ImageInput
{
    public static function normalizeMarkdownLink(string $value): string
    {
        if (preg_match('/\A\[[^\]]*\]\((https?:\/\/[^)]+)\)\z/', $value, $matches)) {
            return $matches[1];
        }

        return $value;
    }

    public static function isHttpUrl(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && filled($parts['host'] ?? null);
    }
}
