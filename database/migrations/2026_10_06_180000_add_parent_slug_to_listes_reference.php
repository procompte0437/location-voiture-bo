<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relie un modèle à sa marque via parent_slug (ex. type=modele, parent_slug=toyota).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listes_reference', function (Blueprint $table) {
            $table->string('parent_slug')->nullable()->after('slug');
            $table->index(['type', 'parent_slug']);
        });
    }

    public function down(): void
    {
        Schema::table('listes_reference', function (Blueprint $table) {
            $table->dropIndex(['type', 'parent_slug']);
            $table->dropColumn('parent_slug');
        });
    }
};
