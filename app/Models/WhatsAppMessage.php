<?php

namespace App\Models;

use App\Enums\WhatsAppDirection;
use App\Enums\WhatsAppStatus;
use Database\Factories\WhatsAppMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Registro de un mensaje de WhatsApp: envío desde la API, mensaje entrante del
 * cliente o acuse de entrega/lectura recibido por webhook.
 */
#[Fillable([
    'direction', 'status', 'wa_message_id', 'business_id', 'from_phone', 'to_phone',
    'type', 'template_name', 'template_language', 'body', 'payload',
    'error_code', 'error', 'sent_at', 'delivered_at', 'read_at',
])]
class WhatsAppMessage extends Model
{
    /** @use HasFactory<WhatsAppMessageFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => WhatsAppDirection::class,
            'status' => WhatsAppStatus::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeForBusiness(Builder $query, Business|string $business): Builder
    {
        return $query->where('business_id', $business instanceof Business ? $business->getKey() : $business);
    }

    public function scopeDirection(Builder $query, WhatsAppDirection|string|null $direction): Builder
    {
        if (blank($direction)) {
            return $query;
        }

        return $query->where('direction', $direction instanceof WhatsAppDirection ? $direction->value : $direction);
    }

    public function scopeStatus(Builder $query, WhatsAppStatus|string|null $status): Builder
    {
        if (blank($status)) {
            return $query;
        }

        return $query->where('status', $status instanceof WhatsAppStatus ? $status->value : $status);
    }

    public function scopeForPhone(Builder $query, ?string $phone): Builder
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (blank($digits)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($digits): void {
            $query->where('to_phone', 'like', '%'.$digits.'%')
                ->orWhere('from_phone', 'like', '%'.$digits.'%');
        });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $digits = preg_replace('/\D+/', '', $term);
        $like = '%'.$term.'%';

        return $query->where(function (Builder $query) use ($like, $digits): void {
            $query->where('template_name', 'like', $like)
                ->orWhere('body', 'like', $like)
                ->orWhere('to_phone', 'like', $like)
                ->orWhere('from_phone', 'like', $like);

            if (filled($digits)) {
                $query->orWhere('to_phone', 'like', '%'.$digits.'%')
                    ->orWhere('from_phone', 'like', '%'.$digits.'%');
            }
        });
    }

    // -----------------------------------------------------------------
    // Estados
    // -----------------------------------------------------------------

    /**
     * Marca el mensaje como aceptado por la Cloud API.
     *
     * @param  array<string, mixed>  $response
     */
    public function markAsSent(array $response): self
    {
        $waMessageId = data_get($response, 'messages.0.id');

        $this->wa_message_id = filled($waMessageId) ? (string) $waMessageId : $this->wa_message_id;
        // Sin id de Meta no habrá acuses: el mensaje se queda en `queued`.
        $this->status = filled($waMessageId) ? WhatsAppStatus::Sent : WhatsAppStatus::Queued;
        $this->sent_at ??= filled($waMessageId) ? now() : null;
        $this->payload = ['request' => $this->payload, 'response' => $response];

        $this->save();

        return $this;
    }

    public function markAsFailed(Throwable $exception, ?string $errorCode = null): self
    {
        $this->status = WhatsAppStatus::Failed;
        $this->error = $exception->getMessage();
        $this->error_code = $errorCode;

        $this->save();

        return $this;
    }

    /**
     * Aplica un acuse de Meta respetando el orden de los estados.
     */
    public function applyStatus(WhatsAppStatus $status, ?Carbon $at = null): self
    {
        $at ??= now();

        if ($status->isForwardFrom($this->status)) {
            $this->status = $status;
        }

        match ($status) {
            WhatsAppStatus::Sent => $this->sent_at ??= $at,
            WhatsAppStatus::Delivered => $this->delivered_at ??= $at,
            WhatsAppStatus::Read => $this->read_at ??= $at,
            default => null,
        };

        $this->save();

        return $this;
    }

    public function isOutgoing(): bool
    {
        return $this->direction === WhatsAppDirection::Outgoing;
    }
}
