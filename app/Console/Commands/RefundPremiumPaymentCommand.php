<?php

namespace App\Console\Commands;

use App\DTO\RefundedPaymentDTO;
use App\Enums\PremiumStatus;
use App\Models\PremiumSubscription;
use App\Services\PremiumPaymentService;
use App\Services\TelegramService;
use Illuminate\Console\Command;

final class RefundPremiumPaymentCommand extends Command
{
    protected $signature = 'premium:refund {charge_id : Telegram payment charge ID} {--force : Skip interactive confirmation}';

    protected $description = 'Refund a completed Telegram Stars Premium payment';

    public function handle(TelegramService $telegram, PremiumPaymentService $payments): int
    {
        $chargeId = (string) $this->argument('charge_id');
        $subscription = PremiumSubscription::query()
            ->with('user')
            ->where('telegram_payment_charge_id', $chargeId)
            ->first();

        if ($subscription === null || $subscription->status !== PremiumStatus::Completed || $subscription->user === null) {
            $this->error('A completed payment with this charge ID was not found.');

            return self::FAILURE;
        }

        $this->table(['User', 'Charge ID', 'Amount', 'Currency'], [[
            $subscription->user->telegram_id,
            $subscription->telegram_payment_charge_id,
            $subscription->stars_amount,
            $subscription->currency,
        ]]);

        if (! $this->option('force') && ! $this->confirm('Issue this irreversible Telegram Stars refund?')) {
            $this->warn('Refund cancelled.');

            return self::SUCCESS;
        }

        $response = $telegram->refundStarPayment(
            $subscription->user->telegram_id,
            $subscription->telegram_payment_charge_id,
        );
        if (($response['ok'] ?? false) !== true) {
            $this->error('Telegram rejected the refund: '.(string) ($response['description'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $refund = RefundedPaymentDTO::fromArray([
            'telegram_payment_charge_id' => $subscription->telegram_payment_charge_id,
            'total_amount' => $subscription->stars_amount,
            'currency' => $subscription->currency,
            'invoice_payload' => $subscription->invoice_payload,
        ]);
        $payments->refund($subscription->user, $refund);
        $this->info('Telegram Stars payment refunded and local Premium entitlement recalculated.');

        return self::SUCCESS;
    }
}
