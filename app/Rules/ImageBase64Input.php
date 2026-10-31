<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Valida imágenes JPEG, PNG o WEBP codificadas como base64 o data URI. */
class ImageBase64Input implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $fail('La imagen debe enviarse como una cadena base64.');

            return;
        }

        $maxKilobytes = (int) config('punto_plus.assets.max_kilobytes', 4096);
        $maxBytes = $maxKilobytes * 1024;
        $maxEncodedLength = (int) ceil($maxBytes / 3) * 4 + 64;

        if (strlen($value) > $maxEncodedLength) {
            $fail('La imagen no puede superar los '.$maxKilobytes.' KB.');

            return;
        }

        $declaredMime = null;
        $encoded = $value;

        if (str_starts_with($value, 'data:')) {
            if (! preg_match('/\Adata:(image\/(?:jpeg|png|webp));base64,([A-Za-z0-9+\/]*={0,2})\z/', $value, $matches)) {
                $fail('La imagen debe ser un data URI JPEG, PNG o WEBP codificado en base64.');

                return;
            }

            $declaredMime = $matches[1];
            $encoded = $matches[2];
        } elseif (! preg_match('/\A[A-Za-z0-9+\/]*={0,2}\z/', $encoded)) {
            $fail('La imagen debe ser una cadena base64 válida.');

            return;
        }

        $contents = base64_decode($encoded, true);

        if ($contents === false || $contents === '' || strlen($contents) > $maxBytes) {
            $fail('La imagen debe ser base64 válida y no superar los '.$maxKilobytes.' KB.');

            return;
        }

        $image = @getimagesizefromstring($contents);
        $actualMime = is_array($image) ? ($image['mime'] ?? null) : null;
        $allowedMimes = config('punto_plus.assets.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp']);

        if (! is_array($image) || ! in_array($actualMime, $allowedMimes, true) || ($declaredMime !== null && $actualMime !== $declaredMime)) {
            $fail('El contenido debe ser una imagen JPEG, PNG o WEBP válida y coincidir con el formato declarado.');
        }
    }
}
