<script setup lang="ts">
import { computed } from 'vue';
import { BanknotesIcon } from '@heroicons/vue/20/solid';
import { PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';
import DashboardCard from '@/Components/Dashboard/DashboardCard.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { useRetainerStatusQuery } from '@/utils/useRetainerStatusQuery';
import { canDeleteRetainers, canUpdateRetainers } from '@/utils/permissions';
import type { Retainer } from '@/packages/api/src';

const props = defineProps<{
    retainer: Retainer;
}>();

const emit = defineEmits<{
    edit: [];
    delete: [];
}>();

const { status, isLoading } = useRetainerStatusQuery(props.retainer.id);

function hours(seconds: number | undefined): number {
    return Math.round(((seconds ?? 0) / 3600) * 10) / 10;
}

// Prefer the current-period view (matches what the badge/status card should agree on):
// it shows the un-prorated allocation for "this period" rather than the cumulative
// since-inception total, which is what most retainer clients care about day to day.
const period = computed(() => status.value?.current_period ?? null);

const trackedHours = computed(() =>
    hours(period.value ? period.value.tracked_seconds : status.value?.tracked_seconds)
);
const allocatedHours = computed(() =>
    hours(period.value ? period.value.allocated_seconds : status.value?.allocated_seconds)
);
const percent = computed(() => {
    if (period.value) return period.value.percent * 100;
    return (status.value?.percent ?? 0) * 100;
});

const progressWidth = computed(() => Math.min(Math.round(percent.value), 100));

/** green under 90%, amber 90-100%, red over 100% -- matches the retainer badge thresholds. */
const severity = computed<'ok' | 'warn' | 'over'>(() => {
    if (percent.value >= 100) return 'over';
    if (percent.value >= 90) return 'warn';
    return 'ok';
});

const barColorClass = computed(() => {
    return {
        ok: 'bg-green-500',
        warn: 'bg-amber-500',
        over: 'bg-red-500',
    }[severity.value];
});

const badgeColorClass = computed(() => {
    return {
        ok: 'bg-green-500/15 text-green-400',
        warn: 'bg-amber-500/15 text-amber-400',
        over: 'bg-red-500/15 text-red-400',
    }[severity.value];
});

const badgeLabel = computed(() => {
    return { ok: 'On track', warn: 'Near cap', over: 'Over cap' }[severity.value];
});
</script>

<template>
    <DashboardCard :title="retainer.name" :icon="BanknotesIcon">
        <div v-if="isLoading" class="p-4 text-sm text-text-secondary">Loading...</div>
        <div v-else class="p-4 flex flex-col gap-3">
            <div class="flex items-baseline justify-between">
                <div>
                    <span class="text-2xl font-semibold text-text-primary">
                        {{ trackedHours }}
                    </span>
                    <span class="text-sm text-text-secondary"> / {{ allocatedHours }} h</span>
                </div>
                <span
                    class="text-xs font-medium px-2 py-0.5 rounded"
                    :class="badgeColorClass"
                    data-testid="retainer_status_badge">
                    {{ badgeLabel }}
                </span>
            </div>

            <div class="h-2 w-full rounded-full bg-secondary overflow-hidden">
                <div
                    class="h-full rounded-full transition-all"
                    :class="barColorClass"
                    :style="{ width: progressWidth + '%' }"></div>
            </div>

            <div v-if="period" class="text-xs text-text-secondary">
                This period ({{ period.starts_at }} – {{ period.ends_at }})
            </div>
            <div v-else class="text-xs text-text-secondary">
                No period is currently active for this retainer.
            </div>

            <div
                v-if="retainer.hard_cap_enabled"
                class="text-xs text-text-tertiary"
                data-testid="retainer_hard_cap_notice">
                Hard cap enabled ({{
                    retainer.hard_cap_scope === 'cumulative' ? 'cumulative' : 'per period'
                }}<template v-if="retainer.hard_cap_enforcement !== 'block'">
                    , {{ retainer.hard_cap_enforcement }} only, not enforced yet</template
                >).
            </div>

            <div v-if="canUpdateRetainers() || canDeleteRetainers()" class="flex gap-2 pt-1">
                <SecondaryButton
                    v-if="canUpdateRetainers()"
                    :icon="PencilSquareIcon"
                    data-testid="retainer_edit_button"
                    @click="emit('edit')">
                    Edit
                </SecondaryButton>
                <SecondaryButton
                    v-if="canDeleteRetainers()"
                    :icon="TrashIcon"
                    class="text-destructive"
                    data-testid="retainer_delete_button"
                    @click="emit('delete')">
                    Delete
                </SecondaryButton>
            </div>
        </div>
    </DashboardCard>
</template>

<style scoped></style>
