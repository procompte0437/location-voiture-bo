<?php

namespace App\Http\Controllers\Api\Partenaire;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\DepensePartenaire;
use App\Models\InterventionEntretien;
use App\Models\MembrePartenaire;
use App\Models\MouvementCaution;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PlanEntretien;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Écrans de gestion du loueur : finances, dépenses, entretiens, équipe et exports.
 */
class ControleurGestionLoueur extends Controller
{
    use ResoutPartenaire;

    private const STATUTS_FACTURES = ['confirmed', 'ongoing', 'completed'];

    public function __construct(private AuditService $audit) {}

    public function finances(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaire($requete, ['finance_manager']);
        $reservations = $partenaire->bookings()->whereIn('status', self::STATUTS_FACTURES);

        $chiffreAffaires = (int) (clone $reservations)->sum('total_amount');
        $commission = (int) (clone $reservations)->sum('commission_amount');
        $encaisse = (int) Payment::query()
            ->whereIn('booking_id', (clone $reservations)->select('id'))
            ->where('status', 'paid')
            ->sum('amount');
        $mouvements = MouvementCaution::query()->where('partner_id', $partenaire->id)->latest()->get();
        $depenses = (int) DepensePartenaire::query()->where('partner_id', $partenaire->id)->sum('montant');

        return response()->json(['data' => [
            'indicateurs' => [
                'chiffre_affaires_facture' => $chiffreAffaires,
                'commission' => $commission,
                'encaissements_nets' => $encaisse,
                'cautions_detenues' => (int) $mouvements->where('type', 'encaissement')->sum('montant')
                    - (int) $mouvements->whereIn('type', ['restitution', 'affectation'])->sum('montant'),
                'solde_clients' => max(0, $chiffreAffaires - $encaisse),
                'depenses' => $depenses,
                'marge_indicative' => $chiffreAffaires - $commission - $depenses,
            ],
            'factures' => $this->factures($partenaire),
            'cautions' => $mouvements->values(),
            'depenses' => DepensePartenaire::query()->where('partner_id', $partenaire->id)->latest('date_depense')->limit(200)->get(),
        ]]);
    }

    public function enregistrerDepense(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaire($requete, ['finance_manager']);
        $donnees = $requete->validate([
            'libelle' => ['required', 'string', 'max:255'],
            'montant' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'date_depense' => ['required', 'date', 'before_or_equal:today'],
            'categorie' => ['nullable', 'string', 'max:60'],
        ]);

        $depense = DepensePartenaire::create([
            ...$donnees,
            'categorie' => $donnees['categorie'] ?? 'exploitation',
            'partner_id' => $partenaire->id,
            'cree_par' => $requete->user()->id,
        ]);
        $this->audit->log('partner.expense_recorded', $partenaire, null, $depense->toArray());

        return response()->json(['message' => 'Dépense enregistrée.', 'data' => $depense], 201);
    }

    public function entretiens(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaire($requete, ['partner_agent']);

        return response()->json(['data' => [
            'plans' => PlanEntretien::query()->where('partner_id', $partenaire->id)
                ->with('vehicule:id,brand,model,plate_number')->orderBy('prochaine_echeance_le')->get(),
            'interventions' => InterventionEntretien::query()->where('partner_id', $partenaire->id)
                ->with('vehicule:id,brand,model,plate_number')
                ->orderByRaw("case when statut = 'prevue' then 0 else 1 end")->orderBy('prevue_le')->limit(300)->get(),
            'vehicules' => $partenaire->vehicles()->orderBy('brand')->orderBy('model')->get(['id', 'brand', 'model', 'plate_number']),
        ]]);
    }

    /** Un plan crée aussitôt sa première intervention prévue. */
    public function creerPlan(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaire($requete, ['partner_agent']);
        $donnees = $requete->validate([
            'vehicule_id' => ['required', 'integer', Rule::exists('vehicules', 'id')->where('partner_id', $partenaire->id)],
            'libelle' => ['required', 'string', 'max:120'],
            'periodicite_jours' => ['nullable', 'integer', 'min:1', 'max:3650', 'required_without:periodicite_km'],
            'periodicite_km' => ['nullable', 'integer', 'min:100', 'max:1000000'],
            'prochaine_echeance_le' => ['nullable', 'date'],
            'prochain_kilometrage' => ['nullable', 'integer', 'min:0'],
        ], [
            'vehicule_id.exists' => 'Choisissez un véhicule de votre flotte.',
            'periodicite_jours.required_without' => 'Indiquez une périodicité en jours ou en kilomètres.',
        ]);

        $plan = DB::transaction(function () use ($donnees, $partenaire) {
            $plan = PlanEntretien::create([
                'partner_id' => $partenaire->id,
                'vehicle_id' => $donnees['vehicule_id'],
                'libelle' => $donnees['libelle'],
                'periodicite_jours' => $donnees['periodicite_jours'] ?? null,
                'periodicite_km' => $donnees['periodicite_km'] ?? null,
                'prochaine_echeance_le' => $donnees['prochaine_echeance_le'] ?? today(),
                'prochain_kilometrage' => $donnees['prochain_kilometrage'] ?? null,
            ]);
            $this->prevoir($plan);

            return $plan;
        });
        $this->audit->log('partner.maintenance_plan_created', $plan, null, $plan->toArray());

        return response()->json(['message' => 'Plan d’entretien créé.', 'data' => $plan->load('vehicule')], 201);
    }

    /** Réaliser une intervention recalcule l’échéance du plan et prévoit la suivante. */
    public function realiserIntervention(Request $requete, int $id): JsonResponse
    {
        $partenaire = $this->partenaire($requete, ['partner_agent']);
        $intervention = InterventionEntretien::query()->where('partner_id', $partenaire->id)->findOrFail($id);
        $donnees = $requete->validate([
            'compteur' => ['required', 'integer', 'min:0', 'max:2000000'],
            'realisee_le' => ['required', 'date', 'before_or_equal:today'],
        ]);

        if ($intervention->statut !== 'prevue') {
            throw ValidationException::withMessages(['statut' => ['Cette intervention est déjà réalisée.']]);
        }

        DB::transaction(function () use ($intervention, $donnees) {
            $intervention->update([...$donnees, 'statut' => 'realisee']);

            $plan = $intervention->plan;
            if ($plan) {
                $date = Carbon::parse($donnees['realisee_le']);
                $plan->update([
                    'prochaine_echeance_le' => $plan->periodicite_jours ? $date->copy()->addDays($plan->periodicite_jours) : null,
                    'prochain_kilometrage' => $plan->periodicite_km ? $donnees['compteur'] + $plan->periodicite_km : null,
                ]);
                $this->prevoir($plan->fresh());
            }
        });
        $this->audit->log('partner.maintenance_done', $intervention, null, $intervention->fresh()->toArray());

        return response()->json(['message' => 'Intervention réalisée.', 'data' => $intervention->fresh()]);
    }

    public function membres(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaire($requete, ['partner_agent', 'finance_manager']);

        $responsable = $partenaire->owner;
        $membres = MembrePartenaire::query()->where('partner_id', $partenaire->id)
            ->with('utilisateur:id,name,email')->get()
            ->map(fn (MembrePartenaire $m) => ['id' => $m->id, 'role' => $m->role, 'utilisateur' => $m->utilisateur]);

        return response()->json(['data' => collect([[
            'id' => 0,
            'role' => 'responsable',
            'utilisateur' => $responsable?->only(['id', 'name', 'email']),
        ]])->concat($membres)->values()]);
    }

    /**
     * Seul le responsable invite. Le compte créé reçoit un lien pour choisir son mot de passe.
     */
    public function inviterMembre(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaire($requete, []);
        $donnees = $requete->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(['partner_agent', 'finance_manager'])],
            'nom' => ['nullable', 'string', 'max:120'],
        ]);
        $email = mb_strtolower(trim($donnees['email']));

        $compte = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($compte) {
            $role = $compte->role instanceof UserRole ? $compte->role : UserRole::tryFrom((string) $compte->role);
            if ($role !== UserRole::PartnerAgent || $compte->adhesionPartenaire) {
                throw ValidationException::withMessages([
                    'email' => ['Cette adresse a déjà un compte DOTO : invitez une adresse dédiée à l’équipe.'],
                ]);
            }
        }

        $membre = DB::transaction(function () use ($compte, $email, $donnees, $partenaire) {
            $compte ??= User::create([
                'name' => $donnees['nom'] ?? Str::before($email, '@'),
                'email' => $email,
                'password' => Hash::make(Str::password(32)),
                'role' => UserRole::PartnerAgent,
                'status' => 'active',
                'locale' => 'fr',
            ]);

            return MembrePartenaire::create(['partner_id' => $partenaire->id, 'user_id' => $compte->id, 'role' => $donnees['role']]);
        });

        Password::broker()->sendResetLink(['email' => $email]);
        $this->audit->log('partner.member_invited', $partenaire, null, $membre->toArray());

        return response()->json([
            'message' => 'Invitation envoyée à '.$email.' : un lien lui permet de choisir son mot de passe.',
            'data' => $membre->load('utilisateur:id,name,email'),
        ], 201);
    }

    public function retirerMembre(Request $requete, int $id): JsonResponse
    {
        $partenaire = $this->partenaire($requete, []);
        $membre = MembrePartenaire::query()->where('partner_id', $partenaire->id)->findOrFail($id);
        $avant = $membre->toArray();
        $membre->utilisateur?->tokens()->delete();
        $membre->delete();
        $this->audit->log('partner.member_removed', $partenaire, $avant, null);

        return response()->json(['message' => 'Collaborateur retiré de l’équipe.']);
    }

    public function exporter(Request $requete, string $type): StreamedResponse
    {
        $partenaire = $this->partenaire($requete, $type === 'entretiens' || $type === 'reservations' ? ['partner_agent', 'finance_manager'] : ['finance_manager']);

        $lignes = match ($type) {
            'reservations' => $this->lignesReservations($partenaire),
            'mouvements' => $this->lignesMouvements($partenaire),
            'finances' => $this->lignesFactures($partenaire),
            'entretiens' => $this->lignesEntretiens($partenaire),
            default => abort(404),
        };
        $this->audit->log('partner.export', $partenaire, null, null, null, ['type' => $type]);

        return response()->streamDownload(function () use ($lignes) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF"); // Excel ouvre l’UTF-8 correctement
            foreach ($lignes as $ligne) {
                fputcsv($sortie, array_map(fn ($v) => $this->celluleSure($v), $ligne), ';');
            }
            fclose($sortie);
        }, $type.'-doto.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function prevoir(PlanEntretien $plan): void
    {
        if (! $plan->prochaine_echeance_le && ! $plan->prochain_kilometrage) {
            return;
        }

        InterventionEntretien::create([
            'partner_id' => $plan->partner_id,
            'vehicle_id' => $plan->vehicle_id,
            'plan_id' => $plan->id,
            'description' => $plan->libelle.($plan->prochain_kilometrage ? ' (à '.number_format($plan->prochain_kilometrage, 0, ',', ' ').' km)' : ''),
            'statut' => 'prevue',
            'prevue_le' => $plan->prochaine_echeance_le,
        ]);
    }

    /** Facture de location : une par réservation confirmée, numérotée sur sa référence. */
    private function factures(Partner $partenaire): array
    {
        return $partenaire->bookings()->whereIn('status', self::STATUTS_FACTURES)->latest('confirmed_at')->limit(500)->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'numero' => 'FAC-'.($r->reference ?: $r->id),
                'type' => 'location',
                'montant_ttc' => (int) $r->total_amount,
                'commission' => (int) $r->commission_amount,
                'emise_le' => ($r->confirmed_at ?? $r->created_at)?->format('Y-m-d'),
                'reservation_id' => $r->id,
            ])->all();
    }

    private function lignesReservations(Partner $partenaire): array
    {
        $lignes = [['Référence', 'Statut', 'Véhicule', 'Immatriculation', 'Départ', 'Retour', 'Jours', 'Total FCFA', 'Commission FCFA', 'Caution FCFA']];
        foreach ($partenaire->bookings()->with('vehicle')->latest()->limit(5000)->get() as $r) {
            $lignes[] = [
                $r->reference, $r->status instanceof \BackedEnum ? $r->status->value : $r->status,
                trim(($r->vehicle?->brand ?? '').' '.($r->vehicle?->model ?? '')), $r->vehicle?->plate_number,
                $r->pickup_at?->format('Y-m-d H:i'), $r->return_at?->format('Y-m-d H:i'), $r->days_count,
                $r->total_amount, $r->commission_amount, $r->deposit_amount,
            ];
        }

        return $lignes;
    }

    private function lignesMouvements(Partner $partenaire): array
    {
        $lignes = [['Date', 'Nature', 'Réservation', 'Type', 'Montant FCFA', 'Détail']];
        $ids = $partenaire->bookings()->pluck('reference', 'id');
        foreach (Payment::query()->whereIn('booking_id', $ids->keys())->where('status', 'paid')->orderBy('paid_at')->get() as $p) {
            $lignes[] = [$p->paid_at?->format('Y-m-d H:i'), 'règlement', $ids[$p->booking_id] ?? $p->booking_id, $p->method, $p->amount, $p->gateway];
        }
        foreach (MouvementCaution::query()->where('partner_id', $partenaire->id)->orderBy('created_at')->get() as $m) {
            $lignes[] = [$m->created_at?->format('Y-m-d H:i'), 'caution', $ids[$m->booking_id] ?? $m->booking_id, $m->type, $m->montant, $m->motif];
        }
        foreach (DepensePartenaire::query()->where('partner_id', $partenaire->id)->orderBy('date_depense')->get() as $d) {
            $lignes[] = [$d->date_depense?->format('Y-m-d'), 'dépense', '', $d->categorie, $d->montant, $d->libelle];
        }

        return $lignes;
    }

    private function lignesFactures(Partner $partenaire): array
    {
        $lignes = [['Numéro', 'Type', 'Émise le', 'Montant TTC FCFA', 'Commission FCFA', 'Réservation']];
        foreach ($this->factures($partenaire) as $f) {
            $lignes[] = [$f['numero'], $f['type'], $f['emise_le'], $f['montant_ttc'], $f['commission'], $f['reservation_id']];
        }

        return $lignes;
    }

    private function lignesEntretiens(Partner $partenaire): array
    {
        $lignes = [['Véhicule', 'Immatriculation', 'Opération', 'Statut', 'Prévue le', 'Réalisée le', 'Compteur km']];
        $interventions = InterventionEntretien::query()->where('partner_id', $partenaire->id)->with('vehicule')->orderBy('prevue_le')->get();
        foreach ($interventions as $i) {
            $lignes[] = [
                trim(($i->vehicule?->brand ?? '').' '.($i->vehicule?->model ?? '')), $i->vehicule?->plate_number,
                $i->description, $i->statut, $i->prevue_le?->format('Y-m-d'), $i->realisee_le?->format('Y-m-d'), $i->compteur,
            ];
        }

        return $lignes;
    }

    /** Empêche l’injection de formules quand le CSV est ouvert dans un tableur. */
    private function celluleSure(mixed $valeur): string
    {
        $texte = (string) ($valeur ?? '');

        return preg_match('/^[=+\-@\t\r]/', $texte) ? "'".$texte : $texte;
    }
}
