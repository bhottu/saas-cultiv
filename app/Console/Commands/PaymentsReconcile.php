<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Console\Command;

/** Safety net: re-check pending QRIS payments against the provider. */
class PaymentsReconcile extends Command
{
    protected $signature = 'payments:reconcile {--minutes=12 : Look back window in minutes}';

    protected $description = 'Re-verify pending QRIS payment statuses via provider API';

    public function handle(PaymentService $payments): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        Payment::where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('provider_transaction_id')
            ->chunkById(50, function ($batch) use ($payments) {
                foreach ($batch as $payment) {
                    try {
                        $status = $payments->reconcile($payment);
                        $this->line("{$payment->order_id}: {$status}");
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('payment.reconcile.failed', [
                            'order_id' => $payment->order_id, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return self::SUCCESS;
    }
}
