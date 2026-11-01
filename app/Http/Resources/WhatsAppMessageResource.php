<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mensaje de WhatsApp enviado o recibido, tal como lo consume la app/panel.
 *
 * @mixin \App\Models\WhatsAppMessage
 */
class WhatsAppMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'wa_message_id' => $this->wa_message_id,
            'business_id' => $this->business_id,
            'from_phone' => $this->from_phone,
            'to_phone' => $this->to_phone,
            'type' => $this->type,
            'template_name' => $this->template_name,
            'template_language' => $this->template_language,
            'body' => $this->body,
            'error' => $this->error,
            'error_code' => $this->error_code,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
