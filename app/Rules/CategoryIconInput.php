<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Acepta una ruta de icono ya existente o un data URI de imagen para guardarlo
 * en el disco público de categorías.
 */
class CategoryIconInput implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $fail('El icono debe ser una ruta o una imagen codificada en base64.');

            return;
        }

        if (! str_starts_with($value, 'data:')) {
            if (mb_strlen($value) > 255) {
                $fail('La ruta del icono no puede superar los 255 caracteres.');
            }

            return;
        }

        $maxBytes = (int) config('punto_plus.assets.max_kilobytes', 4096) * 1024;
        $maxEncodedLength = (int) ceil($maxBytes / 3) * 4 + 64;

        if (strlen($value) > $maxEncodedLength) {
            $fail('La imagen no puede superar los '.(int) config('punto_plus.assets.max_kilobytes', 4096).' KB.');

            return;
        }

        if (! preg_match('/\Adata:(image\/(?:jpeg|png|webp));base64,([A-Za-z0-9+\/]*={0,2})\z/', $value, $matches)) {
            $fail('El icono debe ser un data URI de imagen JPEG, PNG o WEBP válido.');

            return;
        }

        $contents = base64_decode($matches[2], true);

        if ($contents === false || strlen($contents) > $maxBytes) {
            $fail('La imagen no puede superar los '.(int) config('punto_plus.assets.max_kilobytes', 4096).' KB.');

            return;
        }

        $image = @getimagesizefromstring($contents);
        $allowedMimes = config('punto_plus.assets.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp']);

        if ($image === false || ! in_array($image['mime'] ?? null, $allowedMimes, true) || $image['mime'] !== $matches[1]) {
            $fail('El contenido del icono debe ser una imagen JPEG, PNG o WEBP válida y coincidir con su formato declarado.');
        }
    }
}
