<?php

namespace App\Console\Commands;

use App\Services\BookingService;
use App\Services\Notificateur;
use Illuminate\Console\Command;

/**
 * Mode « sur demande » : passé le délai de réponse (12 h par défaut), la demande expire,
 * le véhicule est libéré et le client comme le loueur sont prévenus.
 */
class ExpirerDemandes extends Command
{
    protected $signature = 'doto:expirer-demandes';

    protected $description = 'Fait expirer les demandes de réservation restées sans réponse du loueur';

    public function handle(BookingService $reservations, Notificateur $notificateur): int
    {
        $expirees = $reservations->expirerDemandesSansReponse();
        foreach ($expirees as $reservation) {
            $notificateur->demandeExpiree($reservation);
        }

        $this->info(count($expirees).' demande(s) expirée(s).');

        return self::SUCCESS;
    }
}
