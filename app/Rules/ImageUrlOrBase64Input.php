<?php

namespace App\Rules;

use App\Support\ImageInput;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Valida imágenes como URL HTTP(S), data URI o base64. */
class ImageUrlOrBase64Input implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $fail('La imagen debe enviarse como una URL o una cadena base64.');

            return;
        }

        $value = ImageInput::normalizeMarkdownLink($value);
        if (ImageInput::isHttpUrl($value)) {
            if (strlen($value) > 255) {
                $fail('La URL de la imagen no puede superar los 255 caracteres.');
            }

            return;
        }

        (new ImageBase64Input)->validate($attribute, $value, $fail);
    }
}
