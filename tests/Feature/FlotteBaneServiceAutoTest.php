<?php

namespace Tests\Feature;

use App\Models\Partner;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ImportVehiculesExcel;
use App\Support\ClasseurExcel;
use Database\Seeders\BaneServiceAutoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlotteBaneServiceAutoTest extends TestCase
{
    use RefreshDatabase;

    private string $fichierLivre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fichierLivre = database_path('imports/flotte-bane-service-auto.xlsx');
    }

    private function partenaire(): Partner
    {
        return Partner::where('company_name', BaneServiceAutoSeeder::ENTREPRISE)->firstOrFail();
    }

    public function test_le_seeder_publie_la_flotte_sauf_le_modele_inconnu(): void
    {
        $vehicules = $this->partenaire()->vehicles()->with('media')->get();

        $this->assertCount(67, $vehicules);
        $this->assertCount(67, $vehicules->pluck('plate_number')->unique());
        $this->assertSame(66, $vehicules->where('status.value', 'published')->count());
        $this->assertSame('pending_validation', $vehicules->firstWhere('plate_number', 'MQ-814-AA')->status->value);
        $this->assertSame(67, $vehicules->filter(fn ($v) => $v->media->count() === 1)->count());
        $this->assertTrue($vehicules->every(fn ($v) => $v->booking_mode === 'on_request'));
    }

    public function test_les_photos_de_la_flotte_existent(): void
    {
        foreach ($this->partenaire()->vehicles()->with('media')->get() as $vehicule) {
            $chemin = parse_url($vehicule->media->first()->url, PHP_URL_PATH);
            $this->assertFileExists(public_path(ltrim($chemin, '/')));
        }
    }

    public function test_relancer_le_seeder_ne_touche_pas_aux_corrections(): void
    {
        Vehicle::where('plate_number', 'KR-741-AA')->update(['price_per_day' => 123000]);

        $this->seed(BaneServiceAutoSeeder::class);

        $this->assertSame(123000, (int) Vehicle::where('plate_number', 'KR-741-AA')->value('price_per_day'));
        $this->assertSame(67, $this->partenaire()->vehicles()->count());
        $this->assertSame(1, User::where('email', 'baneservicesauto@gmail.com')->count());
    }

    public function test_un_app_url_sans_https_donne_quand_meme_une_adresse_valide(): void
    {
        config(['app.url' => 'location-voiture-bo-production.up.railway.app']);

        $this->assertSame(
            'https://location-voiture-bo-production.up.railway.app/images/vehicules/bane/kia-k5.jpg',
            BaneServiceAutoSeeder::adressePhoto('images/vehicules/bane/kia-k5.jpg')
        );
    }

    public function test_relancer_le_seeder_repare_les_photos_mal_enregistrees(): void
    {
        $avante = Vehicle::where('plate_number', 'LY-927-AA')->firstOrFail();
        $avante->media()->update(['url' => 'http://localhost/location-voiture-bo-production.up.railway.app/images/vehicules/bane/hyundai-avante.jpg']);
        $genesis = Vehicle::where('plate_number', 'KR-741-AA')->firstOrFail();
        $genesis->media()->update(['url' => 'https://exemple.com/vraie-photo.jpg']);
        config(['app.url' => 'location-voiture-bo-production.up.railway.app']);

        $this->seed(BaneServiceAutoSeeder::class);

        $this->assertSame(
            'https://location-voiture-bo-production.up.railway.app/images/vehicules/bane/hyundai-avante.jpg',
            $avante->media()->value('url')
        );
        $this->assertSame('https://exemple.com/vraie-photo.jpg', $genesis->media()->value('url'));
        $this->assertSame(1, $avante->media()->count());
    }

    public function test_le_fichier_livre_est_a_jour(): void
    {
        $attendu = ClasseurExcel::lireFeuille($this->ecrireTemporaire(BaneServiceAutoSeeder::classeurExcel()), ['marque', 'immatriculation']);

        $this->assertEquals($attendu, ClasseurExcel::lireFeuille($this->fichierLivre, ['marque', 'immatriculation']));
    }

    public function test_le_fichier_livre_cree_la_flotte_avec_ses_photos(): void
    {
        // Seuls l’entreprise et son agence restent : le fichier doit tout recréer.
        $partenaire = $this->partenaire();
        foreach ($partenaire->vehicles as $vehicule) {
            $vehicule->media()->delete();
            $vehicule->delete();
        }

        $resultat = app(ImportVehiculesExcel::class)->importer($this->fichierLivre, null, null);

        $this->assertSame([], $resultat['erreurs']);
        $this->assertSame(67, $resultat['crees']);
        $this->assertSame(BaneServiceAutoSeeder::ENTREPRISE, $resultat['partenaire']);

        $i30 = Vehicle::where('plate_number', 'KW-106-AA')->with('media', 'agency')->firstOrFail();
        $this->assertSame((int) $partenaire->id, (int) $i30->partner_id);
        $this->assertSame(BaneServiceAutoSeeder::AGENCE, $i30->agency->name);
        $this->assertSame(50000, (int) $i30->price_per_day);
        $this->assertStringEndsWith('/images/vehicules/bane/hyundai-i30.jpg', $i30->media->first()->url);
        $this->assertStringStartsWith('http', $i30->media->first()->url);
        $this->assertSame('manual', Vehicle::where('plate_number', 'HX-476-AA')->value('transmission'));
        $this->assertSame(2018, (int) Vehicle::where('plate_number', 'ML-910-AA')->value('year'));
    }

    public function test_reimporter_met_a_jour_sans_doubler_les_photos(): void
    {
        $resultat = app(ImportVehiculesExcel::class)->importer($this->fichierLivre, null, null);

        $this->assertSame([], $resultat['erreurs']);
        $this->assertSame(67, $resultat['mis_a_jour']);
        $this->assertSame(0, $resultat['crees']);
        $this->assertSame(1, Vehicle::where('plate_number', 'KW-106-AA')->firstOrFail()->media()->count());
    }

    public function test_le_modele_exporte_se_reimporte_en_gardant_les_photos(): void
    {
        $service = app(ImportVehiculesExcel::class);
        $avant = Vehicle::where('plate_number', 'KW-106-AA')->firstOrFail()->media()->value('url');

        $resultat = $service->importer($this->ecrireTemporaire($service->modele()), null, null);

        $this->assertSame([], $resultat['erreurs']);
        $this->assertSame($avant, Vehicle::where('plate_number', 'KW-106-AA')->firstOrFail()->media()->value('url'));
    }

    public function test_une_photo_dangereuse_est_refusee(): void
    {
        $classeur = ClasseurExcel::ecrire(['Véhicules' => [
            ['Propriétaire', 'Marque', 'Modèle', 'Catégorie', 'Immatriculation', 'Prix par jour', 'Photo'],
            [BaneServiceAutoSeeder::ENTREPRISE, 'Kia', 'K5', 'Berline', 'TEST-1', '50000', 'javascript:alert(1)'],
            [BaneServiceAutoSeeder::ENTREPRISE, 'Kia', 'K5', 'Berline', 'TEST-2', '50000', '../../.env.jpg'],
        ]]);

        $resultat = app(ImportVehiculesExcel::class)->importer($this->ecrireTemporaire($classeur), null, null);

        $this->assertCount(2, $resultat['erreurs']);
        $this->assertSame(0, Vehicle::whereIn('plate_number', ['TEST-1', 'TEST-2'])->count());
    }

    private function ecrireTemporaire(string $contenu): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'bane').'.xlsx';
        file_put_contents($chemin, $contenu);
        $this->beforeApplicationDestroyed(fn () => @unlink($chemin));

        return $chemin;
    }
}
