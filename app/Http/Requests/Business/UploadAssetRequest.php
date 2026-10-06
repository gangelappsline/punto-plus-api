<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Subida de assets de marca (multipart/form-data).
 * Se reutiliza tanto para el negocio como para una tarjeta concreta.
 */
class UploadAssetRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in(['logo', 'background', 'stamp_icon'])],
            'file' => [
                'required',
                'file',
                'image',
                'mimetypes:'.implode(',', config('punto_plus.assets.allowed_mimes')),
                'mimes:'.implode(',', config('punto_plus.assets.allowed_extensions')),
                'max:'.config('punto_plus.assets.max_kilobytes'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.image' => 'El archivo debe ser una imagen (JPG, PNG o WEBP).',
            'file.max' => 'La imagen no puede superar los :max KB.',
            'type.in' => 'El tipo debe ser logo, background o stamp_icon.',
        ];
    }
}
