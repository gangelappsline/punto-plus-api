<?php

namespace App\Http\Requests\BusinessCategory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $businessId = $this->user()?->business()->value('id');
        if ($businessId === null && $this->user()?->isAdmin() && $this->filled('business_id')) {
            $businessId = $this->input('business_id');
        }
        $businessCategory = $this->route('businessCategory');

        return [
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:categories,id',
                Rule::unique('business_categories', 'category_id')
                    ->where('business_id', $businessId)
                    ->ignore($businessCategory),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
