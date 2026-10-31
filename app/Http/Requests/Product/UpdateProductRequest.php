<?php

namespace App\Http\Requests\Product;

use App\Rules\ImageBase64Input;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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
            'menu_category_id' => ['sometimes', 'integer', 'exists:menu_categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_available' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'required', 'array', 'min:1', 'max:5'],
            'images.*' => ['required', 'string', new ImageBase64Input],
        ];
    }
}
