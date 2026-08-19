<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('premium_subscriptions', function (Blueprint $table) {
            $table->string('product_id')->default('premium_30_days')->after('user_id');
            $table->string('invoice_payload')->nullable()->unique()->after('product_id');
            $table->string('currency', 3)->default('XTR')->after('stars_amount');
            $table->timestamp('starts_at')->nullable()->after('status');
            $table->timestamp('purchased_at')->nullable()->after('starts_at');
            $table->timestamp('refunded_at')->nullable()->after('purchased_at');
        });

        Schema::create('premium_payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('event_type', 40)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('telegram_payment_charge_id')->nullable()->index();
            $table->string('product_id')->nullable();
            $table->string('invoice_payload')->nullable();
            $table->string('currency', 3)->nullable();
            $table->integer('amount')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('premium_payment_events');

        Schema::table('premium_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['invoice_payload']);
        });

        Schema::table('premium_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'product_id',
                'invoice_payload',
                'currency',
                'starts_at',
                'purchased_at',
                'refunded_at',
            ]);
        });
    }
};
