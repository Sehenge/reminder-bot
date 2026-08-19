<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // ID Telegram пользователя является уникальным первичным ключом для нашего бота
            $table->bigInteger('telegram_id')->unique()->index();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('language_code', 10)->default('ru');

            // Настройки часового пояса (важно для напоминаний по местному времени)
            $table->string('timezone')->default('UTC');

            // Премиум статус и подписка
            $table->boolean('is_premium')->default(false);
            $table->timestamp('premium_expires_at')->nullable();

            // Состояние диалога (FSM) для бота
            $table->string('state')->nullable();
            $table->text('state_data')->nullable(); // JSON сопутствующих данных состояния

            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
