<?php

namespace App\Http\Requests\Business;

use App\Rules\ImageBase64Input;
use App\Rules\OnboardingProductImageInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBusinessOnboardingStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'step' => ['required', 'string', Rule::in(['information', 'categories', 'products', 'design'])],
        ];

        return $rules + match ($this->input('step')) {
            'information' => [
                'information' => ['required', 'array'],
                'information.name' => ['required', 'string', 'max:150'],
                'information.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'information.welcome_message' => ['sometimes', 'nullable', 'string', 'max:180'],
                'information.logo_url' => ['sometimes', 'nullable', 'string', new ImageBase64Input],
                'information.background_url' => ['sometimes', 'nullable', 'string', new ImageBase64Input],
                'information.phone' => ['sometimes', 'nullable', 'string', 'max:30'],
                'information.phone_numbers' => ['sometimes', 'array', 'max:10'],
                'information.phone_numbers.*' => ['required', 'string', 'max:30'],
                'information.address' => ['sometimes', 'nullable', 'string', 'max:190'],
            ],
            'categories' => [
                'categories' => ['required', 'array'],
                'categories.category_ids' => ['required', 'array', 'max:100'],
                'categories.category_ids.*' => ['required', 'integer', 'distinct', 'exists:categories,id'],
            ],
            'products' => [
                'products' => ['required', 'array'],
                'products.products' => ['required', 'array', 'max:500'],
                'products.products.*.id' => ['required', 'uuid', 'distinct'],
                'products.products.*.name' => ['required', 'string', 'max:180'],
                'products.products.*.description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'products.products.*.price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
                'products.products.*.category_id' => ['required', 'integer', 'exists:categories,id'],
                'products.products.*.image_url' => ['required', 'string', new OnboardingProductImageInput],
                'products.products.*.position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
                'products.products.*.available' => ['sometimes', 'boolean'],
            ],
            'design' => [
                'design' => ['required', 'array'],
                'design.primary_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'design.secondary_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'design.font_family' => ['sometimes', 'string', 'max:80'],
                'design.menu_style' => ['sometimes', 'string', 'max:40'],
            ],
            default => [],
        };
    }

    protected function prepareForValidation(): void
    {
        $data = $this->input('data');
        if (! is_array($data)) {
            return;
        }

        $section = match ($this->input('step')) {
            'information' => $data['information'] ?? $data,
            'categories' => $data['categories'] ?? $data,
            'products' => [
                'products' => is_array($data['products'] ?? null)
                    && array_key_exists('products', $data['products'])
                    ? $data['products']['products']
                    : ($data['products'] ?? null),
            ],
            'design' => $data['design'] ?? $data,
            default => null,
        };

        if (is_array($section)) {
            $field = match ($this->input('step')) {
                'information' => 'information',
                'categories' => 'categories',
                'products' => 'products',
                'design' => 'design',
                default => null,
            };

            if ($field !== null) {
                $this->merge([$field => $section]);
            }
        }
    }
}
