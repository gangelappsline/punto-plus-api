<?php

namespace App\Http\Requests\BusinessCategory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $businessId = $this->businessId();

        return [
            'category_id' => [
                'required',
                'integer',
                'exists:categories,id',
                Rule::unique('business_categories', 'category_id')->where('business_id', $businessId),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    private function businessId(): ?string
    {
        $user = $this->user();
        $businessId = $user?->business()->value('id');

        if ($businessId === null && $user?->isAdmin() && $this->filled('business_id')) {
            return (string) $this->input('business_id');
        }

        return $businessId === null ? null : (string) $businessId;
    }
}
