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
        $inviter = $this->membership->invitedBy?->name ?? 'Someone';
        $tenant = $this->membership->tenant;

        return [
            'type' => 'team_invitation',
            'tenant_id' => $this->membership->tenant_id,
            'role' => $this->membership->role,
            'invited_by' => $inviter,
            // Notification actions deep-link into the existing team surface, where
            // the pending invitation (and its Accept/Reject buttons) lives. Keep the
            // URL path-only: route() would bake the current APP_URL into stored
            // notification data and break links behind the ngrok tunnel swap.
            'url' => route('team.index', [], false),
            'message' => "{$inviter} invited you to join {$tenant->name} as {$this->membership->role}.",
        ];
    }
}
