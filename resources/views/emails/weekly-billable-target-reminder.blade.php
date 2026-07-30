@component('mail::message')
{{ __('Here is your billable-hours check for this week in :organization.', ['organization' => $organization->name]) }}

@if($targetSeconds !== null)
@if($trackedSeconds < $targetSeconds)
{{ __('You tracked :tracked billable of your :target weekly target — :remaining to go.', ['tracked' => \App\Mail\WeeklyBillableTargetReminderMail::formatHours($trackedSeconds), 'target' => \App\Mail\WeeklyBillableTargetReminderMail::formatHours($targetSeconds), 'remaining' => \App\Mail\WeeklyBillableTargetReminderMail::formatHours($targetSeconds - $trackedSeconds)]) }}
@else
{{ __('You tracked :tracked billable — your :target weekly target is met.', ['tracked' => \App\Mail\WeeklyBillableTargetReminderMail::formatHours($trackedSeconds), 'target' => \App\Mail\WeeklyBillableTargetReminderMail::formatHours($targetSeconds)]) }}
@endif
@endif

@if(count($projectRows) > 0)
@component('mail::table')
| {{ __('Project') }} | {{ __('Tracked') }} | {{ __('Target') }} |
|:--------------------|:--------------------|:-------------------|
@foreach($projectRows as $row)
| {{ $row['name'] }} | {{ \App\Mail\WeeklyBillableTargetReminderMail::formatHours($row['tracked']) }} | {{ \App\Mail\WeeklyBillableTargetReminderMail::formatHours($row['target']) }} |
@endforeach
@endcomponent
@endif

{{ __('There is still time to log missing entries before the week closes:') }}

@component('mail::button', ['url' => $dashboardUrl])
{{ __('Go to solidtime') }}
@endcomponent

@endcomponent
