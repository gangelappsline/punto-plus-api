<?php

namespace App\Services;

use App\Support\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Guarda y reemplaza los assets de marca (logo, fondo y sello) en el disco
 * configurado en `punto_plus.assets.disk` y los iconos de categoría en `public`.
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

    /** Directorio de marca de un negocio. */
    public function businessDirectory(string $businessId): string
    {
        return 'businesses/'.$businessId.'/branding';
    }

    /** Directorio de personalización de una tarjeta. */
    public function loyaltyCardDirectory(string $loyaltyCardId): string
    {
        return 'loyalty-cards/'.$loyaltyCardId;
    }

    /**
     * Decodifica y guarda una cadena base64 o data URI de imagen en el disco público.
     */
    public function storeCategoryIconBase64(string $encodedImage, string $categoryId): string
    {
        $declaredMime = null;
        $encoded = $encodedImage;

        if (str_starts_with($encodedImage, 'data:')) {
            if (! preg_match('/\Adata:(image\/(?:jpeg|png|webp));base64,([A-Za-z0-9+\/]*={0,2})\z/', $encodedImage, $matches)) {
                throw new InvalidArgumentException('El data URI del icono no es válido.');
            }

            $declaredMime = $matches[1];
            $encoded = $matches[2];
        } elseif (! preg_match('/\A[A-Za-z0-9+\/]*={0,2}\z/', $encoded)) {
            throw new InvalidArgumentException('El icono no es una cadena base64 válida.');
        }

        $contents = base64_decode($encoded, true);
        $image = $contents === false ? false : @getimagesizefromstring($contents);
        $mime = is_array($image) ? ($image['mime'] ?? null) : null;
        $maxBytes = (int) config('punto_plus.assets.max_kilobytes', 4096) * 1024;

        if (
            $contents === false
            || $contents === ''
            || strlen($contents) > $maxBytes
            || $image === false
            || ! in_array($mime, config('punto_plus.assets.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp']), true)
            || ($declaredMime !== null && $mime !== $declaredMime)
        ) {
            throw new InvalidArgumentException('El icono debe contener una imagen JPEG, PNG o WEBP válida dentro del límite permitido.');
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new InvalidArgumentException('Formato de icono no permitido.'),
        };

        $path = 'categories/'.$categoryId.'/'.Str::uuid().'.'.$extension;
        Storage::disk('public')->put($path, $contents);

        return $path;
    }

    /** Reemplaza el icono de categoría por una nueva cadena base64. */
    public function replaceCategoryIconBase64(?string $currentPath, string $encodedImage, string $categoryId): string
    {
        $path = $this->storeCategoryIconBase64($encodedImage, $categoryId);
        $this->deleteCategoryIcon($currentPath);

        return $path;
    }

    /** Elimina un icono del disco público. */
    public function deleteCategoryIcon(?string $path): void
    {
        if (blank($path) || str_starts_with($path, 'http')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
