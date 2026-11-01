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
                'data' => ['required', 'array'],
                'data.name' => ['required', 'string', 'max:150'],
                'data.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'data.welcome_message' => ['sometimes', 'nullable', 'string', 'max:180'],
                'data.logo_url' => ['sometimes', 'nullable', 'string', new ImageBase64Input],
                'data.background_url' => ['sometimes', 'nullable', 'string', new ImageBase64Input],
                'data.phone' => ['sometimes', 'nullable', 'string', 'max:30'],
                'data.phone_numbers' => ['sometimes', 'array', 'max:10'],
                'data.phone_numbers.*' => ['required', 'string', 'max:30'],
                'data.address' => ['sometimes', 'nullable', 'string', 'max:190'],
            ],
            'categories' => [
                'data' => ['required', 'array'],
                'data.category_ids' => ['required', 'array', 'max:100'],
                'data.category_ids.*' => ['required', 'integer', 'distinct', 'exists:categories,id'],
            ],
            'products' => [
                'data' => ['required', 'array'],
                'data.products' => ['required', 'array', 'max:500'],
                'data.products.*.id' => ['required', 'uuid', 'distinct'],
                'data.products.*.name' => ['required', 'string', 'max:180'],
                'data.products.*.description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'data.products.*.price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
                'data.products.*.category_id' => ['required', 'integer', 'exists:categories,id'],
                'data.products.*.image_url' => ['required', 'string', new OnboardingProductImageInput],
                'data.products.*.position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
                'data.products.*.available' => ['sometimes', 'boolean'],
            ],
            'design' => [
                'data' => ['required', 'array'],
                'data.primary_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'data.secondary_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'data.font_family' => ['sometimes', 'string', 'max:80'],
                'data.menu_style' => ['sometimes', 'string', 'max:40'],
            ],
            default => [],
        };
    }
}
