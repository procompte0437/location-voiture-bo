<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Données de gestion du loueur : états des lieux, cautions, dépenses et entretiens.
 * Les règlements en agence réutilisent la table paiements (passerelle « agence »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etats_des_lieux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('reservations')->cascadeOnDelete();
            $table->string('type'); // depart | retour
            $table->string('conducteur')->nullable();
            $table->unsignedInteger('kilometrage');
            $table->string('niveau_carburant');
            $table->text('notes')->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();
            $table->unique(['booking_id', 'type']);
        });

        Schema::create('mouvements_caution', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partenaires')->cascadeOnDelete();
            $table->string('type'); // encaissement | restitution | affectation
            $table->unsignedInteger('montant');
            $table->string('motif', 500)->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('depenses_partenaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partenaires')->cascadeOnDelete();
            $table->string('libelle');
            $table->unsignedInteger('montant');
            $table->string('categorie')->default('exploitation');
            $table->date('date_depense');
            $table->foreignId('cree_par')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('plans_entretien', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partenaires')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicules')->cascadeOnDelete();
            $table->string('libelle');
            $table->unsignedInteger('periodicite_jours')->nullable();
            $table->unsignedInteger('periodicite_km')->nullable();
            $table->date('prochaine_echeance_le')->nullable();
            $table->unsignedInteger('prochain_kilometrage')->nullable();
            $table->timestamps();
        });

        Schema::create('interventions_entretien', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partenaires')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicules')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans_entretien')->nullOnDelete();
            $table->string('description');
            $table->string('statut')->default('prevue'); // prevue | realisee
            $table->date('prevue_le')->nullable();
            $table->date('realisee_le')->nullable();
            $table->unsignedInteger('compteur')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interventions_entretien');
        Schema::dropIfExists('plans_entretien');
        Schema::dropIfExists('depenses_partenaires');
        Schema::dropIfExists('mouvements_caution');
        Schema::dropIfExists('etats_des_lieux');
    }
};
