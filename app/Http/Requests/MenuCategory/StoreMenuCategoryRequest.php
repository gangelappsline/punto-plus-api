<?php

namespace App\Http\Requests\MenuCategory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMenuCategoryRequest extends FormRequest
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
        $businessId = $this->businessId();

        return [
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('menu_categories', 'name')->where('business_id', $businessId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
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
