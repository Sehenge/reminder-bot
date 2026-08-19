<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            // Состояние доставки: pending, dispatching, sent, failed.
            // Позволяет атомарно резервировать напоминание перед постановкой в очередь
            // и исключить повторную отправку при параллельном/пересекающемся запуске.
            $table->string('status', 20)->default('pending')->after('recurrence_value');

            // Telegram message_id последнего отправленного уведомления
            $table->unsignedBigInteger('telegram_message_id')->nullable()->after('status');

            // Фактическое время отправки последнего уведомления
            $table->timestamp('sent_at')->nullable()->after('telegram_message_id');

            $table->index(['status', 'target_at'], 'reminders_status_target_at_index');
        });

        // Приводим уже существующие данные в согласованное состояние:
        // выполненные напоминания считаем отправленными.
        DB::table('reminders')->where('is_completed', true)->update(['status' => 'sent']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropIndex('reminders_status_target_at_index');
            $table->dropColumn(['status', 'telegram_message_id', 'sent_at']);
        });
    }
};
