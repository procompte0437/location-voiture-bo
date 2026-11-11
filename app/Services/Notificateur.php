<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Partner;
use App\Models\Review;
use App\Models\User;
use App\Notifications\MessageDoto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * E-mails des événements du cahier des charges (§4.7, canal e-mail).
 *
 * - L’envoi part après la réponse HTTP : l’utilisateur n’attend pas le serveur de messagerie.
 * - Un échec d’envoi est journalisé et n’annule jamais l’action métier.
 * - Le client ne voit jamais le nom du loueur ; le loueur ne voit les coordonnées
 *   du client qu’une fois la location confirmée (règles du projet).
 */
class Notificateur
{
    // ——— Dossier partenaire ———

    public function dossierRecu(Partner $partenaire): void
    {
        $this->auPartenaire($partenaire, 'partner.received', 'dossier reçu', [
            'Votre dossier partenaire « '.$this->nom($partenaire).' » est bien arrivé.',
            'Déposez vos pièces justificatives (pièce d’identité, RCCM, NIF, justificatif, coordonnées de reversement) : l’équipe DOTO les étudie sous 48 h ouvrées.',
        ], 'Déposer mes pièces', '/partenaire/documents');

        $admins = User::query()->where('role', UserRole::SuperAdmin)->where('status', 'active')->get();
        foreach ($admins as $admin) {
            $this->envoyer($admin->email, new MessageDoto('admin.partner_received', 'nouveau dossier partenaire', [
                'Un nouveau dossier partenaire attend votre validation : '.$this->nom($partenaire).($partenaire->city ? ' ('.$partenaire->city.')' : '').'.',
            ], 'Ouvrir la file de validation', $this->lien('/admin/comptes?onglet=validation')));
        }
    }

    public function statutDossier(Partner $partenaire, string $statut, ?string $motif = null): void
    {
        [$objet, $lignes, $bouton, $chemin] = match ($statut) {
            'approved' => ['dossier validé', [
                'Bonne nouvelle : votre dossier partenaire est validé.',
                'Vous pouvez publier vos véhicules et recevoir des réservations.',
            ], 'Publier mes véhicules', '/partenaire/voitures'],
            'rejected' => ['dossier refusé', [
                'Votre dossier partenaire n’a pas été retenu.',
                'Motif : '.($motif ?: 'non précisé').'.',
                'Vous pouvez déposer une nouvelle demande une fois les points corrigés.',
            ], 'Voir mon dossier', '/partenaire/documents'],
            'info_requested' => ['complément demandé', [
                'L’équipe DOTO a besoin d’un complément pour valider votre dossier :',
                (string) $motif,
                'Le dossier repart en validation dès que vous renvoyez la pièce demandée.',
            ], 'Compléter mon dossier', '/partenaire/documents'],
            'suspended' => ['compte suspendu', [
                'Votre compte partenaire est suspendu et vos annonces sont retirées du site.',
                'Motif : '.($motif ?: 'non précisé').'.',
                'Contactez le support pour en savoir plus.',
            ], 'Contacter le support', '/contact'],
            default => [null, [], null, null],
        };

        if ($objet) {
            $this->auPartenaire($partenaire, 'partner.'.$statut, $objet, $lignes, $bouton, $chemin);
        }
    }

    public function pieceRefusee(Partner $partenaire, string $piece, string $motif): void
    {
        $this->auPartenaire($partenaire, 'document.rejected', 'pièce à renvoyer', [
            'La pièce « '.$piece.' » a été refusée.',
            'Motif : '.$motif,
        ], 'Renvoyer la pièce', '/partenaire/documents');
    }

    /**
     * @param  list<string>  $echeances  « Assurance du Toyota RAV4 (GA-102-A) : 12/12/2026 »
     */
    public function documentsExpirant(Partner $partenaire, array $echeances): void
    {
        $this->auPartenaire($partenaire, 'document.expiring', 'pièces bientôt expirées', [
            'Ces pièces arrivent à échéance :',
            ...array_map(fn ($e) => '• '.$e, $echeances),
            'Une assurance ou une visite technique expirée retire le véhicule de la recherche. Déposez la nouvelle pièce avant l’échéance.',
        ], 'Mettre à jour mes pièces', '/partenaire/documents');
    }

    // ——— Réservations ———

    public function demandeCreee(Booking $r): void
    {
        $r->loadMissing(['vehicle', 'partner.owner']);
        if ($r->status === \App\Enums\BookingStatus::Confirmed) {
            $this->reservationConfirmee($r);
            $this->auPartenaire($r->partner, 'booking.new_confirmed', 'nouvelle réservation '.$r->reference, [
                'Nouvelle réservation confirmée automatiquement (mode instantané) : '.$this->resume($r).'.',
                'Montant : '.$this->xaf((int) $r->total_amount).'. Les coordonnées du client sont dans le dossier.',
            ], 'Ouvrir le dossier', '/partenaire/reservations');

            return;
        }

        $this->auClient($r, 'booking.requested', 'demande de réservation '.$r->reference, [
            'Votre demande de réservation est enregistrée : '.$this->resume($r).'.',
            'Le loueur répond sous '.config('locagabon.delai_reponse_heures', 12).' h. Vous recevrez un e-mail dès sa réponse.',
        ], 'Suivre ma réservation', '/mes-reservations');

        $this->auPartenaire($r->partner, 'booking.new_request', 'nouvelle demande '.$r->reference, [
            'Nouvelle demande de réservation : '.$this->resume($r).'.',
            'Montant : '.$this->xaf((int) $r->total_amount).'.',
            'Acceptez-la ou refusez-la sous '.config('locagabon.delai_reponse_heures', 12).' h : sans réponse, elle expire et le véhicule est libéré.',
        ], 'Répondre à la demande', '/partenaire/reservations');
    }

    public function reservationConfirmee(Booking $r): void
    {
        $this->auClient($r, 'booking.confirmed', 'réservation confirmée '.$r->reference, [
            'Votre réservation est confirmée : '.$this->resume($r).'.',
            'Présentez votre permis et une pièce d’identité au retrait. La caution prévue est de '.$this->xaf((int) $r->deposit_amount).'.',
        ], 'Voir ma réservation', '/mes-reservations');
    }

    public function reservationRefusee(Booking $r, ?string $motif): void
    {
        $this->auClient($r, 'booking.refused', 'réservation non disponible '.$r->reference, [
            'Le loueur ne peut pas honorer votre demande : '.$this->resume($r).'.',
            'Motif : '.($motif ?: 'non précisé').'.',
            'Si vous aviez payé, le remboursement est traité par l’équipe DOTO.',
        ], 'Chercher un autre véhicule', '/vehicules');
    }

    public function reservationAnnulee(Booking $r, string $par, ?string $motif = null): void
    {
        $r->loadMissing(['vehicle', 'partner.owner']);
        if ($par !== 'client') {
            $this->auClient($r, 'booking.cancelled', 'réservation annulée '.$r->reference, [
                'Votre réservation est annulée : '.$this->resume($r).'.',
                ...($motif ? ['Motif : '.$motif.'.'] : []),
                'Si vous aviez payé, le remboursement est traité par l’équipe DOTO.',
            ], 'Voir mes réservations', '/mes-reservations');
        }

        $this->auPartenaire($r->partner, 'booking.cancelled_partner', 'réservation annulée '.$r->reference, [
            'La réservation '.$r->reference.' est annulée'.($par === 'client' ? ' par le client' : ' par l’équipe DOTO').' : '.$this->resume($r).'.',
            'Le véhicule est de nouveau disponible sur ces dates.',
        ], 'Voir mes réservations', '/partenaire/reservations');
    }

    public function demandeExpiree(Booking $r): void
    {
        $r->loadMissing(['vehicle', 'partner.owner']);
        $this->auClient($r, 'booking.expired', 'demande expirée '.$r->reference, [
            'Le loueur n’a pas répondu à temps à votre demande : '.$this->resume($r).'.',
            'Elle est annulée. Si vous aviez payé, le remboursement est traité par l’équipe DOTO.',
        ], 'Chercher un autre véhicule', '/vehicules');
        $this->auPartenaire($r->partner, 'booking.expired_partner', 'demande expirée '.$r->reference, [
            'La demande '.$r->reference.' a expiré faute de réponse dans le délai : '.$this->resume($r).'.',
            'Répondre vite aux demandes améliore votre visibilité auprès des clients.',
        ], 'Voir mes réservations', '/partenaire/reservations');
    }

    public function demandeAvis(Booking $r): void
    {
        $this->auClient($r, 'booking.review_request', 'votre avis sur votre location', [
            'Merci d’avoir loué avec DOTO : '.$this->resume($r).'.',
            'Votre avis aide les autres clients et le loueur. Il prend une minute.',
        ], 'Donner mon avis', '/mes-reservations');
    }

    public function nouvelAvis(Review $avis): void
    {
        $avis->loadMissing(['partner.owner', 'booking.vehicle']);
        $this->auPartenaire($avis->partner, 'review.received', 'nouvel avis client', [
            'Un client a noté sa location '.($avis->booking?->reference ?? '').' : '.$avis->rating_overall.'/5.',
            ...($avis->comment ? ['« '.mb_strimwidth($avis->comment, 0, 300, '…').' »'] : []),
            'Vous pouvez lui répondre publiquement.',
        ], 'Répondre', '/partenaire/avis');
    }

    public function rappelDepart(Booking $r): void
    {
        $this->auClient($r, 'booking.reminder_pickup', 'rappel : départ demain', [
            'Rappel : votre location commence demain, '.$this->date($r->pickup_at).'.',
            'Véhicule : '.$this->vehicule($r).'.',
            'Pensez à votre permis et à une pièce d’identité.',
        ], 'Voir ma réservation', '/mes-reservations');

        $this->auPartenaire($r->partner, 'booking.reminder_pickup_partner', 'départ demain '.$r->reference, [
            'Départ demain '.$this->date($r->pickup_at).' : '.$this->vehicule($r).', réservation '.$r->reference.'.',
        ], 'Ouvrir le dossier', '/partenaire/reservations');
    }

    public function rappelRetour(Booking $r): void
    {
        $this->auClient($r, 'booking.reminder_return', 'rappel : retour demain', [
            'Rappel : le véhicule est à rendre demain, '.$this->date($r->return_at).'.',
            'Un retard peut entraîner une journée supplémentaire.',
        ], 'Voir ma réservation', '/mes-reservations');
    }

    // ——— Outils ———

    private function auClient(Booking $r, string $evenement, string $objet, array $lignes, ?string $bouton, ?string $chemin): void
    {
        $r->loadMissing('customer');
        $email = $r->customer?->email ?: $r->guest_email;
        if ($email) {
            $this->envoyer($email, new MessageDoto($evenement, $objet, $lignes, $bouton, $chemin ? $this->lien($chemin) : null));
        }
    }

    private function auPartenaire(?Partner $partenaire, string $evenement, string $objet, array $lignes, ?string $bouton, ?string $chemin): void
    {
        $email = $partenaire?->owner?->email;
        if ($email) {
            $this->envoyer($email, new MessageDoto($evenement, $objet, $lignes, $bouton, $chemin ? $this->lien($chemin) : null));
        }
    }

    private function envoyer(string $email, MessageDoto $message): void
    {
        $envoi = function () use ($email, $message) {
            try {
                Notification::route('mail', $email)->notifyNow($message);
            } catch (\Throwable $erreur) {
                Log::warning('E-mail non envoyé', ['evenement' => $message->evenement, 'erreur' => $erreur->getMessage()]);
            }
        };

        // En requête web, après la réponse ; en console et en test, tout de suite.
        if (app()->runningInConsole()) {
            $envoi();
        } else {
            app()->terminating($envoi);
        }
    }

    private function lien(string $chemin): string
    {
        return rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/').$chemin;
    }

    private function resume(Booking $r): string
    {
        return $this->vehicule($r).', du '.$this->date($r->pickup_at).' au '.$this->date($r->return_at).' (réf. '.$r->reference.')';
    }

    private function vehicule(Booking $r): string
    {
        $r->loadMissing('vehicle');

        return trim(($r->vehicle?->brand ?? '').' '.($r->vehicle?->model ?? '')) ?: 'véhicule';
    }

    private function date(?\DateTimeInterface $d): string
    {
        return $d ? $d->format('d/m/Y à H\hi') : '—';
    }

    private function xaf(int $montant): string
    {
        return number_format($montant, 0, ',', ' ').' FCFA';
    }

    private function nom(Partner $p): string
    {
        return (string) ($p->company_name ?: $p->manager_name ?: 'Partenaire');
    }
}
