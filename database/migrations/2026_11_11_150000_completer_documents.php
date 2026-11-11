<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le motif de refus manquait aux pièces des véhicules, et le nom d’origine
 * du fichier sert à le reconnaître au téléchargement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_vehicules', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('status');
            $table->string('nom_original')->nullable()->after('file_path');
            $table->index(['vehicle_id', 'type']);
        });

        Schema::table('documents_partenaires', function (Blueprint $table) {
            $table->string('nom_original')->nullable()->after('file_path');
            $table->index(['partner_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('documents_vehicules', function (Blueprint $table) {
            $table->dropIndex(['vehicle_id', 'type']);
            $table->dropColumn(['rejection_reason', 'nom_original']);
        });

        Schema::table('documents_partenaires', function (Blueprint $table) {
            $table->dropIndex(['partner_id', 'type']);
            $table->dropColumn('nom_original');
        });
    }
};
