<?php

namespace App\Services;

use App\Models\CustomerCard;
use App\Models\LoyaltyCard;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Construye los payloads de los códigos QR y su representación gráfica.
 *
 * - Alta en un programa (lo escanea el cliente): payload tipo `loyalty_card.join`
 *   con el `join_code` del negocio.
 * - Identificación del cliente (lo escanea el negocio): payload tipo
 *   `customer_card.identify` con el `code` de la tarjeta del cliente.
 *
 * El QR siempre viaja con `payload` (JSON) para que la app pueda regenerarlo
 * offline; `svg` es opcional (requiere bacon/bacon-qr-code).
 */
final class QrCodeService
{
    public const TYPE_JOIN = 'loyalty_card.join';

    public const TYPE_CUSTOMER_CARD = 'customer_card.identify';

    private const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public function forLoyaltyCard(LoyaltyCard $card): array
    {
        return $this->render([
            'v' => self::VERSION,
            'type' => self::TYPE_JOIN,
            'join_code' => $card->join_code,
            'loyalty_card_id' => $card->getKey(),
            'business_id' => $card->business_id,
            'business' => $card->relationLoaded('business') ? $card->business?->name : null,
            'url' => $card->join_url,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forCustomerCard(CustomerCard $card): array
    {
        return $this->render([
            'v' => self::VERSION,
            'type' => self::TYPE_CUSTOMER_CARD,
            'code' => $card->code,
            'loyalty_card_id' => $card->loyalty_card_id,
            'business_id' => $card->business_id,
            'holder' => $card->relationLoaded('user') ? $card->user?->name : null,
            'stamps' => (int) $card->stamps_count,
            'required_stamps' => (int) $card->required_stamps,
        ]);
    }

    /**
     * Normaliza lo que llega desde un lector de QR (texto plano, JSON, deep link
     * o URL con query string) y devuelve el código útil.
     */
    public function extractCode(?string $raw): ?string
    {
        if (blank($raw)) {
            return null;
        }

        $raw = trim($raw);

        if (str_starts_with($raw, '{')) {
            $data = json_decode($raw, true);

            if (is_array($data)) {
                $code = $data['code'] ?? $data['join_code'] ?? null;

                return is_string($code) ? $this->normalize($code) : null;
            }
        }

        if (preg_match('~[?&](?:code|join_code)=([A-Za-z0-9\-]+)~', $raw, $matches) === 1) {
            return $this->normalize($matches[1]);
        }

        if (preg_match('~/(?:join)/([A-Za-z0-9\-]+)/?$~', $raw, $matches) === 1) {
            return $this->normalize($matches[1]);
        }

        return $this->normalize($raw);
    }

    /**
     * SVG del código QR (null si bacon/bacon-qr-code no está instalado).
     */
    public function svg(string $content, int $size = 400): ?string
    {
        if (! class_exists(Writer::class)) {
            return null;
        }

        try {
            $renderer = new ImageRenderer(
                new RendererStyle($size, 1),
                new SvgImageBackEnd,
            );

            return (new Writer($renderer))->writeString($content);
        } catch (Throwable $e) {
            Log::warning('No se pudo generar el SVG del QR', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function render(array $payload): array
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'payload' => $payload,
            'json' => $json,
            'svg' => $this->svg($json),
        ];
    }

    private function normalize(string $value): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $value) ?? '');
    }
}
