<?php

namespace App\Services;

use App\Support\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Gestiona assets privados y recursos de imagen públicos.
 */
final class AssetService
{
    public function store(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, ['disk' => Media::disk()]);
    }

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

    public function businessDirectory(string $businessId): string
    {
        return 'businesses/'.$businessId.'/branding';
    }

    public function loyaltyCardDirectory(string $loyaltyCardId): string
    {
        return 'loyalty-cards/'.$loyaltyCardId;
    }

    /**
     * Carpeta pública donde vive el banner de una promoción.
     */
    public function promotionBannerDirectory(string $promotionId): string
    {
        return 'promotions/'.$promotionId.'/banner';
    }

    /**
     * Decodifica una imagen base64 y la guarda en una carpeta del disco público.
     */
    public function storePublicBase64Image(string $encodedImage, string $directory): string
    {
        $declaredMime = null;
        $encoded = $encodedImage;

        if (str_starts_with($encodedImage, 'data:')) {
            if (! preg_match('/\Adata:(image\/(?:jpeg|png|webp));base64,([A-Za-z0-9+\/]*={0,2})\z/', $encodedImage, $matches)) {
                throw new InvalidArgumentException('El data URI de la imagen no es válido.');
            }

            $declaredMime = $matches[1];
            $encoded = $matches[2];
        } elseif (! preg_match('/\A[A-Za-z0-9+\/]*={0,2}\z/', $encoded)) {
            throw new InvalidArgumentException('La imagen no es una cadena base64 válida.');
        }

        $contents = base64_decode($encoded, true);
        $image = $contents === false ? false : @getimagesizefromstring($contents);
        $mime = is_array($image) ? ($image['mime'] ?? null) : null;
        $maxBytes = (int) config('punto_plus.assets.max_kilobytes', 4096) * 1024;
        $allowedMimes = config('punto_plus.assets.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp']);

        if (
            $contents === false
            || $contents === ''
            || strlen($contents) > $maxBytes
            || ! is_array($image)
            || ! in_array($mime, $allowedMimes, true)
            || ($declaredMime !== null && $mime !== $declaredMime)
        ) {
            throw new InvalidArgumentException('La imagen debe ser JPEG, PNG o WEBP válida y estar dentro del límite permitido.');
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new InvalidArgumentException('Formato de imagen no permitido.'),
        };

        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension;

        if (! Storage::disk('public')->put($path, $contents)) {
            throw new RuntimeException('No fue posible guardar la imagen en el almacenamiento público.');
        }

        return $path;
    }

    public function storeCategoryIconBase64(string $encodedImage, string $categoryId): string
    {
        return $this->storePublicBase64Image($encodedImage, 'categories/'.$categoryId);
    }

    public function replaceCategoryIconBase64(?string $currentPath, string $encodedImage, string $categoryId): string
    {
        $path = $this->storeCategoryIconBase64($encodedImage, $categoryId);
        $this->deletePublicAsset($currentPath);

        return $path;
    }

    public function deleteCategoryIcon(?string $path): void
    {
        $this->deletePublicAsset($path);
    }

    public function deletePublicAsset(?string $path): void
    {
        if (blank($path) || str_starts_with($path, 'http')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
