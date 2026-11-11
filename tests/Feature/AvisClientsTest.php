<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Partner;
use App\Models\Review;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvisClientsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Partner $partenaire;

    private Vehicle $vehicule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->client = User::where('email', 'client@doto241.com')->firstOrFail();
        $this->partenaire = User::where('email', 'partenaire@doto241.com')->firstOrFail()->ownedPartner;
        $this->vehicule = Vehicle::where('partner_id', $this->partenaire->id)->firstOrFail();
    }

    private function location(string $statut = 'completed', ?User $client = null): Booking
    {
        return Booking::create([
            'reference' => 'AV-'.uniqid(),
            'customer_id' => ($client ?? $this->client)->id,
            'vehicle_id' => $this->vehicule->id,
            'partner_id' => $this->partenaire->id,
            'pickup_at' => now()->subDays(5),
            'return_at' => now()->subDays(2),
            'status' => $statut,
            'days_count' => 3,
            'total_amount' => 90000,
        ]);
    }

    private function notes(int $n = 4): array
    {
        return ['rating_cleanliness' => $n, 'rating_condition' => $n, 'rating_welcome' => 5, 'rating_value' => 3, 'comment' => 'Voiture propre, accueil rapide.'];
    }

    public function test_seul_le_client_d_une_location_terminee_note_une_fois(): void
    {
        Sanctum::actingAs($this->client);
        $enCours = $this->location('ongoing');
        $this->postJson('/api/v1/bookings/'.$enCours->id.'/avis', $this->notes())->assertStatus(422);

        $terminee = $this->location();
        $this->postJson('/api/v1/bookings/'.$terminee->id.'/avis', ['rating_cleanliness' => 6])->assertStatus(422);
        $this->postJson('/api/v1/bookings/'.$terminee->id.'/avis', $this->notes())->assertCreated()
            ->assertJsonPath('data.note', 4)
            ->assertJsonPath('data.auteur', 'Client D.');
        $this->postJson('/api/v1/bookings/'.$terminee->id.'/avis', $this->notes())->assertStatus(422);

        $autre = User::where('email', 'admin@doto241.com')->firstOrFail();
        Sanctum::actingAs($autre);
        $this->postJson('/api/v1/bookings/'.$this->location()->id.'/avis', $this->notes())->assertNotFound();

        $this->partenaire->refresh();
        $this->assertSame(1, (int) $this->partenaire->reviews_count);
        // 4, 4, 5, 3 : la moyenne des critères vaut 4.
        $this->assertEquals(4.0, (float) $this->partenaire->average_rating);
    }

    public function test_la_note_publique_suit_la_moyenne_des_criteres(): void
    {
        Sanctum::actingAs($this->client);
        $this->postJson('/api/v1/bookings/'.$this->location()->id.'/avis', [
            'rating_cleanliness' => 5, 'rating_condition' => 4, 'rating_welcome' => 5, 'rating_value' => 4,
        ])->assertCreated();

        $this->getJson('/api/v1/vehicles/'.$this->vehicule->id.'/avis')->assertJsonPath('data.moyenne', 4.5);
        $this->assertEquals(4.5, (float) $this->partenaire->fresh()->average_rating);
    }

    public function test_le_public_voit_les_avis_sans_le_nom_du_loueur(): void
    {
        Sanctum::actingAs($this->client);
        $this->postJson('/api/v1/bookings/'.$this->location()->id.'/avis', $this->notes())->assertCreated();

        $reponse = $this->getJson('/api/v1/vehicles/'.$this->vehicule->id.'/avis')->assertOk()
            ->assertJsonPath('data.nombre', 1)
            ->assertJsonPath('data.moyenne', 4)
            ->assertJsonPath('data.criteres.2.moyenne', 5);
        $this->assertStringNotContainsString($this->partenaire->company_name, $reponse->getContent());
        $this->assertStringNotContainsString('client@doto241.com', $reponse->getContent());

        $this->getJson('/api/v1/bookings')->assertOk()->assertJsonPath('data.0.review.rating_overall', 4);
    }

    public function test_le_loueur_repond_et_l_admin_modere(): void
    {
        Sanctum::actingAs($this->client);
        $id = $this->postJson('/api/v1/bookings/'.$this->location()->id.'/avis', $this->notes(2))->json('data.id');

        Sanctum::actingAs(Partner::where('company_name', 'Bané Service Auto')->firstOrFail()->owner);
        $this->postJson('/api/v1/partenaires/moi/avis/'.$id.'/repondre', ['reponse' => 'Merci'])->assertNotFound();

        Sanctum::actingAs($this->partenaire->owner);
        $this->getJson('/api/v1/partenaires/moi/avis')->assertOk()->assertJsonPath('data.nombre', 1);
        $this->postJson('/api/v1/partenaires/moi/avis/'.$id.'/repondre', ['reponse' => 'Merci, nous corrigeons cela.'])->assertOk();
        $this->getJson('/api/v1/vehicles/'.$this->vehicule->id.'/avis')->assertJsonPath('data.avis.0.reponse', 'Merci, nous corrigeons cela.');

        Sanctum::actingAs(User::where('email', 'admin@doto241.com')->firstOrFail());
        $this->postJson('/api/v1/admin/avis/'.$id.'/moderer', ['statut' => 'hidden'])->assertStatus(422);
        $this->postJson('/api/v1/admin/avis/'.$id.'/moderer', ['statut' => 'hidden', 'motif' => 'Propos injurieux'])->assertOk();
        $this->getJson('/api/v1/admin/avis?statut=hidden')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/vehicles/'.$this->vehicule->id.'/avis')->assertJsonPath('data.nombre', 0);
        $this->assertSame(0, (int) $this->partenaire->fresh()->reviews_count);
        $this->assertSame('hidden', Review::find($id)->moderation_status);

        Sanctum::actingAs($this->client);
        $this->getJson('/api/v1/admin/avis')->assertForbidden();
    }
}
