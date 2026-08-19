<?php

namespace Tests\Feature;

use App\DTO\SuccessfulPaymentDTO;
use App\Enums\PremiumStatus;
use App\Models\PremiumSubscription;
use App\Models\User;
use App\Services\PremiumPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_paysupport_command_registers_a_request(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $user = $this->user();

        $this->telegramCommand($user, '/paysupport Оплата прошла, но Premium не появился', 3001);

        $this->assertDatabaseHas('payment_support_requests', [
            'user_id' => $user->id,
            'status' => 'open',
            'message' => 'Оплата прошла, но Premium не появился',
        ]);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'зарегистрировано'));
    }

    public function test_operator_can_reply_to_and_resolve_payment_support_request(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $user = $this->user();
        $request = $user->paymentSupportRequests()->create(['message' => 'Нужна помощь', 'status' => 'open']);

        $this->artisan('payments:support', [
            '--resolve' => (string) $request->id,
            '--reply' => 'Платёж проверен, Premium активирован.',
        ])->assertSuccessful();

        $this->assertSame('resolved', $request->refresh()->status);
        $this->assertNotNull($request->resolved_at);
        Http::assertSent(fn ($httpRequest): bool => str_contains($httpRequest->url(), 'sendMessage')
            && $httpRequest['chat_id'] === $user->telegram_id
            && str_contains($httpRequest['text'], 'Платёж проверен'));
    }

    public function test_refund_command_calls_telegram_and_revokes_premium(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        [$user, $subscription] = $this->completedSubscription('refund-command-ok');

        $this->artisan('premium:refund', [
            'charge_id' => $subscription->telegram_payment_charge_id,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(PremiumStatus::Refunded, $subscription->refresh()->status);
        $this->assertFalse($user->refresh()->is_premium);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'refundStarPayment')
            && $request['user_id'] === $user->telegram_id
            && $request['telegram_payment_charge_id'] === 'refund-command-ok');
    }

    public function test_failed_telegram_refund_keeps_local_entitlement_unchanged(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Refund failed'], 400)]);
        [$user, $subscription] = $this->completedSubscription('refund-command-fail');

        $this->artisan('premium:refund', [
            'charge_id' => $subscription->telegram_payment_charge_id,
            '--force' => true,
        ])->assertFailed();

        $this->assertSame(PremiumStatus::Completed, $subscription->refresh()->status);
        $this->assertTrue($user->refresh()->is_premium);
    }

    /** @return array{User, PremiumSubscription} */
    private function completedSubscription(string $chargeId): array
    {
        $user = $this->user();
        $payments = app(PremiumPaymentService::class);
        $order = $payments->createPendingOrder($user);
        $result = $payments->purchase($user, SuccessfulPaymentDTO::fromArray([
            'telegram_payment_charge_id' => $chargeId,
            'total_amount' => 50,
            'currency' => 'XTR',
            'invoice_payload' => $order->invoice_payload,
        ]));

        return [$user->refresh(), $result->subscription];
    }

    private function telegramCommand(User $user, string $text, int $updateId): void
    {
        $this->postJson(route('telegram.webhook'), [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => $user->telegram_id, 'first_name' => 'Test', 'is_bot' => false],
                'chat' => ['id' => $user->telegram_id, 'type' => 'private'],
                'date' => 1_755_600_000,
                'text' => $text,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret-token'])->assertOk();
    }

    private function user(): User
    {
        static $telegramId = 950000;

        return User::query()->create([
            'telegram_id' => ++$telegramId,
            'first_name' => 'Payment Test',
            'timezone' => 'Europe/Moscow',
        ]);
    }
}
