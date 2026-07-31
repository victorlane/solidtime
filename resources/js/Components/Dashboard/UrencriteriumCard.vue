<script setup lang="ts">
import { computed } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { ScaleIcon } from '@heroicons/vue/20/solid';
import DashboardCard from '@/Components/Dashboard/DashboardCard.vue';
import { api } from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';

const organizationId = computed(() => getCurrentOrganizationId());

const { data: urencriterium } = useQuery({
    queryKey: ['urencriterium', organizationId],
    queryFn: () =>
        api.urencriterium({
            params: { organization: organizationId.value! },
        }),
    enabled: computed(() => !!organizationId.value),
});

function hours(seconds: number | undefined): number {
    return Math.round((seconds ?? 0) / 3600);
}

const trackedHours = computed(() => hours(urencriterium.value?.tracked_seconds));
const requiredHours = computed(() => hours(urencriterium.value?.required_seconds));
const billableHours = computed(() => hours(urencriterium.value?.billable_seconds));
const internalHours = computed(() => hours(urencriterium.value?.internal_seconds));

/** Client work that is not billable is still client work, so it is neither of the two named buckets. */
const otherHours = computed(() =>
    Math.max(trackedHours.value - billableHours.value - internalHours.value, 0)
);

const percentage = computed(() => {
    if (!requiredHours.value) return 0;
    return Math.min(Math.round((trackedHours.value / requiredHours.value) * 100), 100);
});

const projectedHours = computed(() => hours(urencriterium.value?.projected_seconds));
const onTrack = computed(() => projectedHours.value >= requiredHours.value);

const perRemainingDay = computed(() => {
    const seconds = urencriterium.value?.required_seconds_per_remaining_day ?? 0;
    if (seconds === 0) return null;
    return (seconds / 3600).toFixed(1);
});
</script>

<template>
    <DashboardCard title="Urencriterium" :icon="ScaleIcon">
        <div class="p-4 flex flex-col gap-3">
            <div class="flex items-baseline justify-between">
                <div>
                    <span class="text-2xl font-semibold text-text-primary">
                        {{ trackedHours }}
                    </span>
                    <span class="text-sm text-text-secondary"> / {{ requiredHours }} h</span>
                </div>
                <span
                    class="text-xs font-medium px-2 py-0.5 rounded"
                    :class="
                        onTrack
                            ? 'bg-green-500/15 text-green-400'
                            : 'bg-amber-500/15 text-amber-400'
                    ">
                    {{ onTrack ? 'On track' : 'Behind' }}
                </span>
            </div>

            <div class="h-2 w-full rounded-full bg-secondary overflow-hidden">
                <div
                    class="h-full rounded-full transition-all"
                    :class="onTrack ? 'bg-green-500' : 'bg-amber-500'"
                    :style="{ width: percentage + '%' }"></div>
            </div>

            <dl class="grid grid-cols-3 gap-2 text-sm">
                <div>
                    <dt class="text-text-secondary text-xs">Billable</dt>
                    <dd class="text-text-primary font-medium">{{ billableHours }} h</dd>
                </div>
                <div>
                    <dt class="text-text-secondary text-xs">Internal</dt>
                    <dd class="text-text-primary font-medium">{{ internalHours }} h</dd>
                </div>
                <div>
                    <dt class="text-text-secondary text-xs">Other</dt>
                    <dd class="text-text-primary font-medium">{{ otherHours }} h</dd>
                </div>
            </dl>

            <p class="text-xs text-text-secondary">
                Projected <strong class="text-text-primary">{{ projectedHours }} h</strong> by year
                end<template v-if="perRemainingDay">
                    &middot; {{ perRemainingDay }} h/day needed from here</template
                >.
            </p>
            <p class="text-xs text-text-tertiary">
                Internal hours count toward the 1225, even though they are never invoiced. Standby
                time does not count and must be excluded manually.
            </p>
        </div>
    </DashboardCard>
</template>

<style scoped></style>
