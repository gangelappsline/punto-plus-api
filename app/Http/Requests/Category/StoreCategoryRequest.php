<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
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
        $iconRules = $this->hasFile('icon')
            ? [
                'nullable',
                'file',
                'image',
                'mimetypes:'.implode(',', config('punto_plus.assets.allowed_mimes')),
                'mimes:'.implode(',', config('punto_plus.assets.allowed_extensions')),
                'max:'.config('punto_plus.assets.max_kilobytes'),
            ]
            : ['nullable', 'string', 'max:255'];

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')],
            'icon' => $iconRules,
        ];
    }
}
