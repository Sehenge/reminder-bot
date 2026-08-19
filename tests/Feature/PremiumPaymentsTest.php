<?php

namespace Tests\Feature;

use App\Enums\PremiumStatus;
use App\Models\PremiumPaymentEvent;
use App\Models\PremiumSubscription;
use App\Models\User;
use App\Services\PremiumPaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class PremiumPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    public function test_valid_pre_checkout_query_is_accepted_and_audited(): void
    {
        $user = $this->user();
        $order = app(PremiumPaymentService::class)->createPendingOrder($user);

        $this->postJson(route('telegram.webhook'), [
            'update_id' => 1001,
            'pre_checkout_query' => [
                'id' => 'checkout-1',
                'from' => ['id' => $user->telegram_id],
                'currency' => 'XTR',
                'total_amount' => 50,
                'invoice_payload' => $order->invoice_payload,
            ],
        ], $this->headers())->assertOk();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'answerPreCheckoutQuery')
            && $request['pre_checkout_query_id'] === 'checkout-1'
            && $request['ok'] === true);
        $this->assertDatabaseHas('premium_payment_events', [
            'event_key' => 'pre_checkout:checkout-1',
            'event_type' => 'pre_checkout_accepted',
            'user_id' => $user->id,
        ]);
    }

    public function test_mismatched_checkout_is_rejected(): void
    {
        $user = $this->user();
        $order = app(PremiumPaymentService::class)->createPendingOrder($user);

        $this->postJson(route('telegram.webhook'), [
            'update_id' => 1002,
            'pre_checkout_query' => [
                'id' => 'checkout-invalid',
                'from' => ['id' => $user->telegram_id],
                'currency' => 'USD',
                'total_amount' => 1,
                'invoice_payload' => $order->invoice_payload,
            ],
        ], $this->headers())->assertOk();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'answerPreCheckoutQuery')
            && $request['pre_checkout_query_id'] === 'checkout-invalid'
            && $request['ok'] === false
            && is_string($request['error_message']));
        $this->assertDatabaseHas('premium_payment_events', [
            'event_key' => 'pre_checkout:checkout-invalid',
            'event_type' => 'pre_checkout_rejected',
        ]);
    }

    public function test_successful_payment_grants_premium_and_is_idempotent(): void
    {
        Event::fake();
        CarbonImmutable::setTestNow('2026-08-19 12:00:00');
        $user = $this->user();
        $payment = $this->successfulPayment($user, 'charge-1');

        $this->postPaymentUpdate(1003, $user, 'successful_payment', $payment);
        $this->postPaymentUpdate(1004, $user, 'successful_payment', $payment);

        $user->refresh();
        $this->assertTrue($user->is_premium);
        $this->assertSame('2026-09-18 12:00:00', $user->premium_expires_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, PremiumSubscription::query()->count());
        $this->assertSame(1, PremiumPaymentEvent::query()->where('event_type', 'payment_completed')->count());
        Http::assertSentCount(1);
    }

    public function test_payment_extends_an_existing_subscription(): void
    {
        CarbonImmutable::setTestNow('2026-08-19 12:00:00');
        $user = $this->user([
            'is_premium' => true,
            'premium_expires_at' => CarbonImmutable::now()->addDays(10),
        ]);

        $this->postPaymentUpdate(1005, $user, 'successful_payment', $this->successfulPayment($user, 'charge-2'));

        $this->assertSame(
            '2026-09-28 12:00:00',
            $user->refresh()->premium_expires_at?->format('Y-m-d H:i:s'),
        );
    }

    public function test_forged_successful_payment_does_not_grant_premium(): void
    {
        $user = $this->user();
        $payment = $this->successfulPayment($user, 'charge-forged');
        $payment['total_amount'] = 1;

        $this->postPaymentUpdate(1006, $user, 'successful_payment', $payment);

        $this->assertFalse($user->refresh()->is_premium);
        $this->assertDatabaseHas('premium_subscriptions', [
            'status' => PremiumStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('premium_payment_events', [
            'event_key' => 'payment_rejected:charge-forged',
            'event_type' => 'payment_rejected',
        ]);
    }

    public function test_refund_revokes_access_and_is_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-08-19 12:00:00');
        $user = $this->user();
        $payment = $this->successfulPayment($user, 'charge-refund');
        $this->postPaymentUpdate(1007, $user, 'successful_payment', $payment);

        $refund = $payment;
        unset($refund['provider_payment_charge_id']);
        $this->postPaymentUpdate(1008, $user, 'refunded_payment', $refund);
        $this->postPaymentUpdate(1009, $user, 'refunded_payment', $refund);

        $user->refresh();
        $subscription = PremiumSubscription::query()->sole();
        $this->assertFalse($user->is_premium);
        $this->assertNull($user->premium_expires_at);
        $this->assertSame(PremiumStatus::Refunded, $subscription->status);
        $this->assertNotNull($subscription->refunded_at);
        $this->assertSame(1, PremiumPaymentEvent::query()->where('event_type', 'payment_refunded')->count());
    }

    public function test_payment_audit_events_cannot_be_changed_or_deleted(): void
    {
        $event = PremiumPaymentEvent::query()->create([
            'event_key' => 'audit-test',
            'event_type' => 'pre_checkout_rejected',
        ]);

        try {
            $event->update(['event_type' => 'payment_completed']);
            $this->fail('An audit event was updated.');
        } catch (LogicException) {
            $this->assertSame('pre_checkout_rejected', $event->fresh()?->event_type);
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_lifetime_premium_status_does_not_dereference_null_expiry(): void
    {
        $user = $this->user(['is_premium' => true, 'premium_expires_at' => null]);

        $this->postPaymentUpdate(1010, $user, 'message_text', '/premium');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'Активен бессрочно'));
    }

    /** @param array<string, mixed> $overrides */
    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'telegram_id' => 700001,
            'first_name' => 'Premium Test',
            'language_code' => 'ru',
            'timezone' => 'Europe/Moscow',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function successfulPayment(User $user, string $chargeId): array
    {
        $order = app(PremiumPaymentService::class)->createPendingOrder($user);

        return [
            'currency' => 'XTR',
            'total_amount' => 50,
            'invoice_payload' => $order->invoice_payload,
            'telegram_payment_charge_id' => $chargeId,
            'provider_payment_charge_id' => '',
        ];
    }

    /** @param array<string, mixed>|string $payment */
    private function postPaymentUpdate(int $updateId, User $user, string $field, array|string $payment): void
    {
        $message = [
            'message_id' => $updateId,
            'from' => [
                'id' => $user->telegram_id,
                'is_bot' => false,
                'first_name' => $user->first_name,
                'language_code' => 'ru',
            ],
            'chat' => ['id' => $user->telegram_id, 'type' => 'private'],
            'date' => 1_755_600_000,
        ];

        if ($field === 'message_text') {
            $message['text'] = $payment;
        } else {
            $message[$field] = $payment;
        }

        $this->postJson(route('telegram.webhook'), [
            'update_id' => $updateId,
            'message' => $message,
        ], $this->headers())->assertOk();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret-token'];
    }
}
