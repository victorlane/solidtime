<?php

declare(strict_types=1);

namespace App\Console\Commands\Member;

use App\Mail\WeeklyBillableTargetReminderMail;
use App\Models\Member;
use App\Models\Project;
use App\Models\User;
use App\Service\BillableTargetService;
use App\Service\TimezoneService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class MemberSendWeeklyBillableTargetMailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'member:send-weekly-billable-target-mails '.
        ' { --dry-run : Do not actually send emails or save anything to the database, just output what would happen }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sends a weekly billable-hours check to members with a weekly billable target (on the member or on a project assignment).';

    /**
     * The command runs hourly and gates per member: each member's week is
     * defined by their own week_start and timezone, so there is no single
     * cron moment that is "end of week" for everyone. The mail goes out once
     * per week, from two days before the member's local week ends (Friday
     * 15:00 for a Monday-start week), tracked via weekly_target_email_sent_at.
     */
    public function handle(BillableTargetService $billableTargetService, TimezoneService $timezoneService): int
    {
        $this->comment('Sending weekly billable target emails...');
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->comment('Running in dry-run mode. No emails will be sent and nothing will be saved to the database.');
        }

        $sentMails = 0;
        Member::query()
            ->where(function (Builder $query): void {
                $query->whereNotNull('weekly_billable_target')
                    ->orWhereHas('projectMembers', function (Builder $builder): void {
                        $builder->whereNotNull('weekly_billable_target');
                    });
            })
            ->whereHas('user', function (Builder $query): void {
                /** @var Builder<User> $query */
                $query->where('is_placeholder', '=', false);
            })
            ->with(['user', 'organization', 'projectMembers' => function ($query): void {
                $query->whereNotNull('weekly_billable_target')->with('project');
            }])
            ->orderBy('id')
            ->chunk(100, function (Collection $members) use ($dryRun, $billableTargetService, $timezoneService, &$sentMails): void {
                /** @var Collection<int, Member> $members */
                foreach ($members as $member) {
                    $user = $member->user;
                    $timezone = $timezoneService->getTimezoneFromUser($user);
                    $now = Carbon::now($timezone);

                    $weekStart = $now->copy()->startOfDay()->startOfWeek($user->week_start->carbonWeekDay());
                    $weekEnd = $weekStart->copy()->addWeek();
                    $remindAt = $weekEnd->copy()->subDays(2)->setTime(15, 0);

                    if ($now->lt($remindAt)) {
                        continue;
                    }
                    if ($member->weekly_target_email_sent_at !== null && $member->weekly_target_email_sent_at->gte($weekStart)) {
                        continue;
                    }

                    $tracked = $billableTargetService->billableSeconds($member, null, $weekStart, $weekEnd);
                    $target = $member->weekly_billable_target;

                    $projectRows = [];
                    $anyProjectBehind = false;
                    foreach ($member->projectMembers as $projectMember) {
                        $projectTarget = $projectMember->weekly_billable_target;
                        // The relation is declared non-nullable, but a project deleted
                        // out from under the membership still has to be skipped.
                        /** @var Project|null $project */
                        $project = $projectMember->project;
                        if ($projectTarget === null || $project === null) {
                            continue;
                        }
                        $projectTracked = $billableTargetService->billableSeconds($member, $projectMember->project_id, $weekStart, $weekEnd);
                        $projectRows[] = [
                            'name' => $project->name,
                            'tracked' => $projectTracked,
                            'target' => $projectTarget,
                        ];
                        if ($projectTracked < $projectTarget) {
                            $anyProjectBehind = true;
                        }
                    }

                    $behindOverall = $target !== null && $tracked < $target;

                    // Always stamp the week as evaluated; a member on target
                    // gets no email, not a re-check every hour.
                    if (! $dryRun) {
                        $member->weekly_target_email_sent_at = Carbon::now();
                        $member->save();
                    }

                    if (! $behindOverall && ! $anyProjectBehind) {
                        continue;
                    }

                    $this->info('Start sending email to user "'.$user->email.'" ('.$user->getKey().') for member '.$member->getKey());
                    $sentMails++;
                    if (! $dryRun) {
                        Mail::to($user->email)
                            ->queue(new WeeklyBillableTargetReminderMail($user, $member->organization, $tracked, $target, $projectRows));
                    }
                }
            });

        $this->comment('Finished sending '.$sentMails.' weekly billable target emails...');

        return self::SUCCESS;
    }
}
