<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import ActionMessage from '@/Components/ActionMessage.vue';
import FormSection from '@/Components/FormSection.vue';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import { Checkbox } from '@/packages/ui/src';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import { useUpdateUserMutation, useUserQuery } from '@/utils/useUserQuery';
import { HIDEABLE_NAV_ITEMS, type HideableNavItem } from '@/utils/navVisibility';
import type { User } from '@/packages/api/src';

const NAV_ITEM_LABELS: Record<HideableNavItem, string> = {
    time: 'Time',
    calendar: 'Calendar',
    timesheet: 'Timesheet',
    tags: 'Tags',
    projects: 'Projects',
    clients: 'Clients',
    members: 'Members',
    import: 'Import / Export',
    reporting_shared: 'Reporting: Shared',
    dashboard_billable_widgets: 'Dashboard: billable time & amount',
};

const { user } = useUserQuery();
const updateUser = useUpdateUserMutation();

// Keyed by nav item -> whether it's VISIBLE (checkbox is framed as "show", the
// server stores the inverse "hidden" set).
const visible = ref<Record<HideableNavItem, boolean>>(
    Object.fromEntries(HIDEABLE_NAV_ITEMS.map((key) => [key, true])) as Record<
        HideableNavItem,
        boolean
    >
);

const seeded = ref(false);

function seedForm(u: User) {
    const hidden = new Set(u.hidden_nav_items ?? []);
    for (const key of HIDEABLE_NAV_ITEMS) {
        visible.value[key] = !hidden.has(key);
    }
    seeded.value = true;
}

watch(
    user,
    (u) => {
        if (u && !seeded.value) seedForm(u);
    },
    { immediate: true }
);

const isUserLoaded = computed(() => user.value !== undefined);
const isSaveDisabled = computed(() => !isUserLoaded.value || updateUser.isPending.value);
const recentlySaved = ref(false);

function flashSaved() {
    recentlySaved.value = true;
    setTimeout(() => (recentlySaved.value = false), 2000);
}

async function save() {
    if (isSaveDisabled.value || !user.value) return;
    const hiddenNavItems = HIDEABLE_NAV_ITEMS.filter((key) => !visible.value[key]);
    try {
        await updateUser.mutateAsync({
            userId: user.value.id,
            body: { hidden_nav_items: hiddenNavItems },
        });
        // The sidebar/nav reads this from the shared Inertia `auth.user` prop,
        // not from the `useUserQuery()` cache, so refresh it to reflect the
        // change immediately without a full page reload.
        router.reload({ only: ['auth'] });
        flashSaved();
    } catch {
        // notification handled by the mutation
    }
}
</script>

<template>
    <FormSection @submitted="save">
        <template #title>Sidebar &amp; features</template>

        <template #description>
            Choose which sections appear in your sidebar. This only affects your own view, not other
            members of the organization.
        </template>

        <template #form>
            <Field
                v-for="key in HIDEABLE_NAV_ITEMS"
                :key="key"
                class="col-span-6 sm:col-span-3"
                orientation="horizontal">
                <Checkbox :id="`nav_item_${key}`" v-model:checked="visible[key]" />
                <FieldLabel :for="`nav_item_${key}`">{{ NAV_ITEM_LABELS[key] }}</FieldLabel>
            </Field>
        </template>

        <template #actions>
            <ActionMessage :on="recentlySaved" class="me-3"> Saved. </ActionMessage>

            <PrimaryButton :class="{ 'opacity-25': isSaveDisabled }" :disabled="isSaveDisabled">
                Save
            </PrimaryButton>
        </template>
    </FormSection>
</template>
