<?php

namespace App\Console\Commands;

use App\Models\ReminderHistory;
use Illuminate\Console\Command;

final class PruneReminderHistoryCommand extends Command
{
    protected $signature = 'reminders:prune-history';

    protected $description = 'Delete reminder history records older than six months';

    public function handle(): int
    {
        $deleted = ReminderHistory::query()
            ->where('created_at', '<', now()->subMonthsNoOverflow(6))
            ->toBase()
            ->delete();

        $this->info("Deleted reminder history records: {$deleted}");

        return self::SUCCESS;
    }
}
