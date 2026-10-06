<?php

namespace App\Http\Requests\CustomerCard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta en un programa de fidelidad. El cliente escanea el QR del negocio, que
 * contiene el `join_code` (o el payload completo del QR, que la API normaliza).
 */
class JoinLoyaltyCardRequest extends FormRequest
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
            'code' => ['required_without:loyalty_card_id', 'nullable', 'string', 'max:255'],
            'loyalty_card_id' => ['required_without:code', 'nullable', 'uuid', 'exists:loyalty_cards,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required_without' => 'Escanea el QR del negocio o indica el identificador de la tarjeta.',
        ];
    }
}
