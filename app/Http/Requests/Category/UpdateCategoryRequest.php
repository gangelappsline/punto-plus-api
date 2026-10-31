<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
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
        $category = $this->route('category');

        $iconRules = $this->hasFile('icon')
            ? [
                'sometimes',
                'nullable',
                'file',
                'image',
                'mimetypes:'.implode(',', config('punto_plus.assets.allowed_mimes')),
                'mimes:'.implode(',', config('punto_plus.assets.allowed_extensions')),
                'max:'.config('punto_plus.assets.max_kilobytes'),
            ]
            : ['sometimes', 'nullable', 'string', 'max:255'];

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($category)],
            'icon' => $iconRules,
        ];
    }
}
