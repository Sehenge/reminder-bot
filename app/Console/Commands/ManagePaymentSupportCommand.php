<?php

namespace App\Console\Commands;

use App\Models\PaymentSupportRequest;
use App\Services\PaymentSupportService;
use App\Services\TelegramService;
use Illuminate\Console\Command;

final class ManagePaymentSupportCommand extends Command
{
    protected $signature = 'payments:support {--resolve= : Resolve a support request by ID} {--reply= : Reply text sent to the user when resolving} {--limit=25 : Number of open requests to display}';

    protected $description = 'List or resolve Telegram payment support requests';

    public function handle(PaymentSupportService $support, TelegramService $telegram): int
    {
        $resolveId = $this->option('resolve');
        if (is_string($resolveId) && ctype_digit($resolveId)) {
            $request = PaymentSupportRequest::query()->findOrFail((int) $resolveId);
            $reply = $this->option('reply');
            if (is_string($reply) && trim($reply) !== '' && $request->user !== null) {
                $response = $telegram->sendMessage([
                    'chat_id' => $request->user->telegram_id,
                    'text' => "Ответ по обращению #{$request->id}:\n\n".trim($reply),
                ]);
                if (($response['ok'] ?? false) !== true) {
                    $this->error('Telegram did not accept the support reply. The request remains open.');

                    return self::FAILURE;
                }
            }
            $support->resolve($request);
            $this->info("Payment support request #{$request->id} resolved.");

            return self::SUCCESS;
        }

        $limit = max(1, min(100, (int) $this->option('limit')));
        $requests = PaymentSupportRequest::query()
            ->with('user:id,telegram_id,username')
            ->where('status', 'open')
            ->oldest()
            ->limit($limit)
            ->get();

        $this->table(
            ['ID', 'Telegram ID', 'Username', 'Created', 'Message'],
            $requests->map(fn (PaymentSupportRequest $request): array => [
                $request->id,
                $request->user?->telegram_id,
                $request->user?->username,
                $request->created_at?->toDateTimeString(),
                mb_strimwidth($request->message, 0, 120, '…'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
