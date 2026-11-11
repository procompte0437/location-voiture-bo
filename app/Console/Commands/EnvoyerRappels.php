<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Partner;
use App\Models\PartnerDocument;
use App\Models\VehicleDocument;
use App\Services\Notificateur;
use App\Support\PiecesJustificatives as Pieces;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Rappels quotidiens : départ et retour du lendemain, pièces qui expirent dans 30, 7 ou 1 jour.
 * Relancer la commande le même jour n’envoie rien deux fois.
 */
class EnvoyerRappels extends Command
{
    protected $signature = 'doto:rappels';

    protected $description = 'Envoie les rappels J-1 et les alertes de pièces qui expirent';

    private const SEUILS_PIECES = [30, 7, 1];

    public function handle(Notificateur $notificateur): int
    {
        $demain = [today()->addDay()->startOfDay(), today()->addDay()->endOfDay()];
        $envoyes = 0;

        $departs = Booking::query()->where('status', BookingStatus::Confirmed->value)->whereBetween('pickup_at', $demain)->get();
        foreach ($departs as $r) {
            $envoyes += $this->uneFois('depart-'.$r->id, fn () => $notificateur->rappelDepart($r));
        }

        $retours = Booking::query()->where('status', BookingStatus::Ongoing->value)->whereBetween('return_at', $demain)->get();
        foreach ($retours as $r) {
            $envoyes += $this->uneFois('retour-'.$r->id, fn () => $notificateur->rappelRetour($r));
        }

        foreach ($this->piecesParPartenaire() as $partenaireId => $echeances) {
            $partenaire = Partner::with('owner')->find($partenaireId);
            if ($partenaire) {
                $envoyes += $this->uneFois('pieces-'.$partenaireId.'-'.today()->toDateString(), fn () => $notificateur->documentsExpirant($partenaire, $echeances));
            }
        }

        $this->info($envoyes.' rappel(s) envoyé(s).');

        return self::SUCCESS;
    }

    /**
     * @return array<int, list<string>>
     */
    private function piecesParPartenaire(): array
    {
        $dates = array_map(fn ($j) => today()->addDays($j)->toDateString(), self::SEUILS_PIECES);
        $parPartenaire = [];

        foreach (PartnerDocument::query()->where(fn ($q) => $this->auxDates($q, $dates))->where('status', '!=', 'rejected')->get() as $d) {
            $parPartenaire[$d->partner_id][] = (Pieces::PARTENAIRE[$d->type]['libelle'] ?? $d->type).' : '.$this->date($d->expires_at);
        }
        foreach (VehicleDocument::query()->where(fn ($q) => $this->auxDates($q, $dates))->where('status', '!=', 'rejected')->with('vehicle')->get() as $d) {
            if ($d->vehicle) {
                $parPartenaire[$d->vehicle->partner_id][] = (Pieces::VEHICULE[$d->type]['libelle'] ?? $d->type)
                    .' du '.trim($d->vehicle->brand.' '.$d->vehicle->model).' ('.$d->vehicle->plate_number.') : '.$this->date($d->expires_at);
            }
        }

        return $parPartenaire;
    }

    /** Comparaison sur le jour seul : la colonne peut porter une heure selon la base. */
    private function auxDates(\Illuminate\Database\Eloquent\Builder $requete, array $dates): void
    {
        foreach ($dates as $date) {
            $requete->orWhereDate('expires_at', $date);
        }
    }

    private function uneFois(string $cle, callable $envoi): int
    {
        if (! Cache::add('rappel-'.$cle, true, now()->addDays(3))) {
            return 0;
        }
        $envoi();

        return 1;
    }

    private function date(?Carbon $d): string
    {
        return $d?->format('d/m/Y') ?? '—';
    }
}
