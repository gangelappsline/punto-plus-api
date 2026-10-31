<?php

namespace App\Http\Requests\Business;

use App\Models\Business;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBusinessQrSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'destination_type' => ['sometimes', 'required', 'string', 'in:menu,website,custom'],
            'custom_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'size' => ['sometimes', 'required', 'integer', 'min:256', 'max:2048'],
            'quiet_zone' => ['sometimes', 'required', 'integer', 'min:4', 'max:12'],
            'foreground_color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'background_color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'eye_color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'module_style' => ['sometimes', 'required', 'string', 'in:square,dots,rounded'],
            'eye_style' => ['sometimes', 'required', 'string', 'in:square,rounded,circle'],
            'include_logo' => ['sometimes', 'required', 'boolean'],
            'logo_scale' => ['sometimes', 'required', 'integer', 'min:10', 'max:20'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $business = $this->businessForSettings();
            $settings = array_merge(
                config('punto_plus.business_qr.defaults', []),
                $business?->qr_settings ?? [],
                $this->all(),
            );

            if (($settings['destination_type'] ?? 'menu') === 'custom' && blank($settings['custom_url'] ?? null)) {
                $validator->errors()->add('custom_url', 'Agrega una URL para el destino personalizado.');
            }

            if (($settings['destination_type'] ?? 'menu') === 'website' && blank($business?->website)) {
                $validator->errors()->add('destination_type', 'El perfil del negocio debe tener un sitio web configurado.');
            }

            if (($settings['include_logo'] ?? false) && blank($business?->logo_path)) {
                $validator->errors()->add('include_logo', 'Sube primero el logo del negocio para incluirlo en el QR.');
            }

            if (preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($settings['foreground_color'] ?? '')) === 1
                && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($settings['background_color'] ?? '')) === 1
                && $this->contrastRatio($settings['foreground_color'], $settings['background_color']) < 4.5
            ) {
                $validator->errors()->add('foreground_color', 'El color principal debe contrastar al menos 4.5:1 con el fondo para mantener la lectura del QR.');
            }

            if (preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($settings['eye_color'] ?? '')) === 1
                && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($settings['background_color'] ?? '')) === 1
                && $this->contrastRatio($settings['eye_color'], $settings['background_color']) < 4.5
            ) {
                $validator->errors()->add('eye_color', 'El color de los localizadores debe contrastar al menos 4.5:1 con el fondo.');
            }
        });
    }

    private function businessForSettings(): ?Business
    {
        $user = $this->user();
        if ($user === null) {
            return null;
        }

        $business = $user->business()->first();
        if ($business !== null) {
            return $business;
        }

        if ($user->isAdmin() && filled($this->input('business_id'))) {
            return Business::query()->find($this->input('business_id'));
        }

        return null;
    }

    private function contrastRatio(string $first, string $second): float
    {
        $luminance = fn (string $color): float => array_sum(array_map(
            fn (int $channel, float $weight): float => $this->linearChannel($channel) * $weight,
            [hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2))],
            [0.2126, 0.7152, 0.0722],
        ));

        $a = $luminance($first);
        $b = $luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private function linearChannel(int $channel): float
    {
        $value = $channel / 255;

        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }
}
