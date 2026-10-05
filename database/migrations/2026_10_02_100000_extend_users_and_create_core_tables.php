<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profils_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('nationality')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('license_number')->nullable();
            $table->date('license_obtained_at')->nullable();
            $table->string('license_front_path')->nullable();
            $table->string('license_back_path')->nullable();
            $table->string('id_document_path')->nullable();
            $table->timestamps();
        });

        Schema::create('partenaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('type');
            $table->string('company_name')->nullable();
            $table->string('rccm')->nullable();
            $table->string('nif')->nullable();
            $table->string('manager_name')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('website')->nullable();
            $table->string('status')->default('pending');
            $table->string('trust_level')->default('new');
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->foreignId('validated_by')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('documents_partenaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partenaires')->cascadeOnDelete();
            $table->string('type');
            $table->string('file_path');
            $table->string('status')->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('membres_partenaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partenaires')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('role')->default('agent');
            $table->timestamps();
            $table->unique(['partner_id', 'user_id']);
        });

        Schema::create('lieux', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type');
            $table->string('city')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('agences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partenaires')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('lieux')->nullOnDelete();
            $table->string('name');
            $table->string('address');
            $table->string('city');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('opening_hours')->nullable();
            $table->json('closed_days')->nullable();
            $table->unsignedInteger('after_hours_fee')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vehicules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partenaires')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agences')->nullOnDelete();
            $table->string('brand');
            $table->string('model');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('category');
            $table->string('plate_number')->unique();
            $table->string('color')->nullable();
            $table->unsignedTinyInteger('seats')->default(5);
            $table->unsignedTinyInteger('doors')->default(4);
            $table->unsignedTinyInteger('luggage')->default(2);
            $table->string('transmission')->default('manual');
            $table->string('fuel')->default('petrol');
            $table->boolean('air_conditioning')->default(true);
            $table->unsignedInteger('included_km')->nullable();
            $table->unsignedInteger('deposit_amount')->default(0);
            $table->unsignedTinyInteger('min_driver_age')->default(21);
            $table->unsignedTinyInteger('min_license_years')->default(1);
            $table->string('booking_mode')->default('instant');
            $table->string('cancellation_policy')->default('moderate');
            $table->boolean('with_driver_available')->default(false);
            $table->boolean('airport_delivery')->default(false);
            $table->boolean('free_cancellation')->default(false);
            $table->string('fuel_policy')->default('full_to_full');
            $table->json('features')->nullable();
            $table->json('allowed_zones')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('price_per_day')->default(0);
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('documents_vehicules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicules')->cascadeOnDelete();
            $table->string('type');
            $table->string('file_path');
            $table->date('expires_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('medias_vehicules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicules')->cascadeOnDelete();
            $table->string('url');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();
        });

        Schema::create('regles_tarification', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicules')->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('min_days')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->unsignedInteger('amount');
            $table->timestamps();
        });

        Schema::create('options_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partenaires')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('price');
            $table->string('billing_type')->default('per_day');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('blocages_vehicules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicules')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->string('guest_name')->nullable();
            $table->foreignId('vehicle_id')->constrained('vehicules')->restrictOnDelete();
            $table->foreignId('partner_id')->constrained('partenaires')->restrictOnDelete();
            $table->foreignId('pickup_location_id')->nullable()->constrained('lieux')->nullOnDelete();
            $table->foreignId('return_location_id')->nullable()->constrained('lieux')->nullOnDelete();
            $table->dateTime('pickup_at');
            $table->dateTime('return_at');
            $table->unsignedTinyInteger('driver_age')->nullable();
            $table->string('status')->default('pending_payment');
            $table->unsignedInteger('days_count')->default(1);
            $table->unsignedInteger('subtotal')->default(0);
            $table->unsignedInteger('extras_total')->default(0);
            $table->unsignedInteger('total_amount')->default(0);
            $table->unsignedInteger('deposit_amount')->default(0);
            $table->unsignedInteger('commission_amount')->default(0);
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->string('cancellation_policy')->nullable();
            $table->string('source')->default('web');
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('extras_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('extra_id')->constrained('options_extras')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('total_price');
            $table->timestamps();
        });

        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('reservations')->cascadeOnDelete();
            $table->string('gateway')->nullable();
            $table->string('method');
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('XAF');
            $table->string('status')->default('pending');
            $table->string('transaction_ref')->nullable()->unique();
            $table->json('gateway_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('remboursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('paiements')->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('reason')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('reversements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partenaires')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('gross_amount');
            $table->unsignedInteger('commission_amount');
            $table->unsignedInteger('net_amount');
            $table->string('status')->default('pending');
            $table->string('reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('avis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partenaires')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating_cleanliness')->nullable();
            $table->unsignedTinyInteger('rating_condition')->nullable();
            $table->unsignedTinyInteger('rating_welcome')->nullable();
            $table->unsignedTinyInteger('rating_value')->nullable();
            $table->unsignedTinyInteger('rating_overall');
            $table->text('comment')->nullable();
            $table->text('partner_reply')->nullable();
            $table->timestamp('partner_replied_at')->nullable();
            $table->string('moderation_status')->default('published');
            $table->timestamps();
        });

        Schema::create('journaux_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role', 40)->nullable();
            $table->string('action');
            $table->string('http_method', 10)->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('route')->nullable();
            $table->string('referer', 1000)->nullable();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('browser', 80)->nullable();
            $table->string('browser_version', 40)->nullable();
            $table->string('platform', 80)->nullable();
            $table->string('device_type', 40)->nullable();
            $table->uuid('request_id')->nullable();
            $table->string('session_id', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['entity_type', 'entity_id']);
            $table->index('actor_id');
            $table->index('action');
            $table->index('created_at');
            $table->index('ip_address');
            $table->index('request_id');
        });

        Schema::create('parametres_plateforme', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametres_plateforme');
        Schema::dropIfExists('journaux_audit');
        Schema::dropIfExists('avis');
        Schema::dropIfExists('reversements');
        Schema::dropIfExists('remboursements');
        Schema::dropIfExists('paiements');
        Schema::dropIfExists('extras_reservations');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('blocages_vehicules');
        Schema::dropIfExists('options_extras');
        Schema::dropIfExists('regles_tarification');
        Schema::dropIfExists('medias_vehicules');
        Schema::dropIfExists('documents_vehicules');
        Schema::dropIfExists('vehicules');
        Schema::dropIfExists('agences');
        Schema::dropIfExists('lieux');
        Schema::dropIfExists('membres_partenaires');
        Schema::dropIfExists('documents_partenaires');
        Schema::dropIfExists('partenaires');
        Schema::dropIfExists('profils_clients');
    }
};
