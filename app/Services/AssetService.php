<?php

namespace App\Services;

use App\Support\Media;
use Illuminate\Http\UploadedFile;

/**
 * Guarda y reemplaza los assets de marca (logo, fondo, sello) en el disco
 * configurado en `punto_plus.assets.disk`.
 */
final class AssetService
{
    /**
     * Guarda el archivo y devuelve la ruta relativa en el disco.
     */
    public function store(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, ['disk' => Media::disk()]);
    }

    /**
     * Reemplaza un asset previo, borrando el anterior para no acumular basura.
     */
    public function replace(?string $currentPath, UploadedFile $file, string $directory): string
    {
        $path = $this->store($file, $directory);

        if ($currentPath !== null && $currentPath !== $path) {
            Media::delete($currentPath);
        }

        return $path;
    }

    public function delete(?string $path): void
    {
        Media::delete($path);
    }

    /**
     * Directorio de marca de un negocio.
     */
    public function businessDirectory(string $businessId): string
    {
        return 'businesses/'.$businessId.'/branding';
    }

    /**
     * Directorio de personalización de una tarjeta.
     */
    public function loyaltyCardDirectory(string $loyaltyCardId): string
    {
        return 'loyalty-cards/'.$loyaltyCardId;
    }
}
