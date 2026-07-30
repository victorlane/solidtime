<script setup lang="ts">
import { computed } from 'vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';

// WeeklyTargetInput edits a weekly billable-hours target. The model value is
// in seconds (the API unit for durations); the input shows and accepts hours,
// with comma or point decimals. Empty means "no target".
const model = defineModel<number | null>({ default: null });

defineProps<{
    name?: string;
}>();

const hours = computed({
    get: () => (model.value === null ? '' : String(Math.round((model.value / 3600) * 100) / 100)),
    set: (value: string | number) => {
        const parsed = parseFloat(String(value).replace(',', '.'));
        model.value = isNaN(parsed) || parsed <= 0 ? null : Math.round(parsed * 3600);
    },
});
</script>

<template>
    <TextInput
        v-model="hours"
        type="text"
        inputmode="decimal"
        :name="name"
        placeholder="e.g. 32"
        class="w-full" />
</template>

<style scoped></style>
