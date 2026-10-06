<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Resolución de rutas de archivos (logo, fondo, sello) a URLs públicas.
 */
final class Media
{
    public static function disk(): string
    {
        return (string) config('punto_plus.assets.disk', 'public');
    }

    public static function url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk(self::disk())->url($path);
    }

    public static function delete(?string $path): void
    {
        if (blank($path) || str_starts_with($path, 'http')) {
            return;
        }

        $disk = Storage::disk(self::disk());

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
