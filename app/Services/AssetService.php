<?php

namespace App\Services;

use App\Support\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Guarda el icono de una categoría en el disco público, independientemente
     * del disco configurado para los assets privados de negocio.
     */
    public function storeCategoryIcon(UploadedFile $file, string $categoryId): string
    {
        return $file->store('categories/'.$categoryId, ['disk' => 'public']);
    }

    /**
     * Reemplaza el icono de categoría en el disco público.
     */
    public function replaceCategoryIcon(?string $currentPath, UploadedFile $file, string $categoryId): string
    {
        $path = $this->storeCategoryIcon($file, $categoryId);

        if ($currentPath !== null && $currentPath !== $path && ! str_starts_with($currentPath, 'http')) {
            Storage::disk('public')->delete($currentPath);
        }

        return $path;
    }

    /**
     * Elimina un icono del disco público.
     */
    public function deleteCategoryIcon(?string $path): void
    {
        if (blank($path) || str_starts_with($path, 'http')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
