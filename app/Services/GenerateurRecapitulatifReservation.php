<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ReferenceList;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;

/**
 * Produit le PDF de récapitulatif remis au client après sa demande.
 * Le fichier est généré à la volée : rien n’est stocké, le contenu suit la réservation.
 */
class GenerateurRecapitulatifReservation
{
    /** @var array<string, string> */
    private const STATUTS = [
        'pending_payment' => 'Paiement en attente',
        'locked' => 'En cours de validation',
        'pending_partner' => 'Demande en attente',
        'confirmed' => 'Confirmée',
        'ongoing' => 'En cours de location',
        'completed' => 'Clôturée',
        'cancelled' => 'Annulée',
        'expired' => 'Expirée',
        'refunded' => 'Remboursée',
        'archived' => 'Archivée',
    ];

    public function produire(Booking $reservation): string
    {
        $reservation->loadMissing([
            'vehicle',
            'pickupLocation',
            'returnLocation',
            'customer',
        ]);

        $html = view('pdf.recapitulatif-reservation', [
            'reference' => $reservation->reference ?: '—',
            'lignes' => $this->lignes($reservation),
            'montant' => $this->montant((int) $reservation->total_amount),
            'editeLe' => $this->formaterDate(now()),
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }

    /**
     * @return list<array{libelle: string, valeur: string}>
     */
    private function lignes(Booking $reservation): array
    {
        $vehicule = $reservation->vehicle;
        $nomVehicule = $vehicule
            ? trim(($vehicule->brand ?? '').' '.($vehicule->model ?? ''))
            : '';
        if ($vehicule?->plate_number) {
            $nomVehicule = trim($nomVehicule.' · '.$vehicule->plate_number);
        }

        $client = $reservation->customer;
        $nomClient = $client?->name ?: $reservation->guest_name;
        $email = $client?->email ?: $reservation->guest_email;
        $telephone = $client?->phone ?: $reservation->guest_phone;

        $lieuDepart = $this->lieu($reservation->pickupLocation?->name, $reservation->pickupLocation?->city);
        $lieuRetour = $this->lieu($reservation->returnLocation?->name, $reservation->returnLocation?->city);
        if ($lieuRetour === '—') {
            $lieuRetour = $lieuDepart;
        }

        $jours = (int) $reservation->days_count;

        return array_values(array_filter([
            $this->ligne('Client', $nomClient),
            $this->ligne('E-mail', $email),
            $this->ligne('Téléphone', $telephone),
            $this->ligne('Véhicule', $nomVehicule),
            $this->ligne('Départ', $this->formaterDate($reservation->pickup_at)),
            $this->ligne('Lieu de départ', $lieuDepart === '—' ? null : $lieuDepart),
            $this->ligne('Retour', $this->formaterDate($reservation->return_at)),
            $this->ligne('Lieu de retour', $lieuRetour === '—' ? null : $lieuRetour),
            $this->ligne('Durée', $jours > 0 ? $jours.' jour'.($jours > 1 ? 's' : '') : null),
            $this->ligne('Statut', $this->libelleStatut($reservation->status?->value ?? (string) $reservation->status)),
            $this->ligne('Moyen de paiement', $this->libellePaiement($reservation->payment_method)),
            $this->ligne('Caution', $reservation->deposit_amount !== null ? $this->montant((int) $reservation->deposit_amount) : null),
        ]));
    }

    /**
     * @return array{libelle: string, valeur: string}|null
     */
    private function ligne(string $libelle, ?string $valeur): ?array
    {
        $propre = trim((string) $valeur);
        if ($propre === '' || $propre === '—') {
            return null;
        }

        return ['libelle' => $libelle, 'valeur' => $propre];
    }

    private function libelleStatut(string $statut): string
    {
        return self::STATUTS[$statut] ?? $statut;
    }

    private function libellePaiement(?string $slug): ?string
    {
        if (! $slug) {
            return null;
        }

        $libelle = ReferenceList::query()
            ->where('type', 'payment_method')
            ->where('slug', $slug)
            ->value('label');

        return $libelle ?: $slug;
    }

    private function lieu(?string $nom, ?string $ville): string
    {
        $texte = trim(implode(', ', array_filter([$nom, $ville])));

        return $texte !== '' ? $texte : '—';
    }

    private function formaterDate(Carbon|\DateTimeInterface|null $date): string
    {
        if (! $date) {
            return '—';
        }

        $moment = Carbon::parse($date)->timezone('Africa/Libreville');
        $jours = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
        $mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

        return $jours[(int) $moment->dayOfWeek]
            .' '.$moment->format('d')
            .' '.$mois[(int) $moment->format('n') - 1]
            .' '.$moment->format('Y').', '.$moment->format('H:i');
    }

    private function montant(int $valeur): string
    {
        return number_format($valeur, 0, ',', ' ').' FCFA';
    }
}
