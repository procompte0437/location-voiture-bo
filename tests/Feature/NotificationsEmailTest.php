<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Partner;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Notifications\MessageDoto;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationsEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Partner $partenaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['app.frontend_url' => 'https://doto241.com']);
        $this->client = User::where('email', 'client@doto241.com')->firstOrFail();
        $this->partenaire = User::where('email', 'partenaire@doto241.com')->firstOrFail()->ownedPartner;
    }

    /** Renvoie l’e-mail envoyé pour cet événement à cette adresse. */
    private function envoye(string $email, string $evenement): MessageDoto
    {
        $trouve = null;
        Notification::assertSentOnDemand(MessageDoto::class, function (MessageDoto $n, array $canaux, AnonymousNotifiable $d) use ($email, $evenement, &$trouve) {
            if (($d->routes['mail'] ?? null) === $email && $n->evenement === $evenement) {
                $trouve = $n;

                return true;
            }

            return false;
        });

        return $trouve;
    }

    private function texte(MessageDoto $n): string
    {
        return implode(' ', [$n->objet, ...$n->lignes, (string) $n->lien]);
    }

    public function test_cycle_d_une_reservation_avec_ses_e_mails(): void
    {
        Notification::fake();
        $vehicule = Vehicle::where('partner_id', $this->partenaire->id)->firstOrFail();

        Sanctum::actingAs($this->client);
        $id = $this->postJson('/api/v1/bookings', [
            'vehicle_id' => $vehicule->id,
            'pickup_at' => now()->addDays(3)->format('Y-m-d 10:00'),
            'return_at' => now()->addDays(5)->format('Y-m-d 10:00'),
            'driver_age' => 30,
            'payment_method' => 'airtel_money',
        ])->assertCreated()->json('data.id');

        $auClient = $this->envoye('client@doto241.com', 'booking.requested');
        $this->assertStringNotContainsString($this->partenaire->company_name, $this->texte($auClient));
        $this->assertStringStartsWith('https://doto241.com/mes-reservations', $auClient->lien);
        $auLoueur = $this->envoye('partenaire@doto241.com', 'booking.new_request');
        $this->assertStringNotContainsString('client@doto241.com', $this->texte($auLoueur));

        Sanctum::actingAs($this->partenaire->owner);
        $url = '/api/v1/partenaires/moi/reservations/'.$id;
        $this->postJson($url.'/accepter')->assertOk();
        $this->envoye('client@doto241.com', 'booking.confirmed');

        $this->postJson($url.'/sortie', ['conducteur' => 'Client Démo', 'kilometrage' => 1000, 'niveau_carburant' => 'plein'])->assertOk();
        $this->postJson($url.'/retour', ['kilometrage' => 1200, 'niveau_carburant' => 'plein'])->assertOk();
        $this->envoye('client@doto241.com', 'booking.review_request');

        Sanctum::actingAs($this->client);
        $this->postJson('/api/v1/bookings/'.$id.'/avis', [
            'rating_cleanliness' => 5, 'rating_condition' => 5, 'rating_welcome' => 4, 'rating_value' => 4, 'comment' => 'Parfait',
        ])->assertCreated();
        $this->envoye('partenaire@doto241.com', 'review.received');
    }

    public function test_un_refus_et_une_annulation_previennent_les_bonnes_personnes(): void
    {
        Notification::fake();
        $vehicule = Vehicle::where('partner_id', $this->partenaire->id)->firstOrFail();
        $nouvelle = fn () => Booking::create([
            'reference' => 'NT-'.uniqid(), 'customer_id' => $this->client->id, 'vehicle_id' => $vehicule->id,
            'partner_id' => $this->partenaire->id, 'pickup_at' => now()->addDays(4), 'return_at' => now()->addDays(6),
            'status' => 'pending_partner', 'days_count' => 2, 'total_amount' => 50000,
        ]);

        Sanctum::actingAs($this->partenaire->owner);
        $this->postJson('/api/v1/partenaires/moi/reservations/'.$nouvelle()->id.'/refuser', ['motif' => 'Véhicule en révision'])->assertOk();
        $this->assertStringContainsString('Véhicule en révision', $this->texte($this->envoye('client@doto241.com', 'booking.refused')));

        Sanctum::actingAs($this->client);
        $this->postJson('/api/v1/bookings/'.$nouvelle()->id.'/annuler')->assertOk();
        $this->envoye('partenaire@doto241.com', 'booking.cancelled_partner');
        // Le client qui annule lui-même ne reçoit pas d’e-mail d’annulation.
        $recu = true;
        try {
            $this->envoye('client@doto241.com', 'booking.cancelled');
        } catch (\PHPUnit\Framework\ExpectationFailedException) {
            $recu = false;
        }
        $this->assertFalse($recu);
    }

    public function test_dossier_partenaire_et_pieces(): void
    {
        Notification::fake();
        $candidat = User::where('email', 'client@doto241.com')->firstOrFail();

        Sanctum::actingAs($candidat);
        $this->postJson('/api/v1/partenaires/inscription', [
            'type' => 'company', 'company_name' => 'Okoumé Location', 'manager_name' => 'Paul Obiang',
            'address' => 'Glass', 'city' => 'Libreville', 'phone' => '+24106112233',
        ])->assertCreated();
        $this->envoye('client@doto241.com', 'partner.received');
        $this->envoye('admin@doto241.com', 'admin.partner_received');

        $nouveau = Partner::where('company_name', 'Okoumé Location')->firstOrFail();
        Sanctum::actingAs(User::where('email', 'admin@doto241.com')->firstOrFail());
        $this->postJson('/api/v1/admin/partners/'.$nouveau->id.'/request-info', ['message' => 'RCCM illisible'])->assertOk();
        $this->assertStringContainsString('RCCM illisible', $this->texte($this->envoye('client@doto241.com', 'partner.info_requested')));
        $this->postJson('/api/v1/admin/partners/'.$nouveau->id.'/approve')->assertOk();
        $this->envoye('client@doto241.com', 'partner.approved');
    }

    public function test_rappels_quotidiens_envoyes_une_seule_fois(): void
    {
        Notification::fake();
        $vehicule = Vehicle::where('partner_id', $this->partenaire->id)->firstOrFail();
        Booking::create([
            'reference' => 'RAP-1', 'customer_id' => $this->client->id, 'vehicle_id' => $vehicule->id,
            'partner_id' => $this->partenaire->id, 'pickup_at' => today()->addDay()->setTime(9, 0), 'return_at' => today()->addDays(3),
            'status' => 'confirmed', 'days_count' => 2, 'total_amount' => 50000,
        ]);
        VehicleDocument::create(['vehicle_id' => $vehicule->id, 'type' => 'assurance', 'file_path' => 'x.pdf', 'expires_at' => today()->addDays(7)]);

        $this->artisan('doto:rappels')->assertSuccessful();
        $this->envoye('client@doto241.com', 'booking.reminder_pickup');
        $this->envoye('partenaire@doto241.com', 'booking.reminder_pickup_partner');
        $this->assertStringContainsString($vehicule->plate_number, $this->texte($this->envoye('partenaire@doto241.com', 'document.expiring')));

        Notification::fake();
        $this->artisan('doto:rappels')->expectsOutput('0 rappel(s) envoyé(s).')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_une_panne_d_e_mail_ne_bloque_pas_l_action(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        $vehicule = Vehicle::where('partner_id', $this->partenaire->id)->firstOrFail();

        Sanctum::actingAs($this->client);
        $this->postJson('/api/v1/bookings', [
            'vehicle_id' => $vehicule->id,
            'pickup_at' => now()->addDays(8)->format('Y-m-d 10:00'),
            'return_at' => now()->addDays(9)->format('Y-m-d 10:00'),
            'driver_age' => 30,
            'payment_method' => 'airtel_money',
        ])->assertCreated();
    }
}
