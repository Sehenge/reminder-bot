<?php

namespace App\Services;

use App\DTO\PreCheckoutQueryDTO;
use App\DTO\PremiumPurchaseResult;
use App\DTO\RefundedPaymentDTO;
use App\DTO\SuccessfulPaymentDTO;
use App\Enums\PremiumStatus;
use App\Events\PremiumPurchased;
use App\Events\PremiumRefunded;
use App\Models\PremiumPaymentEvent;
use App\Models\PremiumSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PremiumPaymentService
{
    public function createPendingOrder(User $user): PremiumSubscription
    {
        $nonce = (string) Str::uuid();
        $payload = $this->productId().':'.$user->id.':'.$nonce;

        $subscription = PremiumSubscription::query()->create([
            'user_id' => $user->id,
            'product_id' => $this->productId(),
            'invoice_payload' => $payload,
            'telegram_payment_charge_id' => 'pending:'.$nonce,
            'stars_amount' => $this->amount(),
            'currency' => $this->currency(),
            'status' => PremiumStatus::Pending,
        ]);

        PremiumPaymentEvent::query()->create([
            'event_key' => 'invoice_created:'.$nonce,
            'event_type' => 'invoice_created',
            'user_id' => $user->id,
            'product_id' => $this->productId(),
            'invoice_payload' => $payload,
            'currency' => $this->currency(),
            'amount' => $this->amount(),
        ]);

        return $subscription;
    }

    public function userForCheckout(PreCheckoutQueryDTO $query): ?User
    {
        $user = User::query()->where('telegram_id', $query->userTelegramId)->first();

        return $user !== null && $this->isValidOrder($user, $query->amount, $query->currency, $query->payload)
            ? $user
            : null;
    }

    public function isValidSuccessfulPayment(User $user, SuccessfulPaymentDTO $payment): bool
    {
        if (! $this->matchesProduct($payment->amount, $payment->currency)) {
            return false;
        }

        return PremiumSubscription::query()
            ->where('user_id', $user->id)
            ->where('product_id', $this->productId())
            ->where('invoice_payload', $payment->payload)
            ->where(function ($query) use ($payment): void {
                $query->where('status', PremiumStatus::Pending)
                    ->orWhere('telegram_payment_charge_id', $payment->chargeId);
            })
            ->exists();
    }

    public function recordCheckout(PreCheckoutQueryDTO $query, ?User $user, bool $accepted, ?string $reason = null): void
    {
        PremiumPaymentEvent::query()->firstOrCreate(
            ['event_key' => 'pre_checkout:'.$query->id],
            [
                'event_type' => $accepted ? 'pre_checkout_accepted' : 'pre_checkout_rejected',
                'user_id' => $user?->id,
                'product_id' => $accepted ? $this->productId() : null,
                'invoice_payload' => $query->payload,
                'currency' => $query->currency,
                'amount' => $query->amount,
                'details' => $reason === null ? null : ['reason' => $reason],
            ],
        );
    }

    public function recordRejectedPayment(User $user, SuccessfulPaymentDTO $payment): void
    {
        PremiumPaymentEvent::query()->firstOrCreate(
            ['event_key' => 'payment_rejected:'.$payment->chargeId],
            [
                'event_type' => 'payment_rejected',
                'user_id' => $user->id,
                'telegram_payment_charge_id' => $payment->chargeId,
                'invoice_payload' => $payment->payload,
                'currency' => $payment->currency,
                'amount' => $payment->amount,
                'details' => ['reason' => 'order_mismatch'],
            ],
        );
    }

    public function purchase(User $user, SuccessfulPaymentDTO $payment): PremiumPurchaseResult
    {
        $result = DB::transaction(function () use ($user, $payment): PremiumPurchaseResult {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = PremiumSubscription::query()
                ->where('telegram_payment_charge_id', $payment->chargeId)
                ->first();

            if ($existing !== null) {
                return new PremiumPurchaseResult($existing, false);
            }

            $subscription = PremiumSubscription::query()
                ->where('user_id', $lockedUser->id)
                ->where('product_id', $this->productId())
                ->where('invoice_payload', $payment->payload)
                ->where('status', PremiumStatus::Pending)
                ->lockForUpdate()
                ->firstOrFail();

            $now = CarbonImmutable::now();
            $currentExpiry = $lockedUser->premium_expires_at?->toImmutable();
            $startsAt = $currentExpiry !== null && $currentExpiry->isFuture() ? $currentExpiry : $now;
            $expiresAt = $startsAt->addDays($this->durationDays());

            $subscription->update([
                'telegram_payment_charge_id' => $payment->chargeId,
                'status' => PremiumStatus::Completed,
                'starts_at' => $startsAt,
                'purchased_at' => $now,
                'expires_at' => $expiresAt,
            ]);

            PremiumPaymentEvent::query()->create([
                'event_key' => 'payment_completed:'.$payment->chargeId,
                'event_type' => 'payment_completed',
                'user_id' => $lockedUser->id,
                'telegram_payment_charge_id' => $payment->chargeId,
                'product_id' => $this->productId(),
                'invoice_payload' => $payment->payload,
                'currency' => $payment->currency,
                'amount' => $payment->amount,
            ]);

            $lockedUser->update([
                'is_premium' => true,
                'premium_expires_at' => $expiresAt,
            ]);

            return new PremiumPurchaseResult($subscription, true);
        });

        if ($result->wasCreated) {
            PremiumPurchased::dispatch($result->subscription);
        }

        return $result;
    }

    public function refund(User $user, RefundedPaymentDTO $payment): bool
    {
        $subscription = DB::transaction(function () use ($user, $payment): ?PremiumSubscription {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $subscription = PremiumSubscription::query()
                ->where('telegram_payment_charge_id', $payment->chargeId)
                ->where('user_id', $lockedUser->id)
                ->lockForUpdate()
                ->first();

            $paymentMatches = $subscription !== null
                && $subscription->invoice_payload === $payment->payload
                && $subscription->currency === $payment->currency
                && $subscription->stars_amount === $payment->amount;

            if ($subscription !== null && $subscription->status === PremiumStatus::Refunded && $paymentMatches) {
                return null;
            }

            if ($subscription === null || $subscription->status !== PremiumStatus::Completed || ! $paymentMatches) {
                PremiumPaymentEvent::query()->firstOrCreate(
                    ['event_key' => 'refund_rejected:'.$payment->chargeId],
                    [
                        'event_type' => 'refund_rejected',
                        'user_id' => $lockedUser->id,
                        'telegram_payment_charge_id' => $payment->chargeId,
                        'invoice_payload' => $payment->payload,
                        'currency' => $payment->currency,
                        'amount' => $payment->amount,
                        'details' => ['reason' => 'payment_not_found_or_mismatch'],
                    ],
                );

                return null;
            }

            $subscription->update([
                'status' => PremiumStatus::Refunded,
                'refunded_at' => CarbonImmutable::now(),
            ]);

            PremiumPaymentEvent::query()->create([
                'event_key' => 'payment_refunded:'.$payment->chargeId,
                'event_type' => 'payment_refunded',
                'user_id' => $lockedUser->id,
                'telegram_payment_charge_id' => $payment->chargeId,
                'product_id' => $subscription->product_id,
                'invoice_payload' => $payment->payload,
                'currency' => $payment->currency,
                'amount' => $payment->amount,
            ]);

            $currentExpiry = $lockedUser->premium_expires_at?->toImmutable();
            $newExpiry = $currentExpiry?->subDays($this->durationDays());
            $hasPremium = $newExpiry !== null && $newExpiry->isFuture();

            $lockedUser->update([
                'is_premium' => $hasPremium,
                'premium_expires_at' => $hasPremium ? $newExpiry : null,
            ]);

            return $subscription;
        });

        if ($subscription !== null) {
            PremiumRefunded::dispatch($subscription);
        }

        return $subscription !== null;
    }

    public function productId(): string
    {
        return (string) config('premium.product.id');
    }

    public function amount(): int
    {
        return (int) config('premium.product.amount');
    }

    public function currency(): string
    {
        return (string) config('premium.product.currency');
    }

    private function durationDays(): int
    {
        return (int) config('premium.product.duration_days');
    }

    private function isValidOrder(User $user, int $amount, string $currency, string $payload): bool
    {
        return $this->matchesProduct($amount, $currency)
            && PremiumSubscription::query()
                ->where('user_id', $user->id)
                ->where('product_id', $this->productId())
                ->where('invoice_payload', $payload)
                ->where('status', PremiumStatus::Pending)
                ->exists();
    }

    private function matchesProduct(int $amount, string $currency): bool
    {
        return $amount === $this->amount() && hash_equals($this->currency(), $currency);
    }
}
