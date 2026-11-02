<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tarjeta de fidelidad con sus assets (logo, fondo e icono de sello).
 *
 * `type` distingue los dos tipos de programa:
 *  - `visits`: progreso por visitas (`required_stamps` + vigencia opcional);
 *  - `spend`: progreso por puntos (`spend_amount_per_block` → `points_per_block`).
 *
 * @mixin \App\Models\LoyaltyCard
 */
class LoyaltyCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,

            // --- Tipo de programa ---
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'progress_unit' => $this->progressUnit(),
            // Campo de `rewards` que define el umbral según el tipo.
            'reward_threshold_field' => $this->type->rewardThresholdColumn(),

            // --- Configuración por visitas ---
            'required_stamps' => $this->required_stamps === null ? null : (int) $this->required_stamps,

            // --- Configuración por consumo ---
            'currency' => $this->currency,
            'spend_amount_per_block' => $this->spend_amount_per_block === null
                ? null
                : (float) $this->spend_amount_per_block,
            'points_per_block' => $this->points_per_block === null ? null : (int) $this->points_per_block,

            // --- Vigencia del ciclo ---
            'period_type' => $this->period_type->value,
            'period_type_label' => $this->period_type->label(),
            'period_days' => $this->period_days === null ? null : (int) $this->period_days,

            'reward_description' => $this->reward_description,
            'terms' => $this->terms,

            // Personalización
            'logo_url' => $this->logo_url,
            'background_url' => $this->background_url,
            'stamp_icon_url' => $this->stamp_icon_url,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'text_color' => $this->text_color,

            // Alta del programa por QR (código pensado para ser público: va impreso
            // en el cartel del negocio, igual que el QR de una tarjeta física).
            'join_code' => $this->join_code,
            'join_url' => $this->join_url,
            'qr' => $this->when(
                $request->boolean('include_qr'),
                fn (): array => app(\App\Services\QrCodeService::class)->forLoyaltyCard($this->resource),
            ),

            'is_active' => (bool) $this->is_active,
            'is_public' => (bool) $this->is_public,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'settings' => $this->settings ?? (object) [],

            'business' => new BusinessResource($this->whenLoaded('business')),
            'rewards' => RewardResource::collection($this->whenLoaded('rewards')),
            'rewards_count' => $this->whenCounted('rewards'),
            'customers_count' => $this->whenCounted('customerCards'),
            'stamps_count' => $this->whenCounted('stamps'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
