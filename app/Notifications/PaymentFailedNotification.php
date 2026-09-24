<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Payment $payment, public readonly string $status) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Payment {$this->status} — action required")
            ->greeting('Heads up!')
            ->line("Your payment for order {$this->payment->order_id} was marked as {$this->status}.")
            ->line($this->status === 'expired'
                ? 'The QR code expired before payment. You can create a new payment at any time.'
                : 'Please review your billing page and try again.')
            ->action('Go to billing', route('billing.index'));
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => "payment_{$this->status}",
            'payment_id' => $this->payment->id,
            'order_id' => $this->payment->order_id,
            'message' => "Payment for order {$this->payment->order_id} was {$this->status}.",
        ];
    }
}
