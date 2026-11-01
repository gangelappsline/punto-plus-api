<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Rules\ImageBase64Input;
use App\Rules\PhoneNumbersInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta de un negocio desde el panel de administración.
 *
 * El negocio necesita un propietario: se indica con `user_id` (usuario con rol
 * `business` que todavía no tiene negocio) o con `owner` (se crea el usuario).
 */
class StoreBusinessRequest extends FormRequest
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
        return [
            // Propietario: existente o nuevo.
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'required_without:owner'],
            'owner' => ['nullable', 'array', 'required_without:user_id', 'prohibits:user_id'],
            'owner.name' => ['required_with:owner', 'string', 'max:150'],
            'owner.email' => ['required_with:owner', 'email', 'max:190', Rule::unique('users', 'email')],
            'owner.phone' => ['nullable', 'string', 'max:30'],
            'owner.password' => ['required_with:owner', 'string', 'min:8', 'max:190'],
            'owner.is_active' => ['sometimes', 'boolean'],

            // Datos del negocio.
            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                'nullable',
                'string',
                'max:190',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('businesses', 'slug'),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:30'],
            'phone_numbers' => ['nullable', new PhoneNumbersInput],
            'email' => ['nullable', 'email', 'max:190'],
            'website' => ['nullable', 'url', 'max:190'],
            'address' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['sometimes', 'boolean'],

            // Branding (base64 o data URI; se guardan en el disco público).
            'logo_url' => ['nullable', 'string', new ImageBase64Input],
            'background_url' => ['nullable', 'string', new ImageBase64Input],
            'stamp_icon_url' => ['nullable', 'string', new ImageBase64Input],

            // Categorías globales del negocio.
            'category_ids' => ['sometimes', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],

            // Configuración por defecto de las tarjetas.
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
     * El propietario existente debe tener rol de negocio y no tener ya uno.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('user_id')) {
                return;
            }

            $owner = User::query()->find((int) $this->input('user_id'));

            if ($owner === null) {
                return;
            }

            if (! $owner->hasRole(UserRole::Negocio)) {
                $validator->errors()->add('user_id', 'El usuario indicado debe tener el rol de negocio.');
            }

            if ($owner->business()->exists()) {
                $validator->errors()->add('user_id', 'El usuario indicado ya tiene un negocio asociado.');
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
