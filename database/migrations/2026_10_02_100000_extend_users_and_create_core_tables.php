<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->unique()->after('email');
            $table->string('role')->default('customer')->after('password'); // customer, partner, partner_agent, admin, super_admin
            $table->string('status')->default('active')->after('role'); // active, suspended, banned
            $table->string('locale', 5)->default('fr')->after('status');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->boolean('two_factor_enabled')->default(false)->after('locale');
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
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

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // individual, company, agency
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
            $table->string('status')->default('pending'); // pending, info_requested, approved, rejected, suspended
            $table->string('trust_level')->default('new'); // new, verified, trusted
            $table->decimal('commission_rate', 5, 2)->default(15.00);
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // id_card, rccm, nif, proof_of_address, rib, other
            $table->string('file_path');
            $table->string('status')->default('pending'); // pending, accepted, rejected
            $table->text('rejection_reason')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('agent'); // fleet_manager, booking_agent, accountant, agent
            $table->timestamps();
            $table->unique(['partner_id', 'user_id']);
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type'); // city, airport, district, agency_point
            $table->string('city')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
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

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('brand');
            $table->string('model');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('category'); // city_car, sedan, suv, 4x4, pickup, minibus, utility, luxury
            $table->string('plate_number')->unique();
            $table->string('color')->nullable();
            $table->unsignedTinyInteger('seats')->default(5);
            $table->unsignedTinyInteger('doors')->default(4);
            $table->unsignedTinyInteger('luggage')->default(2);
            $table->string('transmission')->default('manual'); // manual, automatic
            $table->string('fuel')->default('petrol'); // petrol, diesel, hybrid, electric
            $table->boolean('air_conditioning')->default(true);
            $table->unsignedInteger('included_km')->nullable(); // null = unlimited
            $table->unsignedInteger('deposit_amount')->default(0); // XAF
            $table->unsignedTinyInteger('min_driver_age')->default(21);
            $table->unsignedTinyInteger('min_license_years')->default(1);
            $table->string('booking_mode')->default('instant'); // instant, on_request
            $table->string('cancellation_policy')->default('moderate'); // flexible, moderate, strict
            $table->boolean('with_driver_available')->default(false);
            $table->boolean('airport_delivery')->default(false);
            $table->boolean('free_cancellation')->default(false);
            $table->string('fuel_policy')->default('full_to_full');
            $table->json('features')->nullable();
            $table->json('allowed_zones')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('price_per_day')->default(0); // XAF
            $table->string('status')->default('draft'); // draft, pending_validation, published, suspended, archived
            $table->timestamps();
        });

        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // registration, insurance, technical_inspection
            $table->string('file_path');
            $table->date('expires_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('vehicle_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();
        });

        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // base, tiered, seasonal, weekend
            $table->unsignedInteger('min_days')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->unsignedInteger('amount'); // XAF per day
            $table->timestamps();
        });

        Schema::create('extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('price');
            $table->string('billing_type')->default('per_day'); // per_day, flat
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vehicle_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->string('guest_name')->nullable();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->foreignId('pickup_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('return_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->dateTime('pickup_at');
            $table->dateTime('return_at');
            $table->unsignedTinyInteger('driver_age')->nullable();
            $table->string('status')->default('pending_payment');
            // pending_payment, locked, pending_partner, confirmed, ongoing, completed, cancelled, expired, refunded
            $table->unsignedInteger('days_count')->default(1);
            $table->unsignedInteger('subtotal')->default(0);
            $table->unsignedInteger('extras_total')->default(0);
            $table->unsignedInteger('total_amount')->default(0);
            $table->unsignedInteger('deposit_amount')->default(0);
            $table->unsignedInteger('commission_amount')->default(0);
            $table->decimal('commission_rate', 5, 2)->default(15);
            $table->string('cancellation_policy')->nullable();
            $table->string('source')->default('web');
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extra_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('total_price');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('gateway')->nullable(); // cinetpay, flutterwave, mock
            $table->string('method'); // airtel_money, moov_money, card, agency_cash
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('XAF');
            $table->string('status')->default('pending'); // pending, paid, failed, refunded
            $table->string('transaction_ref')->nullable()->unique();
            $table->json('gateway_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('reason')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('gross_amount');
            $table->unsignedInteger('commission_amount');
            $table->unsignedInteger('net_amount');
            $table->string('status')->default('pending'); // pending, processing, paid
            $table->string('reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating_cleanliness')->nullable();
            $table->unsignedTinyInteger('rating_condition')->nullable();
            $table->unsignedTinyInteger('rating_welcome')->nullable();
            $table->unsignedTinyInteger('rating_value')->nullable();
            $table->unsignedTinyInteger('rating_overall');
            $table->text('comment')->nullable();
            $table->text('partner_reply')->nullable();
            $table->timestamp('partner_replied_at')->nullable();
            $table->string('moderation_status')->default('published'); // published, hidden, flagged
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('booking_extras');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('vehicle_blocks');
        Schema::dropIfExists('extras');
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('vehicle_media');
        Schema::dropIfExists('vehicle_documents');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('agencies');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('partner_members');
        Schema::dropIfExists('partner_documents');
        Schema::dropIfExists('partners');
        Schema::dropIfExists('customer_profiles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'role', 'status', 'locale', 'phone_verified_at',
                'two_factor_enabled', 'last_login_at',
            ]);
        });
    }
};
