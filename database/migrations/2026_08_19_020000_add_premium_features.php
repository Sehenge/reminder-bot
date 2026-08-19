<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_token', 64)->nullable()->unique()->after('premium_expires_at');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('color', 7)->default('#64748b');
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('shared_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('telegram_chat_id')->nullable();
            $table->timestamps();
        });

        Schema::create('shared_list_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['shared_list_id', 'user_id']);
        });

        Schema::table('reminders', function (Blueprint $table) {
            $table->foreignId('shared_list_id')->nullable()->after('category_id')->constrained()->nullOnDelete();
        });

        Schema::create('reminder_tag', function (Blueprint $table) {
            $table->foreignId('reminder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['reminder_id', 'tag_id']);
        });

        Schema::create('reminder_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 30)->index();
            $table->text('text');
            $table->timestamp('target_at')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_history');
        Schema::dropIfExists('reminder_tag');

        Schema::table('reminders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shared_list_id');
        });

        Schema::dropIfExists('shared_list_members');
        Schema::dropIfExists('shared_lists');
        Schema::dropIfExists('tags');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['calendar_token']);
            $table->dropColumn('calendar_token');
        });
    }
};
