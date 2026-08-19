<?php

namespace App\Services;

use App\Models\PaymentSupportRequest;
use App\Models\User;
use InvalidArgumentException;

final class PaymentSupportService
{
    public function open(User $user, string $message): PaymentSupportRequest
    {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 2000) {
            throw new InvalidArgumentException('Payment support message must contain between 1 and 2000 characters.');
        }

        return PaymentSupportRequest::query()->create([
            'user_id' => $user->id,
            'message' => $message,
            'status' => 'open',
        ]);
    }

    public function resolve(PaymentSupportRequest $request): void
    {
        if ($request->status === 'resolved') {
            return;
        }

        $request->update(['status' => 'resolved', 'resolved_at' => now()]);
    }
}
