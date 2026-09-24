<?php

namespace App\Notifications;

use App\Models\TenantUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class TeamInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly TenantUser $membership) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    /** Signed URL — only this invitee, valid for 3 days. */
    public function acceptanceUrl($notifiable): string
    {
        return URL::temporarySignedRoute('team.accept', now()->addDays(3), [
            'membership' => $this->membership->id,
        ]);
    }

    public function toMail($notifiable): MailMessage
    {
        $tenant = $this->membership->tenant;

        return (new MailMessage)
            ->subject("You've been invited to {$tenant->name}")
            ->greeting("Hi {$notifiable->name}!")
            ->line(($this->membership->invitedBy?->name ?? 'Someone')." invited you to join {$tenant->name} as {$this->membership->role}.")
            ->line('This invitation link expires in 3 days.')
            ->action('Accept invitation', $this->acceptanceUrl($notifiable));
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'team_invitation',
            'tenant_id' => $this->membership->tenant_id,
            'role' => $this->membership->role,
            'message' => "You were invited to join {$this->membership->tenant->name} as {$this->membership->role}.",
        ];
    }
}
