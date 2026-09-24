<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Tenant $tenant) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $sub = $this->tenant->activeSubscription()->first();
        $ends = $sub?->current_period_end?->format('d M Y');

        return (new MailMessage)
            ->subject('Your subscription expires in 7 days')
            ->greeting("Hi {$notifiable->name}!")
            ->line("The subscription for {$this->tenant->name} expires on {$ends}.")
            ->line('Renew now to avoid losing access to paid features.')
            ->action('Renew subscription', route('billing.index'));
    }

    public function toArray($notifiable): array
    {
        return ['type' => 'subscription_expiring', 'tenant_id' => $this->tenant->id,
            'message' => "Subscription for {$this->tenant->name} expires within 7 days."];
    }
}
