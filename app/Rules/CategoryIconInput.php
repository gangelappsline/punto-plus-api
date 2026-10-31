<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Valida un data URI de imagen o una cadena base64 sin prefijo. */
class CategoryIconInput implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $fail('El icono debe enviarse como una cadena base64.');

            return;
        }

        $maxBytes = (int) config('punto_plus.assets.max_kilobytes', 4096) * 1024;
        $maxEncodedLength = (int) ceil($maxBytes / 3) * 4 + 64;

        if (strlen($value) > $maxEncodedLength) {
            $fail('La imagen no puede superar los '.(int) config('punto_plus.assets.max_kilobytes', 4096).' KB.');

            return;
        }

        $declaredMime = null;
        $encoded = $value;

        if (str_starts_with($value, 'data:')) {
            if (! preg_match('/\Adata:(image\/(?:jpeg|png|webp));base64,([A-Za-z0-9+\/]*={0,2})\z/', $value, $matches)) {
                $fail('El icono debe ser un data URI JPEG, PNG o WEBP codificado en base64.');

                return;
            }

            $declaredMime = $matches[1];
            $encoded = $matches[2];
        } elseif (! preg_match('/\A[A-Za-z0-9+\/]*={0,2}\z/', $encoded)) {
            $fail('El icono debe ser una cadena base64 válida.');

            return;
        }

        $contents = base64_decode($encoded, true);

        if ($contents === false || $contents === '' || strlen($contents) > $maxBytes) {
            $fail('El icono debe ser una imagen base64 válida y no superar los '.(int) config('punto_plus.assets.max_kilobytes', 4096).' KB.');

            return;
        }

        $image = @getimagesizefromstring($contents);
        $allowedMimes = config('punto_plus.assets.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp']);
        $actualMime = is_array($image) ? ($image['mime'] ?? null) : null;

        if (! is_array($image) || ! in_array($actualMime, $allowedMimes, true) || ($declaredMime !== null && $actualMime !== $declaredMime)) {
            $fail('El contenido debe ser una imagen JPEG, PNG o WEBP válida y coincidir con el formato declarado.');
        }
    }
}
