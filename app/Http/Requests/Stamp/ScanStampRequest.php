<?php

namespace App\Http\Requests\Stamp;

use App\Enums\StampSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El negocio escanea el QR del cliente (que contiene `code`) y registra el sello.
 */
class ScanStampRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:255'],
            'purchase_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'notes' => ['nullable', 'string', 'max:255'],
            'loyalty_card_id' => ['nullable', 'uuid', 'exists:loyalty_cards,id'],
            'stamped_at' => ['nullable', 'date', 'before_or_equal:now', 'after:-30 days'],
            'source' => ['nullable', Rule::in([StampSource::Scan->value, StampSource::Manual->value])],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Debes escanear (o teclear) el código de la tarjeta del cliente.',
        ];
    }
}
