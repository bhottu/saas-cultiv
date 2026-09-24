<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionActivatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Payment $payment) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Payment received — subscription activated')
            ->greeting('Payment successful!')
            ->line("Your payment of IDR {$this->payment->amount} was confirmed.")
            ->line('Your subscription is now active.')
            ->action('View billing', route('billing.index'));
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'payment_paid',
            'payment_id' => $this->payment->id,
            'amount' => $this->payment->amount,
            'order_id' => $this->payment->order_id,
            'message' => "Payment of IDR {$this->payment->amount} confirmed — subscription activated.",
        ];
    }
}
