<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PartnerStatus;
use App\Enums\VehicleStatus;
use App\Models\Booking;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaiementMobileTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://pvit.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pvit.url' => self::API,
            'services.pvit.compte' => 'ACC_TEST',
            'services.pvit.mot_de_passe' => 'secret',
            'services.pvit.code_secret' => 'CSEC',
            'services.pvit.code_paiement' => 'CPAY',
            'services.pvit.code_statut' => 'CSTA',
            'services.pvit.code_callback' => 'CCAL',
            'services.pvit.montant_test' => null,
        ]);
    }

    private function reservation(int $montant = 60000): Booking
    {
        $proprietaire = User::create([
            'name' => 'Loueur', 'email' => 'loueur@example.test', 'password' => 'password', 'role' => 'partner', 'status' => 'active',
        ]);
        $partenaire = Partner::create([
            'owner_user_id' => $proprietaire->id, 'type' => 'agency', 'company_name' => 'Agence', 'status' => PartnerStatus::Approved, 'trust_level' => 'verified',
        ]);
        $vehicule = Vehicle::create([
            'partner_id' => $partenaire->id, 'brand' => 'Toyota', 'model' => 'Yaris', 'category' => 'city_car', 'plate_number' => 'GA-TEST-1',
            'seats' => 5, 'transmission' => 'manual', 'fuel' => 'petrol', 'price_per_day' => 20000, 'status' => VehicleStatus::Published,
        ]);

        return Booking::create([
            'reference' => 'LG-ABCD1234', 'guest_email' => 'client@example.test', 'guest_name' => 'Client', 'vehicle_id' => $vehicule->id,
            'partner_id' => $partenaire->id, 'pickup_at' => now()->addDays(2), 'return_at' => now()->addDays(5), 'driver_age' => 30,
            'status' => BookingStatus::Locked, 'days_count' => 3, 'subtotal' => $montant, 'extras_total' => 0, 'total_amount' => $montant,
            'deposit_amount' => 0, 'commission_amount' => 0, 'commission_rate' => 0, 'locked_until' => now()->addMinutes(10),
        ]);
    }

    private function fauxPvit(string $statut = 'SUCCESS', int $montant = 60000): void
    {
        Http::fake([
            self::API.'/v2/CSEC/renew-secret' => Http::response(['secret' => 'sk_test', 'expires_in' => 3600]),
            self::API.'/v2/CPAY/rest' => Http::response(['status' => 'PENDING', 'reference_id' => 'PAY001', 'status_code' => '200']),
            self::API.'/CSTA/status*' => function ($requete) use ($statut, $montant) {
                $reference = Payment::query()->latest('id')->value('transaction_ref');

                return Http::response(['status' => $statut, 'amount' => $montant, 'merchant_reference_id' => $reference]);
            },
        ]);
    }

    private function lancer(Booking $reservation, array $corps = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/bookings/{$reservation->id}/paiement-mobile", [
            'reference' => $reservation->reference,
            'moyen' => 'airtel_money',
            'numero' => '+241 74 11 11 11',
            ...$corps,
        ]);
    }

    public function test_le_paiement_lance_reste_en_attente_et_envoie_le_numero_local(): void
    {
        $this->fauxPvit();
        $reservation = $this->reservation();

        $this->lancer($reservation)->assertCreated()->assertJsonPath('data.statut', 'pending');

        Http::assertSent(fn ($requete) => str_contains($requete->url(), '/CPAY/rest')
            && $requete['customer_account_number'] === '074111111'
            && $requete['operator_code'] === 'AIRTEL_MONEY'
            && $requete['amount'] === 60000
            && $requete->hasHeader('X-Secret', 'sk_test'));
        $this->assertSame(BookingStatus::Locked, $reservation->fresh()->status);
    }

    public function test_le_webhook_ne_suffit_pas_le_statut_vient_de_lapi(): void
    {
        $this->fauxPvit('FAILED');
        $reservation = $this->reservation();
        $this->lancer($reservation)->assertCreated();
        $paiement = Payment::query()->latest('id')->first();

        $this->postJson('/api/v1/paiements/pvit/notification', [
            'transactionId' => 'PAY001',
            'merchantReferenceId' => $paiement->transaction_ref,
            'status' => 'SUCCESS',
            'amount' => 60000,
            'code' => 200,
        ])->assertOk()->assertExactJson(['transactionId' => 'PAY001', 'responseCode' => 200]);

        $this->assertSame('failed', $paiement->fresh()->status);
        $this->assertSame(BookingStatus::Locked, $reservation->fresh()->status);
    }

    public function test_un_paiement_reussi_passe_la_reservation_chez_le_partenaire_une_seule_fois(): void
    {
        $this->fauxPvit();
        $reservation = $this->reservation();
        $this->lancer($reservation)->assertCreated();
        $paiement = Payment::query()->latest('id')->first();
        $corps = ['transactionId' => 'PAY001', 'merchantReferenceId' => $paiement->transaction_ref, 'code' => 200];

        $this->postJson('/api/v1/paiements/pvit/notification', $corps)->assertOk();
        $paye = $paiement->fresh()->paid_at;
        $this->postJson('/api/v1/paiements/pvit/notification', $corps)->assertOk();

        $this->assertSame('paid', $paiement->fresh()->status);
        $this->assertEquals($paye, $paiement->fresh()->paid_at);
        $this->assertSame(BookingStatus::PendingPartner, $reservation->fresh()->status);
    }

    public function test_un_montant_different_ne_valide_pas_le_paiement(): void
    {
        $this->fauxPvit('SUCCESS', 150);
        $reservation = $this->reservation();
        $this->lancer($reservation)->assertCreated();

        $this->getJson("/api/v1/bookings/{$reservation->id}/paiement?reference={$reservation->reference}")
            ->assertOk()->assertJsonPath('data.statut', 'pending');

        $this->assertSame(BookingStatus::Locked, $reservation->fresh()->status);
    }

    public function test_une_mauvaise_reference_est_refusee(): void
    {
        $this->fauxPvit();
        $reservation = $this->reservation();

        $this->lancer($reservation, ['reference' => 'LG-FAUSSE'])->assertNotFound();
        $this->getJson("/api/v1/bookings/{$reservation->id}/paiement?reference=LG-FAUSSE")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_un_numero_invalide_et_un_montant_trop_eleve_sont_refuses(): void
    {
        $this->fauxPvit();

        $reservation = $this->reservation();
        $this->lancer($reservation, ['numero' => '123'])->assertUnprocessable()->assertJsonValidationErrors('numero');

        $reservation->update(['total_amount' => 600000]);
        $this->lancer($reservation)->assertUnprocessable()->assertJsonValidationErrors('moyen');
        Http::assertNothingSent();
    }

    public function test_le_paiement_est_ferme_sans_identifiants_pvit(): void
    {
        config(['services.pvit.compte' => null]);
        $reservation = $this->reservation();

        $this->getJson('/api/v1/catalog/paiement')->assertOk()->assertJsonPath('data.en_ligne', false);
        $this->lancer($reservation)->assertUnprocessable()->assertJsonValidationErrors('moyen');
    }

    public function test_la_route_de_paiement_simule_est_fermee_par_defaut(): void
    {
        $reservation = $this->reservation();

        $this->postJson("/api/v1/bookings/{$reservation->id}/pay", ['method' => 'airtel_money'])->assertStatus(410);
        $this->assertSame(0, Payment::query()->count());
    }
}
