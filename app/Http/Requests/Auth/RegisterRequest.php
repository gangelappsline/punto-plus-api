<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', Rule::unique(User::class, 'email')],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique(User::class, 'phone')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in($this->assignableRoles())],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', Rule::in(array_keys(config('punto_plus.scopes')))],
            'device_name' => ['nullable', 'string', 'max:120'],

            // Datos del negocio (obligatorios si role = negocio)
            'business' => ['required_if:role,negocio', 'array'],
            'business.name' => ['required_if:role,negocio', 'string', 'max:150'],
            'business.description' => ['nullable', 'string', 'max:1000'],
            'business.category' => ['nullable', 'string', 'max:80'],
            'business.phone' => ['nullable', 'string', 'max:30'],
            'business.email' => ['nullable', 'email', 'max:190'],
            'business.website' => ['nullable', 'url', 'max:190'],
            'business.address' => ['nullable', 'string', 'max:190'],
            'business.city' => ['nullable', 'string', 'max:100'],
            'business.country' => ['nullable', 'string', 'size:2'],
            'business.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'business.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'El rol debe ser cliente o negocio.',
            'business.required_if' => 'Debes indicar los datos del negocio para registrarte como negocio.',
            'business.name.required_if' => 'El nombre del negocio es obligatorio.',
        ];
    }

    protected function prepareForValidation(): void
    {
        /** @var array<string, mixed> $business */
        $business = (array) $this->input('business', []);

        if (isset($business['country']) && is_string($business['country'])) {
            $business['country'] = mb_strtoupper(trim($business['country']));
        }

        if (isset($business['email']) && is_string($business['email'])) {
            $business['email'] = mb_strtolower(trim($business['email']));
        }

        if (isset($business['name']) && is_string($business['name'])) {
            $business['name'] = trim($business['name']);
        }

        $this->merge([
            'email' => is_string($this->email) ? mb_strtolower(trim($this->email)) : $this->email,
            'phone' => is_string($this->phone) ? trim($this->phone) : $this->phone,
            'business' => $business,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function assignableRoles(): array
    {
        return array_map(fn (UserRole $role): string => $role->value, UserRole::selfAssignable());
    }
}
