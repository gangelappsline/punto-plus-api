<?php

namespace App\Http\Requests\WhatsApp;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Envío de un mensaje de WhatsApp desde el negocio.
 *
 * El teléfono llega en cualquier formato razonable y se normaliza a E.164
 * antes de validar; `type` decide si se manda texto libre o una plantilla
 * aprobada por Meta.
 */
class SendWhatsAppMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user !== null && ($user->isNegocio() || $user->isAdmin());
    }

    protected function prepareForValidation(): void
    {
        $to = $this->input('to');

        if (is_string($to)) {
            $normalized = PhoneNumber::normalize($to, config('whatsapp.default_country_code'));

            // Si no se puede normalizar se deja el valor original para que falle
            // la validación con un mensaje claro.
            $this->merge(['to' => $normalized ?? $to]);
        }

        if ($this->has('template_language') && blank($this->input('template_language'))) {
            $this->merge(['template_language' => config('whatsapp.templates.language')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'type' => ['required', 'string', Rule::in(['text', 'template'])],

            // Texto libre (sólo válido dentro de la ventana de 24 h).
            'body' => ['required_if:type,text', 'nullable', 'string', 'max:4096'],
            'preview_url' => ['sometimes', 'boolean'],

            // Plantilla aprobada.
            'template_name' => ['required_if:type,template', 'nullable', 'string', 'max:191', 'regex:/^[a-z0-9_]+$/'],
            'template_language' => ['nullable', 'string', 'max:15'],
            'components' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.required' => 'Indica el número de WhatsApp de destino.',
            'to.regex' => 'El teléfono debe ir en formato internacional E.164, por ejemplo +5215512345678.',
            'type.required' => 'Indica el tipo de mensaje: text o template.',
            'type.in' => 'El tipo de mensaje debe ser text o template.',
            'body.required_if' => 'Un mensaje de texto necesita el campo body.',
            'body.max' => 'El texto no puede superar los 4096 caracteres.',
            'template_name.required_if' => 'Una plantilla necesita el campo template_name.',
            'template_name.regex' => 'El nombre de la plantilla sólo admite minúsculas, números y guiones bajos.',
            'components.array' => 'components debe ser una lista de componentes de la plantilla.',
        ];
    }
}
