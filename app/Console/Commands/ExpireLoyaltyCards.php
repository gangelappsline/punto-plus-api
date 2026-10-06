<?php

namespace App\Console\Commands;

use App\Enums\CardStatus;
use App\Models\CustomerCard;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Cierra las tarjetas de cliente que ya no pueden acumular sellos y reinicia los
 * sellos caducados.
 *
 * Se ejecuta a diario desde `routes/console.php`:
 *
 *   - pasa a `expired` las tarjetas activas/completadas cuyo `expires_at` venció,
 *     cuyo programa se desactivó/caducó o cuyo programa ya no existe;
 *   - pone a cero los sellos de las tarjetas inactivas durante más días que
 *     `settings.stamps_lifetime_days` (por tarjeta) o
 *     `punto_plus.cards.stamps_lifetime_days` (global; null = no caducan).
 *
 * Las recompensas ya ganadas se respetan: sólo se reinician los sellos de tarjetas
 * en progreso, nunca los de una tarjeta completada pendiente de canje.
 */
class ExpireLoyaltyCards extends Command
{
    protected $signature = 'punto-plus:expire-cards {--dry-run : Sólo informa, sin escribir en la base de datos}';

    protected $description = 'Marca como caducadas las tarjetas que ya no son válidas y reinicia los sellos vencidos';

    public function handle(): int
    {
        $now = now();
        $dryRun = (bool) $this->option('dry-run');

        $expiredQuery = CustomerCard::query()
            ->whereIn('status', [CardStatus::Active->value, CardStatus::Completed->value])
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->where(fn (Builder $q) => $q->whereNotNull('expires_at')->where('expires_at', '<=', $now))
                    ->orWhereDoesntHave('loyaltyCard')
                    ->orWhereHas('loyaltyCard', function (Builder $q) use ($now): void {
                        $q->where('is_active', false)
                            ->orWhere(fn (Builder $q2) => $q2->whereNotNull('expires_at')->where('expires_at', '<=', $now));
                    });
            });

        $expired = $dryRun
            ? $expiredQuery->count()
            : $expiredQuery->update(['status' => CardStatus::Expired->value, 'updated_at' => $now]);

        $reset = $this->resetExpiredStamps($now, $dryRun);

        $this->info(($dryRun ? '[simulación] ' : '')."Tarjetas marcadas como caducadas: {$expired}");
        $this->info(($dryRun ? '[simulación] ' : '')."Tarjetas con sellos vencidos reiniciados: {$reset}");

        return self::SUCCESS;
    }

    /**
     * Reinicia los sellos de las tarjetas que llevan demasiado tiempo sin actividad.
     */
    private function resetExpiredStamps(Carbon $now, bool $dryRun): int
    {
        $reset = 0;

        CustomerCard::query()
            ->where('status', CardStatus::Active->value)
            ->where('stamps_count', '>', 0)
            ->whereNotNull('last_stamp_at')
            ->chunkById(200, function (\Illuminate\Support\Collection $cards) use ($now, $dryRun, &$reset): void {
                /** @var CustomerCard $card */
                foreach ($cards as $card) {
                    $days = $card->stampLifetimeDays();

                    if ($days === null || $days <= 0) {
                        continue;
                    }

                    if ($card->last_stamp_at->gt($now->copy()->subDays($days))) {
                        continue;
                    }

                    if (! $dryRun) {
                        $card->stamps_count = 0;
                        $card->completed_at = null;
                        $card->save();
                    }

                    $reset++;
                }
            });

        return $reset;
    }
}
