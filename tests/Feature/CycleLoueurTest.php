<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\MembrePartenaire;
use App\Models\Partner;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CycleLoueurTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partenaire;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->responsable = User::where('email', 'partenaire@doto241.com')->firstOrFail();
        $this->partenaire = $this->responsable->ownedPartner;
    }

    private function demande(string $statut = 'pending_partner', ?Partner $partenaire = null): Booking
    {
        $partenaire ??= $this->partenaire;
        $vehicule = Vehicle::where('partner_id', $partenaire->id)->firstOrFail();
        $client = User::where('email', 'client@doto241.com')->firstOrFail();

        return Booking::create([
            'reference' => 'TST-'.uniqid(),
            'customer_id' => $client->id,
            'vehicle_id' => $vehicule->id,
            'partner_id' => $partenaire->id,
            'pickup_at' => now()->addDays(3),
            'return_at' => now()->addDays(5),
            'status' => $statut,
            'days_count' => 2,
            'subtotal' => 70000,
            'total_amount' => 70000,
            'deposit_amount' => 200000,
            'commission_amount' => 0,
        ]);
    }

    private function url(Booking $r, string $suite = ''): string
    {
        return '/api/v1/partenaires/moi/reservations/'.$r->id.$suite;
    }

    public function test_le_loueur_mene_la_location_de_la_demande_au_retour(): void
    {
        Sanctum::actingAs($this->responsable);
        $r = $this->demande();

        $this->getJson($this->url($r))->assertOk()
            ->assertJsonPath('data.status', 'pending_partner')
            ->assertJsonPath('data.client.email', null)
            ->assertJsonPath('data.solde_restant', 70000);

        $this->postJson($this->url($r, '/accepter'))->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.client.email', 'client@doto241.com');

        $this->postJson($this->url($r, '/reglements'), ['montant' => 80000, 'mode' => 'especes'])->assertStatus(422);
        $this->postJson($this->url($r, '/reglements'), ['montant' => 50000, 'mode' => 'especes'])->assertCreated()
            ->assertJsonPath('data.solde_restant', 20000);

        $this->postJson($this->url($r, '/sortie'), ['conducteur' => 'Jean Mba', 'kilometrage' => 42000, 'niveau_carburant' => 'plein'])
            ->assertOk()->assertJsonPath('data.status', 'ongoing');
        $this->postJson($this->url($r, '/retour'), ['kilometrage' => 41000, 'niveau_carburant' => 'moitie'])->assertStatus(422);
        $this->postJson($this->url($r, '/retour'), ['kilometrage' => 42350, 'niveau_carburant' => 'moitie'])
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $pdf = $this->get($this->url($r, '/etat-des-lieux'));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->get($this->url($r, '/contrat'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_un_refus_exige_un_motif_et_libere_le_vehicule(): void
    {
        Sanctum::actingAs($this->responsable);
        $r = $this->demande();

        $this->postJson($this->url($r, '/refuser'))->assertStatus(422);
        $this->postJson($this->url($r, '/refuser'), ['motif' => 'Véhicule en panne'])->assertOk()
            ->assertJsonPath('data.status', 'refused');

        $this->assertSame(BookingStatus::Refused, $r->fresh()->status);
        $this->assertTrue(app(\App\Services\BookingService::class)->isAvailable($r->vehicle, $r->pickup_at, $r->return_at));
    }

    public function test_un_loueur_ne_voit_pas_les_reservations_d_un_autre(): void
    {
        $autre = Partner::where('company_name', 'Bané Service Auto')->firstOrFail();
        $r = $this->demande('pending_partner', $autre);
        Sanctum::actingAs($this->responsable);

        $this->getJson($this->url($r))->assertNotFound();
        $this->postJson($this->url($r, '/accepter'))->assertNotFound();
        $this->assertSame(BookingStatus::PendingPartner, $r->fresh()->status);
    }

    public function test_la_caution_ne_peut_pas_rendre_plus_que_ce_qui_est_detenu(): void
    {
        Sanctum::actingAs($this->responsable);
        $r = $this->demande('confirmed');

        $this->postJson($this->url($r, '/cautions'), ['type' => 'encaissement', 'montant' => 200000])->assertCreated();
        $this->postJson($this->url($r, '/cautions'), ['type' => 'restitution', 'montant' => 150000])->assertStatus(422);
        $this->postJson($this->url($r, '/cautions'), ['type' => 'restitution', 'montant' => 150000, 'motif' => 'Retour sans dommage'])->assertCreated();
        $this->postJson($this->url($r, '/cautions'), ['type' => 'affectation', 'montant' => 60000, 'motif' => 'Rayure'])->assertStatus(422);

        $this->getJson('/api/v1/partenaires/moi/finances')->assertOk()
            ->assertJsonPath('data.indicateurs.cautions_detenues', 50000)
            ->assertJsonPath('data.indicateurs.chiffre_affaires_facture', 70000);
    }

    public function test_finances_depenses_et_exports(): void
    {
        Sanctum::actingAs($this->responsable);
        $this->demande('completed');

        $this->postJson('/api/v1/partenaires/moi/depenses', ['libelle' => 'Pneus', 'montant' => 30000, 'date_depense' => today()->toDateString()])
            ->assertCreated();
        $this->getJson('/api/v1/partenaires/moi/finances')->assertOk()
            ->assertJsonPath('data.indicateurs.depenses', 30000)
            ->assertJsonPath('data.indicateurs.marge_indicative', 40000)
            ->assertJsonCount(1, 'data.factures');

        foreach (['reservations', 'mouvements', 'finances', 'entretiens'] as $type) {
            $reponse = $this->get('/api/v1/partenaires/moi/exports/'.$type);
            $reponse->assertOk();
            $this->assertStringContainsString(';', $reponse->streamedContent());
        }
    }

    public function test_un_plan_d_entretien_prevoit_puis_recalcule_l_echeance(): void
    {
        Sanctum::actingAs($this->responsable);
        $vehicule = Vehicle::where('partner_id', $this->partenaire->id)->firstOrFail();
        $etranger = Vehicle::where('partner_id', '!=', $this->partenaire->id)->firstOrFail();

        $this->postJson('/api/v1/partenaires/moi/entretiens/plans', ['vehicule_id' => $etranger->id, 'libelle' => 'Vidange', 'periodicite_jours' => 180])
            ->assertStatus(422);
        $this->postJson('/api/v1/partenaires/moi/entretiens/plans', [
            'vehicule_id' => $vehicule->id, 'libelle' => 'Vidange', 'periodicite_jours' => 180, 'periodicite_km' => 5000,
        ])->assertCreated();

        $intervention = $this->getJson('/api/v1/partenaires/moi/entretiens')->assertOk()->json('data.interventions.0');
        $this->assertSame('prevue', $intervention['statut']);

        $this->postJson('/api/v1/partenaires/moi/entretiens/interventions/'.$intervention['id'].'/realiser', [
            'compteur' => 30000, 'realisee_le' => today()->toDateString(),
        ])->assertOk();

        $donnees = $this->getJson('/api/v1/partenaires/moi/entretiens')->json('data');
        $this->assertSame(35000, $donnees['plans'][0]['prochain_kilometrage']);
        $this->assertSame(today()->addDays(180)->toDateString(), $donnees['plans'][0]['prochaine_echeance_le']);
        $this->assertCount(2, $donnees['interventions']);
    }

    public function test_le_responsable_invite_un_agent_aux_droits_limites(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->responsable);

        $this->postJson('/api/v1/partenaires/moi/membres', ['email' => 'client@doto241.com', 'role' => 'partner_agent'])->assertStatus(422);
        $this->postJson('/api/v1/partenaires/moi/membres', ['email' => 'agent@gabon-auto.ga', 'role' => 'partner_agent'])->assertCreated();
        $this->getJson('/api/v1/partenaires/moi/membres')->assertOk()->assertJsonCount(2, 'data');

        $agent = User::where('email', 'agent@gabon-auto.ga')->firstOrFail();
        Notification::assertSentTo($agent, ResetPassword::class);

        Sanctum::actingAs($agent);
        $r = $this->demande();
        $this->postJson($this->url($r, '/accepter'))->assertOk();
        $this->getJson('/api/v1/partenaires/moi/finances')->assertForbidden();
        $this->postJson('/api/v1/partenaires/moi/membres', ['email' => 'x@y.ga', 'role' => 'partner_agent'])->assertForbidden();
        $this->getJson('/api/v1/partenaires/moi/vehicules')->assertOk();

        Sanctum::actingAs($this->responsable);
        $membre = MembrePartenaire::where('user_id', $agent->id)->firstOrFail();
        $this->deleteJson('/api/v1/partenaires/moi/membres/'.$membre->id)->assertOk();
        $this->assertNull($agent->fresh()->partenaireRattache());
    }

    public function test_mot_de_passe_oublie_puis_reinitialise(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/mot-de-passe-oublie', ['email' => 'inconnu@doto241.com'])->assertOk();
        $this->postJson('/api/v1/auth/mot-de-passe-oublie', ['email' => 'CLIENT@doto241.com'])->assertOk()
            ->assertJsonPath('lien', null);

        $client = User::where('email', 'client@doto241.com')->firstOrFail();
        $jeton = null;
        Notification::assertSentTo($client, ResetPassword::class, function (ResetPassword $n) use (&$jeton) {
            $jeton = $n->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reinitialiser-mot-de-passe', [
            'email' => 'client@doto241.com', 'token' => 'faux', 'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertStatus(422);
        $this->postJson('/api/v1/auth/reinitialiser-mot-de-passe', [
            'email' => 'client@doto241.com', 'token' => $jeton, 'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'client@doto241.com', 'password' => 'NouveauMdp2026'])->assertOk();
    }
}
