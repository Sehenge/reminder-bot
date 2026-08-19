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
        // Категории/теги для премиум-пользователей
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->string('color', 7)->default('#3b82f6'); // Цветовая маркировка (HEX)
            $table->timestamps();
        });

        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('set null');

            $table->text('text'); // Описание напоминания / задачи

            // Время напоминания
            $table->timestamp('target_at')->index(); // Дата и время следующей отправки (UTC)

            // Повторение напоминания
            $table->string('recurrence_type')->default('once'); // once, daily, weekly, monthly, workdays, custom
            $table->string('recurrence_value')->nullable(); // например "1" (каждый ПН), "15:00" или дни недели

            // Статус
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();

            // Приоритет (низкий, средний, высокий)
            $table->string('priority', 10)->default('medium'); // low, medium, high

            // Совместные напоминания (group chat ID или ID пользователей через запятую / JSON)
            $table->string('shared_with_chat_id')->nullable(); // Для совместных напоминаний

            $table->timestamps();
        });

        // Транзакции и подписки по Telegram Stars
        Schema::create('premium_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('telegram_payment_charge_id')->unique(); // ID транзакции Telegram
            $table->integer('stars_amount'); // Количество Telegram Stars
            $table->string('status')->default('completed'); // pending, completed, refunded
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('premium_subscriptions');
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('categories');
    }
};
