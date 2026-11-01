<?php

namespace App\Rules;

use App\Support\ImageInput;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Acepta una imagen nueva en base64/data URI o una URL de imagen ya guardada. */
class OnboardingProductImageInput implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('La imagen debe ser una URL existente o una imagen codificada en base64.');

            return;
        }

        $url = ImageInput::normalizeMarkdownLink($value);
        if (ImageInput::isHttpUrl($url)) {
            return;
        }

        (new ImageBase64Input)->validate($attribute, $value, $fail);
    }
}
