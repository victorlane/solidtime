@use('Brick\Math\BigDecimal')
@use('Brick\Money\Money')
@use('Carbon\CarbonInterval')
@use('Illuminate\Support\Str')
@inject('colorService', 'App\Service\ColorService')
@php
    // A deterministic accent per organization, so the same sender always produces the same looking
    // document without solidtime having to store a brand colour.
    $accent = $colorService->getRandomColor($organization->getKey());
    $monogram = Str::upper(Str::substr(trim($organization->name), 0, 1));
    $formatMoney = fn (int $cents): string => $localization->formatCurrency(Money::of(BigDecimal::ofUnscaledValue($cents, 2)->__toString(), $currency));
@endphp
    <!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <title>Hours specification</title>
    <style>
        html, body, div, span, applet, object, iframe,
        h1, h2, h3, h4, h5, h6, p, blockquote, pre,
        a, abbr, acronym, address, big, cite, code,
        del, dfn, em, img, ins, kbd, q, s, samp,
        small, strike, strong, sub, sup, tt, var,
        b, u, i, center,
        dl, dt, dd, ol, ul, li,
        fieldset, form, label, legend,
        table, caption, tbody, tfoot, thead, tr, th, td,
        article, aside, canvas, details, embed,
        figure, figcaption, footer, header, hgroup,
        menu, nav, output, ruby, section, summary,
        time, mark, audio, video {
            margin: 0;
            padding: 0;
            border: 0;
            font-size: 100%;
            vertical-align: baseline;
            box-sizing: border-box;
        }

        /* HTML5 display-role reset for older browsers */
        article, aside, details, figcaption, figure,
        footer, header, hgroup, menu, nav, section {
            display: block;
        }

        body {
            line-height: 1;
        }

        ol, ul {
            list-style: none;
        }

        @font-face {
            font-family: 'Outfit';
            src: url('outfit.ttf');
        }

        body {
            font-family: 'Outfit', 'Helvetica Neue', 'Helvetica', Helvetica, Arial, sans-serif;
            color: #18181b;
        }

        table {
            border-collapse: collapse;
            border-spacing: 0;
            text-align: left;
            font-size: 12px;
        }

        table th {
            font-weight: 500;
            padding: 7px 12px;
            color: #52525b;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            background-color: #fafafa;
        }

        table tr {
            border-bottom: 1px #e4e4e7 solid;
        }

        table tbody tr:last-of-type {
            border-bottom: none;
        }

        table tr td {
            font-weight: 400;
            color: #3f3f46;
            padding: 7px 12px;
            vertical-align: top;
        }

        table tfoot {
            border-top: 1px #d4d4d8 solid;
        }

        table tfoot td {
            font-weight: 500;
            color: #18181b;
            background-color: #fafafa;
        }

        .table-wrapper {
            border: 1px solid #d4d4d8;
            border-radius: 8px;
            overflow: hidden;
            width: calc(100% - 2px);
        }

        thead {
            border-bottom: 1px #d4d4d8 solid;
            /* A project that runs over a page break keeps its column headers */
            display: table-header-group;
        }

        .eyebrow {
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: #a1a1aa;
        }

        .meta-label {
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #a1a1aa;
            margin-bottom: 5px;
        }

        .meta-value {
            font-size: 12px;
            font-weight: 500;
            color: #27272a;
        }

        .stat-value {
            font-size: 22px;
            font-weight: 600;
            margin-top: 4px;
            letter-spacing: -0.01em;
        }

        .num {
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .section {
            break-inside: auto;
            padding-top: 26px;
        }

        .no-break {
            break-after: avoid-page;
        }
    </style>
    @if($debug)
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=outfit:200,300,400,500,600,700,800" rel="stylesheet"/>
    @endif
</head>
<body>

<div style="display: flex; align-items: flex-start; justify-content: space-between; padding-bottom: 14px; border-bottom: 2px solid {{ $accent }};">
    <div style="display: flex; align-items: center;">
        <div style="width: 40px; height: 40px; border-radius: 10px; background-color: {{ $accent }}; color: #ffffff; font-size: 20px; font-weight: 600; text-align: center; line-height: 40px; margin-right: 12px;">
            {{ $monogram }}
        </div>
        <div>
            <div class="eyebrow" style="margin-bottom: 4px;">Hours specification</div>
            <div style="font-size: 21px; font-weight: 600; letter-spacing: -0.01em;">{{ $organization->name }}</div>
        </div>
    </div>
    <div style="text-align: right;">
        <div class="meta-label">Period</div>
        <div style="font-size: 13px; font-weight: 500;">
            {{ $localization->formatDate($start) }} &ndash; {{ $localization->formatDate($end) }}
        </div>
    </div>
</div>

<div class="table-wrapper" style="margin-top: 20px;">
    <div style="background-color: #fafafa; display: flex; gap: 26px; padding: 12px 16px; border-bottom: 1px solid #e4e4e7;">
        <div>
            <div class="meta-label" style="margin-bottom: 0;">Total hours</div>
            <div class="stat-value">{{ $localization->formatIntervalForReporting(CarbonInterval::seconds($specification['seconds'])) }}</div>
        </div>
        <div>
            <div class="meta-label" style="margin-bottom: 0;">Decimal</div>
            <div class="stat-value">{{ $localization->formatNumber($specification['seconds'] / 3600) }}</div>
        </div>
        <div>
            <div class="meta-label" style="margin-bottom: 0;">Days worked</div>
            <div class="stat-value">{{ $specification['day_count'] }}</div>
        </div>
        @if($showAmounts && $specification['cost'] !== null)
            <div>
                <div class="meta-label" style="margin-bottom: 0;">Amount</div>
                <div class="stat-value">{{ $formatMoney($specification['cost']) }}</div>
            </div>
        @endif
    </div>
    <div style="display: flex; gap: 26px; padding: 12px 16px;">
        <div style="min-width: 150px;">
            <div class="meta-label">Client</div>
            <div class="meta-value">
                @if(count($specification['clients']) === 0)
                    &ndash;
                @else
                    {{ implode(', ', $specification['clients']) }}
                @endif
            </div>
        </div>
        <div style="min-width: 110px;">
            <div class="meta-label">Prepared by</div>
            <div class="meta-value">{{ $preparedBy }}</div>
        </div>
        <div style="min-width: 90px;">
            <div class="meta-label">Issued</div>
            <div class="meta-value">{{ $localization->formatDate($generatedAt) }}</div>
        </div>
        @if($reference !== null)
            <div>
                <div class="meta-label">Reference</div>
                <div class="meta-value">{{ $reference }}</div>
            </div>
        @endif
    </div>
</div>

@forelse($specification['projects'] as $project)
    <div class="section">
        <div class="no-break" style="display: flex; align-items: center; justify-content: space-between; padding: 0 2px 8px 2px;">
            <div style="display: flex; align-items: center;">
                <div style="width: 9px; height: 9px; border-radius: 50%; background-color: {{ $project['color'] ?? '#d4d4d8' }}; margin-right: 8px;"></div>
                <span style="font-size: 15px; font-weight: 600;">{{ $project['name'] ?? 'Without project' }}</span>
                @if($project['client_name'] !== null)
                    <span style="font-size: 12px; color: #a1a1aa; padding-left: 8px;">for {{ $project['client_name'] }}</span>
                @endif
            </div>
            <div style="font-size: 13px; font-weight: 600;">
                {{ $localization->formatIntervalForReporting(CarbonInterval::seconds($project['seconds'])) }}
            </div>
        </div>

        <div class="table-wrapper">
            <table style="width: 100%;">
                <thead>
                <tr>
                    <th style="width: 95px;">Date</th>
                    <th>Work</th>
                    <th class="num" style="width: 80px;">Duration</th>
                    <th class="num" style="width: 60px;">Hours</th>
                    @if($showAmounts)
                        <th class="num" style="width: 90px;">Amount</th>
                    @endif
                </tr>
                </thead>
                <tbody>
                @foreach($project['days'] as $day)
                    <tr>
                        <td style="white-space: nowrap;">
                            {{ $localization->formatDate($day['date']) }}<br>
                            <span style="color: #a1a1aa; font-size: 10px;">{{ $day['date']->format('l') }}</span>
                        </td>
                        <td style="overflow-wrap: break-word; max-width: 260px; line-height: 1.45;">
                            @forelse($day['items'] as $item)
                                @if($item['task'] !== null)
                                    <span style="font-weight: 500; color: #18181b;">{{ $item['task'] }}</span>@if($item['description'] !== null)<span style="color: #a1a1aa;"> &middot; </span>@endif
                                @endif
                                @if($item['description'] !== null){{ $item['description'] }}@endif
                                @if(! $loop->last)<br>@endif
                            @empty
                                <span style="color: #a1a1aa;">&ndash;</span>
                            @endforelse
                        </td>
                        <td class="num">{{ $localization->formatIntervalForReporting(CarbonInterval::seconds($day['seconds'])) }}</td>
                        <td class="num">{{ $localization->formatNumber($day['seconds'] / 3600) }}</td>
                        @if($showAmounts)
                            <td class="num">{{ $formatMoney($day['cost'] ?? 0) }}</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="2">Subtotal {{ $project['name'] ?? 'without project' }}</td>
                    <td class="num">{{ $localization->formatIntervalForReporting(CarbonInterval::seconds($project['seconds'])) }}</td>
                    <td class="num">{{ $localization->formatNumber($project['seconds'] / 3600) }}</td>
                    @if($showAmounts)
                        <td class="num">{{ $formatMoney($project['cost'] ?? 0) }}</td>
                    @endif
                </tr>
                </tfoot>
            </table>
        </div>
    </div>
@empty
    <div style="margin-top: 26px; padding: 28px 16px; text-align: center; border: 1px dashed #d4d4d8; border-radius: 8px; color: #a1a1aa; font-size: 13px;">
        No hours were tracked in this period.
    </div>
@endforelse

<div class="no-break" style="margin-top: 26px; border-top: 2px solid {{ $accent }}; padding-top: 12px; display: flex; align-items: flex-end; justify-content: space-between;">
    <div>
        <div class="meta-label" style="margin-bottom: 3px;">Total</div>
        <div style="font-size: 11px; color: #71717a;">
            {{ $localization->formatDate($start) }} &ndash; {{ $localization->formatDate($end) }}
            &middot; {{ count($specification['projects']) }} {{ count($specification['projects']) === 1 ? 'project' : 'projects' }}
            &middot; {{ $specification['day_count'] }} {{ $specification['day_count'] === 1 ? 'day' : 'days' }}
        </div>
    </div>
    <div style="text-align: right;">
        <div style="font-size: 24px; font-weight: 600; letter-spacing: -0.01em;">
            {{ $localization->formatIntervalForReporting(CarbonInterval::seconds($specification['seconds'])) }}
            <span style="color: #a1a1aa; font-size: 14px; font-weight: 500;">({{ $localization->formatNumber($specification['seconds'] / 3600) }} h)</span>
        </div>
        @if($showAmounts && $specification['cost'] !== null)
            <div style="font-size: 15px; font-weight: 500; color: #3f3f46; margin-top: 5px;">{{ $formatMoney($specification['cost']) }}</div>
        @endif
    </div>
</div>

<div style="margin-top: 14px; font-size: 10px; color: #a1a1aa; line-height: 1.5;">
    All times are in {{ $timezone }}. Durations are the tracked time per day, rounded as configured for this
    organization. Internal, non-client work is not part of this specification.
</div>

</body>
</html>
