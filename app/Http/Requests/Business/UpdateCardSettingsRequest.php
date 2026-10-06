<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Configuración de la tarjeta "por defecto" del negocio: la que se aplica al
 * crear nuevas tarjetas de fidelidad.
 */
class UpdateCardSettingsRequest extends FormRequest
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
            'primary_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'required_stamps' => [
                'sometimes',
                'integer',
                'min:'.config('punto_plus.cards.min_required_stamps'),
                'max:'.config('punto_plus.cards.max_required_stamps'),
            ],
            'welcome_message' => ['sometimes', 'nullable', 'string', 'max:180'],
            'stamp_icon' => ['sometimes', 'string', 'in:star,heart,coffee,leaf,bolt,crown,circle'],
            'card_shape' => ['sometimes', 'string', 'in:rounded,square,pill'],
        ];
    }
}
