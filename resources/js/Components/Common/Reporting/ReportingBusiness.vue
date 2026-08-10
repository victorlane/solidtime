<script setup lang="ts">
import { BriefcaseIcon } from '@heroicons/vue/20/solid';
import { computed, inject, type ComputedRef } from 'vue';
import { useStorage } from '@vueuse/core';
import PageTitle from '@/Components/Common/PageTitle.vue';
import ReportingTabNavbar from '@/Components/Common/Reporting/ReportingTabNavbar.vue';
import ReportingChart from '@/Components/Common/Reporting/ReportingChart.vue';
import StatCard from '@/Components/Common/StatCard.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import { formatHumanReadableDuration, getDayJsInstance } from '@/packages/ui/src/utils/time';
import { formatCents } from '@/packages/ui/src/utils/money';
import { getOrganizationCurrencyString } from '@/utils/money';
import { useAggregatedTimeEntriesQuery } from '@/utils/useAggregatedTimeEntriesQuery';
import { useClientsQuery } from '@/utils/useClientsQuery';
import type { Organization } from '@/packages/api/src';

const organization = inject<ComputedRef<Organization>>('organization');

// ─── Period preset ──────────────────────────────────────────────────────────
type PeriodPreset = 'this_month' | 'last_3_months' | 'this_year';

const selectedPeriod = useStorage<PeriodPreset>('business-overview-period', 'this_month');

const periodOptions: { key: PeriodPreset; label: string }[] = [
    { key: 'this_month', label: 'This Month' },
    { key: 'last_3_months', label: 'Last 3 Months' },
    { key: 'this_year', label: 'This Year' },
];

const dayjs = getDayJsInstance();

const currentRange = computed(() => {
    const now = dayjs();
    switch (selectedPeriod.value) {
        case 'last_3_months':
            return { start: now.subtract(2, 'month').startOf('month'), end: now.endOf('month') };
        case 'this_year':
            return { start: now.startOf('year'), end: now.endOf('year') };
        case 'this_month':
        default:
            return { start: now.startOf('month'), end: now.endOf('month') };
    }
});

const startIso = computed(() => currentRange.value.start.utc().format());
const endIso = computed(() => currentRange.value.end.utc().format());

// ─── Data ───────────────────────────────────────────────────────────────────
const billableParams = computed(() => ({
    start: startIso.value,
    end: endIso.value,
    billable: 'true' as const,
}));

const totalParams = computed(() => ({
    start: startIso.value,
    end: endIso.value,
}));

const clientParams = computed(() => ({
    start: startIso.value,
    end: endIso.value,
    billable: 'true' as const,
    group: 'client' as const,
}));

const monthlyParams = computed(() => ({
    start: startIso.value,
    end: endIso.value,
    billable: 'true' as const,
    group: 'month' as const,
    fill_gaps_in_time_groups: 'true' as const,
}));

const { data: billableData, isLoading: billableLoading } = useAggregatedTimeEntriesQuery(
    'business-billable',
    billableParams
);
const { data: totalData } = useAggregatedTimeEntriesQuery('business-total', totalParams);
const { data: clientData, isLoading: clientLoading } = useAggregatedTimeEntriesQuery(
    'business-clients',
    clientParams
);
const { data: monthlyData, isLoading: monthlyLoading } = useAggregatedTimeEntriesQuery(
    'business-monthly',
    monthlyParams
);
const { clients } = useClientsQuery();

// ─── KPIs ───────────────────────────────────────────────────────────────────
const totalRevenue = computed(() => billableData.value?.data?.cost ?? 0);
const billableSeconds = computed(() => billableData.value?.data?.seconds ?? 0);
const totalSeconds = computed(() => totalData.value?.data?.seconds ?? 0);
const currency = computed(() => getOrganizationCurrencyString());

const billingRate = computed(() => {
    if (totalSeconds.value === 0) return 0;
    return Math.round((billableSeconds.value / totalSeconds.value) * 100);
});

function formatMoney(cents: number) {
    return formatCents(
        cents,
        currency.value,
        organization?.value?.currency_format,
        organization?.value?.currency_symbol,
        organization?.value?.number_format
    );
}

function formatDuration(seconds: number) {
    return formatHumanReadableDuration(
        seconds,
        organization?.value?.interval_format,
        organization?.value?.number_format
    );
}

// ─── Client breakdown ───────────────────────────────────────────────────────
const clientMap = computed(() => {
    const map: Record<string, string> = {};
    clients.value.forEach((c) => {
        map[c.id] = c.name;
    });
    return map;
});

const clientRows = computed(() => {
    return (clientData.value?.data?.grouped_data ?? [])
        .map((row) => ({
            id: row.key,
            name: row.key ? (clientMap.value[row.key] ?? 'Unknown Client') : 'No Client',
            seconds: row.seconds,
            cost: row.cost ?? 0,
        }))
        .sort((a, b) => b.cost - a.cost);
});

// ─── Monthly chart ──────────────────────────────────────────────────────────
const monthlyGroupedData = computed(() => monthlyData.value?.data?.grouped_data ?? []);
</script>

<template>
    <MainContainer
        class="h-14 sm:h-16 border-b border-default-background-separator flex flex-wrap gap-y-3 justify-between items-center">
        <div class="flex items-center space-x-3 sm:space-x-6">
            <PageTitle :icon="BriefcaseIcon" title="Reporting"></PageTitle>
            <ReportingTabNavbar active="business" class="hidden sm:flex"></ReportingTabNavbar>
        </div>
        <div class="flex gap-1 bg-secondary border border-input-border rounded-lg p-1">
            <button
                v-for="option in periodOptions"
                :key="option.key"
                type="button"
                class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
                :class="
                    selectedPeriod === option.key
                        ? 'bg-default-background-separator text-text-primary'
                        : 'text-text-secondary hover:text-text-primary'
                "
                @click="selectedPeriod = option.key">
                {{ option.label }}
            </button>
        </div>
    </MainContainer>
    <MainContainer class="sm:hidden py-2 border-b border-default-background-separator">
        <ReportingTabNavbar active="business"></ReportingTabNavbar>
    </MainContainer>

    <MainContainer class="py-5 sm:py-8">
        <!-- KPI cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <StatCard
                title="Total Revenue"
                :value="billableLoading ? '...' : formatMoney(totalRevenue)" />
            <StatCard
                title="Billable Hours"
                :value="billableLoading ? '...' : formatDuration(billableSeconds)" />
            <StatCard title="Billing Rate" :value="billableLoading ? '...' : `${billingRate}%`" />
        </div>

        <!-- Monthly revenue chart -->
        <div class="rounded-lg bg-card-background border border-card-border shadow-card p-4 mb-6">
            <h2 class="text-sm font-semibold text-text-secondary mb-4">Monthly Revenue</h2>
            <div v-if="monthlyLoading" class="h-[300px] flex items-center justify-center">
                <div class="w-full h-full flex items-end gap-2 pb-12 px-2">
                    <div
                        v-for="i in 6"
                        :key="i"
                        class="flex-1 bg-default-background-separator rounded-t-xl animate-pulse"
                        :style="{ height: `${20 + Math.random() * 60}%` }"></div>
                </div>
            </div>
            <ReportingChart
                v-else
                metric="cost"
                :currency="currency"
                :grouped-data="monthlyGroupedData"
                grouped-type="month"></ReportingChart>
        </div>

        <!-- Client breakdown -->
        <div class="rounded-lg bg-card-background border border-card-border shadow-card p-4">
            <h2 class="text-sm font-semibold text-text-secondary mb-4">Revenue by Client</h2>

            <div v-if="clientLoading" class="space-y-3">
                <div
                    v-for="i in 4"
                    :key="i"
                    class="h-9 bg-default-background-separator rounded animate-pulse"></div>
            </div>

            <div v-else-if="clientRows.length > 0" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-text-tertiary border-b border-default-background-separator">
                            <th class="text-left pb-3 font-medium">Client</th>
                            <th class="text-right pb-3 font-medium">Billable Hours</th>
                            <th class="text-right pb-3 font-medium">Revenue</th>
                            <th class="text-right pb-3 font-medium w-24">% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in clientRows"
                            :key="row.id ?? 'none'"
                            class="border-b border-default-background-separator last:border-0 hover:bg-default-background-separator/50 transition-colors">
                            <td class="py-3 text-text-primary font-medium">{{ row.name }}</td>
                            <td class="py-3 text-right text-text-secondary">
                                {{ formatDuration(row.seconds) }}
                            </td>
                            <td class="py-3 text-right text-text-primary font-semibold">
                                {{ formatMoney(row.cost) }}
                            </td>
                            <td class="py-3 text-right text-text-secondary">
                                {{
                                    totalRevenue > 0
                                        ? `${Math.round((row.cost / totalRevenue) * 100)}%`
                                        : '—'
                                }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="clientRows.length > 1">
                        <tr class="border-t-2 border-default-background-separator">
                            <td class="pt-3 text-text-primary font-semibold">Total</td>
                            <td class="pt-3 text-right text-text-secondary font-semibold">
                                {{ formatDuration(billableSeconds) }}
                            </td>
                            <td class="pt-3 text-right text-text-primary font-semibold">
                                {{ formatMoney(totalRevenue) }}
                            </td>
                            <td class="pt-3 text-right text-text-secondary">100%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div v-else class="py-10 text-center">
                <p class="text-text-tertiary text-sm">No billable time entries for this period</p>
            </div>
        </div>
    </MainContainer>
</template>
