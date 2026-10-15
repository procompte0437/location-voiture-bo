<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages_contact', function (Blueprint $table) {
            $table->id();
            // Compte connecté au moment de l’envoi, s’il y en a un.
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->string('nom');
            $table->string('email');
            $table->string('telephone')->nullable();
            $table->string('sujet');
            $table->text('message');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_contact');
    }
};
