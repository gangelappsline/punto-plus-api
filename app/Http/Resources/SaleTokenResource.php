<?php

namespace App\Http\Resources;

use App\Services\QrCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Token de venta.
 *
 * `qr.payload`/`qr.json` es el contenido que el panel debe dibujar como QR y
 * `qr.svg` el SVG listo para mostrar (null si bacon/bacon-qr-code no está
 * instalado). `display_code` es la versión `ABCD-EFGH` para dictarla.
 *
 * @mixin \App\Models\SaleToken
 */
class SaleTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $qr = app(QrCodeService::class)->forSaleToken($this->resource);

        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'loyalty_card_id' => $this->loyalty_card_id,
            'code' => $this->code,
            'display_code' => $this->display_code,
            'purchase_amount' => $this->purchase_amount,
            'notes' => $this->notes,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'expires_in_seconds' => $this->expiresInSeconds(),
            'ttl_minutes' => $this->ttlMinutes(),
            'is_expired' => $this->isExpired(),
            'qr' => $qr,
            'qr_content' => $qr['json'],
            'loyalty_card' => new LoyaltyCardResource($this->whenLoaded('loyaltyCard')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
