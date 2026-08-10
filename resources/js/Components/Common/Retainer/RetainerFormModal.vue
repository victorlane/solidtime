<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import TextareaInput from '@/packages/ui/src/Input/TextareaInput.vue';
import Checkbox from '@/packages/ui/src/Input/Checkbox.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/packages/ui/src';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/vue/20/solid';
import { useRetainersStore } from '@/utils/useRetainers';
import type { CreateRetainerBody, Retainer, UpdateRetainerBody } from '@/packages/api/src';

const props = defineProps<{
    clientId: string;
    retainer?: Retainer | null;
}>();

const show = defineModel('show', { default: false });
const saving = ref(false);
const showAdvanced = ref(false);

const isEdit = computed(() => !!props.retainer);

interface FormState {
    name: string;
    description: string;
    period_mode: 'calendar' | 'anchor' | 'explicit';
    period_unit: 'weekly' | 'monthly' | 'quarterly';
    hours_per_period: number;
    anchor_date: string;
    starts_at: string;
    ends_at: string;
    billable_only: boolean;
    hard_cap_enabled: boolean;
    hard_cap_scope: 'per_period' | 'cumulative';
    hard_cap_enforcement: 'block' | 'flag' | 'approval';
    hard_cap_cumulative_hours: number;
    sub_cap_mode: 'soft' | 'strict';
}

function defaultForm(): FormState {
    return {
        name: '',
        description: '',
        period_mode: 'calendar',
        period_unit: 'monthly',
        hours_per_period: 40,
        anchor_date: '',
        starts_at: new Date().toISOString().slice(0, 10),
        ends_at: '',
        billable_only: true,
        hard_cap_enabled: false,
        hard_cap_scope: 'per_period',
        hard_cap_enforcement: 'block',
        hard_cap_cumulative_hours: 0,
        sub_cap_mode: 'soft',
    };
}

const form = ref<FormState>(defaultForm());

function resetFromRetainer() {
    const retainer = props.retainer;
    if (!retainer) {
        form.value = defaultForm();
        showAdvanced.value = false;
        return;
    }
    form.value = {
        name: retainer.name,
        description: retainer.description ?? '',
        period_mode: retainer.period_mode,
        period_unit: retainer.period_unit ?? 'monthly',
        hours_per_period: retainer.seconds_per_period ? retainer.seconds_per_period / 3600 : 40,
        anchor_date: retainer.anchor_date ?? '',
        starts_at: retainer.starts_at,
        ends_at: retainer.ends_at ?? '',
        billable_only: retainer.billable_only,
        hard_cap_enabled: retainer.hard_cap_enabled,
        hard_cap_scope: retainer.hard_cap_scope ?? 'per_period',
        hard_cap_enforcement: retainer.hard_cap_enforcement ?? 'block',
        hard_cap_cumulative_hours: retainer.hard_cap_cumulative_seconds
            ? retainer.hard_cap_cumulative_seconds / 3600
            : 0,
        sub_cap_mode: retainer.sub_cap_mode,
    };
    showAdvanced.value =
        retainer.hard_cap_enabled || retainer.period_mode === 'anchor' || !!retainer.ends_at;
}

watch(() => [show.value, props.retainer], resetFromRetainer, { immediate: true });

const { createRetainer, updateRetainer } = useRetainersStore();

async function submit() {
    saving.value = true;
    try {
        const isExplicit = form.value.period_mode === 'explicit';
        const body: CreateRetainerBody | UpdateRetainerBody = {
            name: form.value.name,
            description: form.value.description || null,
            period_mode: form.value.period_mode,
            period_unit: isExplicit ? null : form.value.period_unit,
            seconds_per_period: isExplicit ? null : Math.round(form.value.hours_per_period * 3600),
            anchor_date: form.value.period_mode === 'anchor' ? form.value.anchor_date : null,
            starts_at: form.value.starts_at,
            ends_at: form.value.ends_at || null,
            billable_only: form.value.billable_only,
            hard_cap_enabled: form.value.hard_cap_enabled,
            hard_cap_scope: form.value.hard_cap_enabled ? form.value.hard_cap_scope : null,
            hard_cap_enforcement: form.value.hard_cap_enabled
                ? form.value.hard_cap_enforcement
                : null,
            hard_cap_cumulative_seconds:
                form.value.hard_cap_enabled && form.value.hard_cap_scope === 'cumulative'
                    ? Math.round(form.value.hard_cap_cumulative_hours * 3600)
                    : null,
            sub_cap_mode: form.value.sub_cap_mode,
        };

        if (isEdit.value && props.retainer) {
            await updateRetainer(props.retainer.id, body as UpdateRetainerBody);
        } else {
            await createRetainer(props.clientId, body as CreateRetainerBody);
        }
        show.value = false;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <DialogModal closeable :show="show" @close="show = false">
        <template #title>
            {{ isEdit ? 'Edit Retainer' : 'New Retainer' }}
        </template>

        <template #content>
            <div class="flex flex-col gap-4">
                <Field>
                    <FieldLabel for="retainerName">Name</FieldLabel>
                    <TextInput
                        id="retainerName"
                        v-model="form.name"
                        type="text"
                        placeholder="Retainer name"
                        class="w-full"
                        required
                        autofocus />
                </Field>

                <Field>
                    <FieldLabel for="retainerPeriodMode">Period mode</FieldLabel>
                    <Select v-model="form.period_mode">
                        <SelectTrigger id="retainerPeriodMode">
                            <SelectValue>{{ form.period_mode }}</SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="calendar"
                                >Calendar (aligns to month/week/quarter)</SelectItem
                            >
                            <SelectItem value="anchor"
                                >Anchor (fixed length from a start date)</SelectItem
                            >
                            <SelectItem value="explicit"
                                >Explicit (manually defined periods)</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </Field>

                <template v-if="form.period_mode !== 'explicit'">
                    <Field>
                        <FieldLabel for="retainerPeriodUnit">Period length</FieldLabel>
                        <Select v-model="form.period_unit">
                            <SelectTrigger id="retainerPeriodUnit">
                                <SelectValue>{{ form.period_unit }}</SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="weekly">Weekly</SelectItem>
                                <SelectItem value="monthly">Monthly</SelectItem>
                                <SelectItem value="quarterly">Quarterly</SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>

                    <Field>
                        <FieldLabel for="retainerHoursPerPeriod">Hours per period</FieldLabel>
                        <TextInput
                            id="retainerHoursPerPeriod"
                            v-model.number="form.hours_per_period"
                            type="number"
                            min="0"
                            step="0.5"
                            class="w-full" />
                    </Field>
                </template>

                <Field>
                    <FieldLabel for="retainerStartsAt">Starts at</FieldLabel>
                    <TextInput
                        id="retainerStartsAt"
                        v-model="form.starts_at"
                        type="date"
                        class="w-full" />
                </Field>

                <template v-if="form.period_mode === 'anchor'">
                    <Field>
                        <FieldLabel for="retainerAnchorDate">Anchor date</FieldLabel>
                        <TextInput
                            id="retainerAnchorDate"
                            v-model="form.anchor_date"
                            type="date"
                            class="w-full" />
                    </Field>
                </template>

                <button
                    type="button"
                    class="flex items-center gap-1 text-sm text-text-secondary hover:text-text-primary self-start"
                    data-testid="retainer_advanced_toggle"
                    @click="showAdvanced = !showAdvanced">
                    <ChevronDownIcon v-if="showAdvanced" class="w-4 h-4"></ChevronDownIcon>
                    <ChevronRightIcon v-else class="w-4 h-4"></ChevronRightIcon>
                    Advanced
                </button>

                <div v-if="showAdvanced" class="flex flex-col gap-4 pl-1">
                    <Field>
                        <FieldLabel for="retainerDescription">Description</FieldLabel>
                        <TextareaInput
                            id="retainerDescription"
                            v-model="form.description"
                            class="w-full"
                            :rows="2" />
                    </Field>

                    <Field>
                        <FieldLabel for="retainerEndsAt">Ends at (optional)</FieldLabel>
                        <TextInput
                            id="retainerEndsAt"
                            v-model="form.ends_at"
                            type="date"
                            class="w-full" />
                    </Field>

                    <Field orientation="horizontal">
                        <Checkbox
                            id="retainerBillableOnly"
                            :checked="form.billable_only"
                            @update:checked="form.billable_only = $event as boolean" />
                        <FieldLabel for="retainerBillableOnly"
                            >Only billable time entries count toward consumption</FieldLabel
                        >
                    </Field>

                    <Field orientation="horizontal">
                        <Checkbox
                            id="retainerHardCapEnabled"
                            :checked="form.hard_cap_enabled"
                            @update:checked="form.hard_cap_enabled = $event as boolean" />
                        <FieldLabel for="retainerHardCapEnabled"
                            >Block saving once the budget is exhausted</FieldLabel
                        >
                    </Field>

                    <template v-if="form.hard_cap_enabled">
                        <Field>
                            <FieldLabel for="retainerHardCapScope">Cap scope</FieldLabel>
                            <Select v-model="form.hard_cap_scope">
                                <SelectTrigger id="retainerHardCapScope">
                                    <SelectValue>{{ form.hard_cap_scope }}</SelectValue>
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="per_period"
                                        >Per period (this period's prorated allocation)</SelectItem
                                    >
                                    <SelectItem value="cumulative"
                                        >Cumulative (a single fixed budget)</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field v-if="form.hard_cap_scope === 'cumulative'">
                            <FieldLabel for="retainerHardCapHours"
                                >Cumulative cap (hours)</FieldLabel
                            >
                            <TextInput
                                id="retainerHardCapHours"
                                v-model.number="form.hard_cap_cumulative_hours"
                                type="number"
                                min="0"
                                step="0.5"
                                class="w-full" />
                        </Field>

                        <Field>
                            <FieldLabel for="retainerSubCapMode">Per-project sub-caps</FieldLabel>
                            <Select v-model="form.sub_cap_mode">
                                <SelectTrigger id="retainerSubCapMode">
                                    <SelectValue>{{ form.sub_cap_mode }}</SelectValue>
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="soft">Soft (informational only)</SelectItem>
                                    <SelectItem value="strict"
                                        >Strict (also blocks saving)</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </Field>
                    </template>
                </div>
            </div>
        </template>

        <template #footer>
            <SecondaryButton @click="show = false">Cancel</SecondaryButton>
            <PrimaryButton
                class="ms-3"
                :class="{ 'opacity-25': saving }"
                :disabled="saving"
                data-testid="retainer_form_submit"
                @click="submit">
                {{ isEdit ? 'Save Retainer' : 'Create Retainer' }}
            </PrimaryButton>
        </template>
    </DialogModal>
</template>

<style scoped></style>
