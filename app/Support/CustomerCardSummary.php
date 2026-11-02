<?php

namespace App\Support;

use App\Models\CustomerCard;

/**
 * Resumen del progreso de una tarjeta que acompaña a las respuestas de venta
 * (`POST /api/business/stamps/scan` y `POST /api/customer/validate-token`).
 */
final class CustomerCardSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function for(CustomerCard $card): array
    {
        return [
            'id' => $card->getKey(),
            'code' => $card->code,
            'progress_unit' => $card->progressUnit(),
            'stamps_count' => (int) $card->stamps_count,
            'required_stamps' => (int) $card->required_stamps,
            'points_count' => (int) $card->points_count,
            'total_points_earned' => (int) $card->total_points_earned,
            'progress_percentage' => $card->progress_percentage,
            'progress' => $card->progress,
            'status' => $card->status->value,
            'reward_available' => $card->isSpend()
                ? $card->available_rewards->isNotEmpty()
                : $card->isCompleted(),
        ];
    }
}
