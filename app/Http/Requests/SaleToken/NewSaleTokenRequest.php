<?php

namespace App\Http\Requests\SaleToken;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Generación de un token de venta (`GET /api/business/new-token`).
 *
 * Todos los parámetros van en la query string porque el endpoint es un `GET`:
 *  - `loyalty_card_id`: tarjeta a la que aplica (obligatorio si el negocio tiene
 *    más de una tarjeta activa);
 *  - `purchase_amount`: monto de la venta (obligatorio para tarjetas por consumo,
 *    porque los puntos dependen del importe);
 *  - `notes`: nota interna opcional.
 */
class NewSaleTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'loyalty_card_id' => ['nullable', 'uuid'],
            'purchase_amount' => ['nullable', 'numeric', 'gt:0', 'max:999999.99'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'loyalty_card_id.uuid' => 'La tarjeta indicada no es válida.',
            'purchase_amount.numeric' => 'El monto de la venta debe ser un número.',
            'purchase_amount.gt' => 'El monto de la venta debe ser mayor que cero.',
            'notes.max' => 'La nota no puede superar los 255 caracteres.',
        ];
    }
}
