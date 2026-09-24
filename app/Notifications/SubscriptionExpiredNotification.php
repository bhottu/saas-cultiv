<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Tenant $tenant) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Subscription expired')
            ->greeting('Subscription expired')
            ->line("The subscription for {$this->tenant->name} has expired.")
            ->line('Your data is safe on the free plan; paid features are paused.')
            ->action('Upgrade now', route('billing.index'));
    }

    public function toArray($notifiable): array
    {
        return ['type' => 'subscription_expired', 'tenant_id' => $this->tenant->id,
            'message' => "Subscription for {$this->tenant->name} expired — moved to free plan."];
    }
}
