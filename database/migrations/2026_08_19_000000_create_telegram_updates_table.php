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
        // Таблица дедупликации входящих Telegram update по update_id.
        // Telegram может повторно доставить один и тот же update (например,
        // при таймауте на нашей стороне), и без дедупликации это привело бы
        // к повторной обработке (двойное создание напоминаний, двойные
        // ответы и т.д.). Уникальный индекс на update_id гарантирует
        // атомарность проверки "уже обработан" на уровне БД.
        //
        // Таблица append-only и не имеет updated_at. Она может неограниченно
        // расти со временем; в проде стоит завести отдельную scheduled-команду
        // (например `telegram-updates:prune`), которая будет удалять записи
        // старше нескольких дней по индексу created_at. На данном этапе это
        // сознательно не реализовано — только заложен индекс под такую задачу.
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};
