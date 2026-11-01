<?php

namespace App\Support;

/**
 * Normalización de teléfonos al formato E.164 (`+` y 8–15 dígitos).
 *
 * WhatsApp Cloud API exige el número completo con lada de país, sin espacios
 * ni guiones. Para los números nacionales de 10 dígitos se antepone
 * `whatsapp.default_country_code`; cualquier otro formato debe venir ya en
 * E.164 para evitar ambigüedades.
 */
final class PhoneNumber
{
    public const MIN_DIGITS = 8;

    public const MAX_DIGITS = 15;

    /**
     * Devuelve el número en E.164 o null si no se puede interpretar.
     */
    public static function normalize(?string $raw, ?string $defaultCountryCode = null): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $hasPlus = str_starts_with($raw, '+') || str_starts_with($raw, '＋');
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        if (! $hasPlus && str_starts_with($digits, '00')) {
            // Prefijo internacional marcado con 00 (uso europeo/latinoamericano).
            $digits = substr($digits, 2);
        } elseif (! $hasPlus) {
            $code = (string) preg_replace('/\D+/', '', (string) $defaultCountryCode);

            // Sólo se completa la lada cuando el número parece nacional (10 dígitos).
            if ($code !== '' && strlen($digits) === 10 && ! str_starts_with($digits, $code)) {
                $digits = $code.$digits;
            }
        }

        return self::isValid('+'.$digits) ? '+'.$digits : null;
    }

    public static function isValid(?string $e164): bool
    {
        return is_string($e164)
            && preg_match('/^\+[1-9]\d{'.(self::MIN_DIGITS - 1).','.(self::MAX_DIGITS - 1).'}$/', $e164) === 1;
    }

    /**
     * Variante sólo con dígitos (la Cloud API acepta ambas, pero es la que se
     * usa para mostrar/comparar).
     */
    public static function toDigits(?string $e164): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $e164);

        return filled($digits) ? (string) $digits : null;
    }
}
