<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\User;
use App\Rules\ImageBase64Input;
use App\Rules\PhoneNumbersInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edición de un negocio desde el panel de administración.
 *
 * Todos los campos son opcionales (`PATCH` parcial). `slug` admite `null` para
 * regenerarlo a partir del nombre, y `logo_url`/`background_url`/`stamp_icon_url`
 * admiten `null` para eliminar la imagen guardada.
 */
class UpdateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $business = $this->route('business');
        $businessId = $business instanceof Business ? $business->getKey() : $business;

        return [
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],

            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:190',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('businesses', 'slug')->ignore($businessId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'phone_numbers' => ['sometimes', 'nullable', new PhoneNumbersInput],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'website' => ['sometimes', 'nullable', 'url', 'max:190'],
            'address' => ['sometimes', 'nullable', 'string', 'max:190'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['sometimes', 'boolean'],

            'logo_url' => ['sometimes', 'nullable', 'string', new ImageBase64Input],
            'background_url' => ['sometimes', 'nullable', 'string', new ImageBase64Input],
            'stamp_icon_url' => ['sometimes', 'nullable', 'string', new ImageBase64Input],

            'category_ids' => ['sometimes', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],

            'card_settings' => ['sometimes', 'nullable', 'array'],
            'card_settings.primary_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'card_settings.secondary_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'card_settings.text_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'card_settings.required_stamps' => [
                'sometimes',
                'integer',
                'min:'.config('punto_plus.cards.min_required_stamps'),
                'max:'.config('punto_plus.cards.max_required_stamps'),
            ],
            'card_settings.welcome_message' => ['sometimes', 'nullable', 'string', 'max:180'],
            'card_settings.stamp_icon' => ['sometimes', 'string', 'in:star,heart,coffee,leaf,bolt,crown,circle'],
            'card_settings.card_shape' => ['sometimes', 'string', 'in:rounded,square,pill'],
        ];
    }

    /**
     * Si se reasigna el negocio, el nuevo propietario debe poder serlo.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('user_id')) {
                return;
            }

            $owner = User::query()->find((int) $this->input('user_id'));
            $business = $this->route('business');
            $businessId = $business instanceof Business ? $business->getKey() : $business;

            if ($owner === null) {
                return;
            }

            if (! $owner->hasRole(UserRole::Negocio)) {
                $validator->errors()->add('user_id', 'El usuario indicado debe tener el rol de negocio.');
            }

            if ($owner->business()->whereKeyNot($businessId)->exists()) {
                $validator->errors()->add('user_id', 'El usuario indicado ya tiene otro negocio asociado.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->country)) {
            $this->merge(['country' => mb_strtoupper($this->country)]);
        }

        if (is_string($this->slug) && trim($this->slug) !== '') {
            $this->merge(['slug' => Str::slug($this->slug)]);
        }
    }
}
