<?php

namespace Tests\Feature;

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\PartnerDocument;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentsPartenaireTest extends TestCase
{
    use RefreshDatabase;

    private User $responsable;

    private Partner $partenaire;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $this->responsable = User::where('email', 'partenaire@doto241.com')->firstOrFail();
        $this->partenaire = $this->responsable->ownedPartner;
    }

    private function pdf(string $nom = 'cni.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($nom, 120, 'application/pdf');
    }

    public function test_le_partenaire_depose_remplace_et_suit_ses_pieces(): void
    {
        Sanctum::actingAs($this->responsable);

        $liste = $this->getJson('/api/v1/partenaires/moi/documents')->assertOk();
        $liste->assertJsonPath('data.complet', false);
        $this->assertContains('À déposer : RCCM.', $liste->json('data.alertes'));

        $this->postJson('/api/v1/partenaires/moi/documents', ['type' => 'piece_identite', 'fichier' => UploadedFile::fake()->create('x.exe', 10)])
            ->assertStatus(422);
        $premier = $this->postJson('/api/v1/partenaires/moi/documents', [
            'type' => 'piece_identite', 'fichier' => $this->pdf(), 'expires_at' => now()->addYears(2)->toDateString(),
        ])->assertCreated()->json('data');
        $this->assertArrayNotHasKey('file_path', $premier);

        $ancienChemin = PartnerDocument::find($premier['id'])->file_path;
        Storage::disk('local')->assertExists($ancienChemin);

        $this->postJson('/api/v1/partenaires/moi/documents', ['type' => 'piece_identite', 'fichier' => $this->pdf('cni-v2.pdf')])->assertCreated();
        $this->assertSame(1, $this->partenaire->documents()->where('type', 'piece_identite')->count());
        Storage::disk('local')->assertMissing($ancienChemin);

        $this->get('/api/v1/partenaires/moi/documents/'.$premier['id'].'/fichier')->assertOk();
    }

    public function test_un_partenaire_ne_telecharge_pas_les_pieces_d_un_autre(): void
    {
        $autre = Partner::where('company_name', 'Bané Service Auto')->firstOrFail();
        Storage::disk('local')->put('documents/partenaires/x/rccm.pdf', 'secret');
        $piece = PartnerDocument::create(['partner_id' => $autre->id, 'type' => 'rccm', 'file_path' => 'documents/partenaires/x/rccm.pdf']);

        Sanctum::actingAs($this->responsable);
        $this->get('/api/v1/partenaires/moi/documents/'.$piece->id.'/fichier')->assertNotFound();

        Sanctum::actingAs(User::where('email', 'client@doto241.com')->firstOrFail());
        $this->get('/api/v1/admin/documents/partenaire/'.$piece->id.'/fichier')->assertForbidden();
    }

    public function test_l_admin_refuse_avec_motif_et_le_complement_relance_la_validation(): void
    {
        Sanctum::actingAs($this->responsable);
        $id = $this->postJson('/api/v1/partenaires/moi/documents', ['type' => 'rccm', 'fichier' => $this->pdf('rccm.pdf')])->json('data.id');

        $admin = User::where('email', 'admin@doto241.com')->firstOrFail();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/partners/'.$this->partenaire->id.'/dossier')->assertOk()
            ->assertJsonPath('data.pieces.1.type', 'rccm')
            ->assertJsonPath('data.pieces.1.document.status', 'pending');
        $this->get('/api/v1/admin/documents/partenaire/'.$id.'/fichier')->assertOk();
        $this->postJson('/api/v1/admin/documents/partenaire/'.$id.'/refuser')->assertStatus(422);
        $this->postJson('/api/v1/admin/documents/partenaire/'.$id.'/refuser', ['motif' => 'Copie illisible'])->assertOk();
        $this->postJson('/api/v1/admin/partners/'.$this->partenaire->id.'/request-info', ['message' => 'RCCM à renvoyer'])->assertOk();

        // Nouvelle instance, comme pour une vraie requête : la relation n’est pas en cache.
        Sanctum::actingAs($this->responsable->fresh());
        $this->getJson('/api/v1/partenaires/moi/documents')->assertOk()
            ->assertJsonPath('data.statut_dossier', 'info_requested')
            ->assertJsonPath('data.pieces.1.document.rejection_reason', 'Copie illisible');
        $this->postJson('/api/v1/partenaires/moi/documents', ['type' => 'rccm', 'fichier' => $this->pdf('rccm-net.pdf')])->assertCreated()
            ->assertJsonPath('data.status', 'pending');
        $this->assertSame(PartnerStatus::Pending, $this->partenaire->fresh()->status);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/documents/partenaire/'.$id.'/accepter')->assertOk()->assertJsonPath('data.status', 'accepted');
    }

    public function test_une_assurance_expiree_retire_le_vehicule_de_la_recherche(): void
    {
        $vehicule = Vehicle::where('partner_id', $this->partenaire->id)->where('plate_number', 'GA-101-A')->firstOrFail();
        Sanctum::actingAs($this->responsable);

        $this->postJson('/api/v1/partenaires/moi/vehicules/'.$vehicule->id.'/documents', ['type' => 'assurance', 'fichier' => $this->pdf('assurance.pdf')])
            ->assertStatus(422);
        $this->postJson('/api/v1/partenaires/moi/vehicules/'.$vehicule->id.'/documents', [
            'type' => 'assurance', 'fichier' => $this->pdf('assurance.pdf'), 'expires_at' => now()->addDays(10)->toDateString(),
        ])->assertCreated()->assertJsonPath('data.echeance', 'bientot');

        $this->assertTrue($this->trouve('Corolla'));

        VehicleDocument::where('vehicle_id', $vehicule->id)->update(['expires_at' => now()->subDay()->toDateString()]);
        $this->assertFalse($this->trouve('Corolla'));
        $this->getJson('/api/v1/vehicles/'.$vehicule->id)->assertNotFound();
        $this->assertContains(
            'Toyota Corolla (GA-101-A) : attestation d’assurance expirée, le véhicule est retiré de la recherche.',
            $this->getJson('/api/v1/partenaires/moi/documents')->json('data.alertes')
        );

        $this->postJson('/api/v1/bookings', [
            'vehicle_id' => $vehicule->id,
            'pickup_at' => now()->addDays(2)->format('Y-m-d H:i'),
            'return_at' => now()->addDays(4)->format('Y-m-d H:i'),
            'driver_age' => 30, 'guest_name' => 'Test', 'guest_email' => 't@t.ga', 'guest_phone' => '+24100000000',
        ])->assertStatus(422);
    }

    private function trouve(string $modele): bool
    {
        $vehicules = $this->getJson('/api/v1/vehicles/search?q='.$modele.'&per_page=50')->assertOk()->json('data');

        return collect($vehicules)->contains(fn ($v) => $v['model'] === $modele);
    }
}
