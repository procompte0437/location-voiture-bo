<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\MessageDoto;
use App\Services\BookingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModesReservationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Partner $partenaire;

    private Vehicle $vehicule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->client = User::where('email', 'client@doto241.com')->firstOrFail();
        $this->partenaire = User::where('email', 'partenaire@doto241.com')->firstOrFail()->ownedPartner;
        $this->vehicule = Vehicle::where('partner_id', $this->partenaire->id)->firstOrFail();
    }

    private function reserver(int $dansJours = 3): int
    {
        Sanctum::actingAs($this->client);

        return $this->postJson('/api/v1/bookings', [
            'vehicle_id' => $this->vehicule->id,
            'pickup_at' => now()->addDays($dansJours)->format('Y-m-d 10:00'),
            'return_at' => now()->addDays($dansJours + 2)->format('Y-m-d 10:00'),
            'driver_age' => 30,
            'payment_method' => 'agency_cash',
        ])->assertCreated()->json('data.id');
    }

    private function aRecu(string $email, string $evenement): bool
    {
        try {
            Notification::assertSentOnDemand(MessageDoto::class, fn (MessageDoto $n, array $c, AnonymousNotifiable $d) => ($d->routes['mail'] ?? null) === $email && $n->evenement === $evenement);

            return true;
        } catch (\PHPUnit\Framework\ExpectationFailedException) {
            return false;
        }
    }

    public function test_instantane_la_reservation_est_confirmee_sans_le_loueur(): void
    {
        $this->vehicule->update(['booking_mode' => 'instant']);
        $id = $this->reserver();

        $reservation = Booking::findOrFail($id);
        $this->assertSame(BookingStatus::Confirmed, $reservation->status);
        $this->assertNotNull($reservation->confirmed_at);
        $this->assertTrue($this->aRecu('client@doto241.com', 'booking.confirmed'));
        $this->assertTrue($this->aRecu('partenaire@doto241.com', 'booking.new_confirmed'));
        $this->assertFalse($this->aRecu('partenaire@doto241.com', 'booking.new_request'));

        // Le créneau est pris : un second client ne peut pas réserver les mêmes dates.
        Sanctum::actingAs(User::where('email', 'admin@doto241.com')->firstOrFail());
        $this->postJson('/api/v1/bookings', [
            'vehicle_id' => $this->vehicule->id,
            'pickup_at' => now()->addDays(3)->format('Y-m-d 12:00'),
            'return_at' => now()->addDays(4)->format('Y-m-d 10:00'),
            'driver_age' => 30,
            'payment_method' => 'agency_cash',
        ])->assertStatus(422);

        // Le loueur passe directement au départ.
        Sanctum::actingAs($this->partenaire->owner);
        $this->postJson('/api/v1/partenaires/moi/reservations/'.$id.'/sortie', ['conducteur' => 'C. Démo', 'kilometrage' => 10, 'niveau_carburant' => 'plein'])
            ->assertOk()->assertJsonPath('data.status', 'ongoing');
    }

    public function test_un_paiement_ne_retrograde_pas_une_reservation_deja_confirmee(): void
    {
        $this->vehicule->update(['booking_mode' => 'instant']);
        $reservation = Booking::findOrFail($this->reserver());
        $paiement = Payment::create(['booking_id' => $reservation->id, 'method' => 'airtel_money', 'amount' => 1000, 'status' => 'paid']);

        app(BookingService::class)->marquerPayee($reservation, $paiement);

        $this->assertSame(BookingStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_sur_demande_sans_reponse_la_demande_expire_et_libere_le_vehicule(): void
    {
        $this->vehicule->update(['booking_mode' => 'on_request']);
        $ancienne = Booking::findOrFail($this->reserver());
        $recente = Booking::findOrFail($this->reserver(10));
        $this->assertSame(BookingStatus::Locked, $ancienne->status);

        $this->travel(11)->hours();
        $this->artisan('doto:expirer-demandes')->expectsOutput('0 demande(s) expirée(s).');

        $recente->forceFill(['created_at' => now()->subHours(1)])->save();
        $this->travel(2)->hours();
        $this->artisan('doto:expirer-demandes')->expectsOutput('1 demande(s) expirée(s).')->assertSuccessful();

        $this->assertSame(BookingStatus::Expired, $ancienne->fresh()->status);
        $this->assertSame(BookingStatus::Locked, $recente->fresh()->status);
        $this->assertTrue($this->aRecu('client@doto241.com', 'booking.expired'));
        $this->assertTrue($this->aRecu('partenaire@doto241.com', 'booking.expired_partner'));
        $this->assertTrue(app(BookingService::class)->isAvailable($this->vehicule, $ancienne->pickup_at, $ancienne->return_at));

        Sanctum::actingAs($this->partenaire->owner);
        $this->postJson('/api/v1/partenaires/moi/reservations/'.$ancienne->id.'/accepter')->assertStatus(422);
    }

    public function test_le_delai_de_reponse_se_regle(): void
    {
        config(['locagabon.delai_reponse_heures' => 2]);
        $this->vehicule->update(['booking_mode' => 'on_request']);
        $id = $this->reserver();

        $this->travel(3)->hours();
        $this->artisan('doto:expirer-demandes')->expectsOutput('1 demande(s) expirée(s).');
        $this->assertSame(BookingStatus::Expired, Booking::findOrFail($id)->status);
    }
}
