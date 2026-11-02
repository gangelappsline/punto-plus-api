<?php

namespace App\Http\Requests\SaleToken;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de un token de venta (`POST /api/customer/validate-token`).
 *
 * `code` acepta el código tecleado (`ABCD1234`, `abcd-1234`) o el contenido del
 * QR (JSON con `code`, deep link o URL con `?code=`).
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
            'code' => ['required', 'string', 'max:500'],
            'purchase_amount' => ['nullable', 'numeric', 'gt:0', 'max:999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Escanea el QR del negocio o escribe el código del token.',
            'code.max' => 'El código enviado es demasiado largo.',
            'purchase_amount.numeric' => 'El monto de la compra debe ser un número.',
            'purchase_amount.gt' => 'El monto de la compra debe ser mayor que cero.',
        ];
    }
}
