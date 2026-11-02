<?php

namespace App\Http\Requests\SaleToken;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de un token de venta (`POST /api/customer/validate-token`).
 *
 * El campo canónico es **`token`** (es lo que envía la app del cliente): acepta el
 * código tecleado (`K7QM2XPD`, `k7qm-2xpd`) o el contenido del QR (JSON con `code`,
 * deep link o URL con `?code=`). `code` se mantiene como alias de compatibilidad.
 */
class ValidateSaleTokenRequest extends FormRequest
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
            'token' => ['required_without:code', 'nullable', 'string', 'max:500'],
            // Alias histórico: se acepta si el cliente envía el payload antiguo.
            'code' => ['sometimes', 'nullable', 'string', 'max:500'],
            'purchase_amount' => ['nullable', 'numeric', 'gt:0', 'max:999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required_without' => 'Escanea el QR del negocio o escribe el código del token.',
            'token.string' => 'El token debe ser texto.',
            'token.max' => 'El token enviado es demasiado largo.',
            'code.string' => 'El token debe ser texto.',
            'code.max' => 'El token enviado es demasiado largo.',
            'purchase_amount.numeric' => 'El monto de la compra debe ser un número.',
            'purchase_amount.gt' => 'El monto de la compra debe ser mayor que cero.',
        ];
    }

    /**
     * Valor a validar: `token` (canónico) o `code` (alias).
     */
    public function saleToken(): string
    {
        $value = $this->input('token');

        if (! is_string($value) || trim($value) === '') {
            $value = $this->input('code');
        }

        return is_string($value) ? $value : '';
    }
}
