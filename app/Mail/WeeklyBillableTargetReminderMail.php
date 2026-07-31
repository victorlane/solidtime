<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class WeeklyBillableTargetReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;

    public Organization $organization;

    /** Billable seconds tracked this week across the organization. */
    public int $trackedSeconds;

    /** Weekly billable target in seconds; null when only project targets exist. */
    public ?int $targetSeconds;

    /**
     * Per-project targets: list of arrays with keys
     * name (string), tracked (int seconds), target (int seconds).
     *
     * @var array<int, array{name: string, tracked: int, target: int}>
     */
    public array $projectRows;

    /**
     * @param  array<int, array{name: string, tracked: int, target: int}>  $projectRows
     */
    public function __construct(User $user, Organization $organization, int $trackedSeconds, ?int $targetSeconds, array $projectRows)
    {
        $this->user = $user;
        $this->organization = $organization;
        $this->trackedSeconds = $trackedSeconds;
        $this->targetSeconds = $targetSeconds;
        $this->projectRows = $projectRows;
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        return $this->markdown('emails.weekly-billable-target-reminder', [
            'dashboardUrl' => URL::route('dashboard'),
        ])
            ->subject(__('Billable hours check for :organization', ['organization' => $this->organization->name]));
    }

    /**
     * Render seconds as a compact hours label ("12h 30m").
     */
    public static function formatHours(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($minutes === 0) {
            return $hours.'h';
        }

        return $hours.'h '.$minutes.'m';
    }
}
