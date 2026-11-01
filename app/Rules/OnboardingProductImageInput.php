<?php

namespace App\Rules;

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

        $url = $this->extractUrl($value);
        $parts = is_string($url) ? parse_url($url) : false;

        if (
            is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && filled($parts['host'] ?? null)
        ) {
            return;
        }

        (new ImageBase64Input)->validate($attribute, $value, $fail);
    }

    private function extractUrl(string $value): ?string
    {
        if (preg_match('/\A\[(https?:\/\/[^\]]+)\]\((https?:\/\/[^)]+)\)\z/', $value, $matches)) {
            return $matches[2];
        }

        return $value;
    }
}
