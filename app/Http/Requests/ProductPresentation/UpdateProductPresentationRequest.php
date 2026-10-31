<?php

namespace App\Http\Requests\ProductPresentation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductPresentationRequest extends FormRequest
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
        $product = $this->route('product');
        $presentation = $this->route('presentation');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('product_presentations', 'name')
                    ->where('product_id', $product?->getKey())
                    ->ignore($presentation),
            ],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
