<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/packages/ui/src/Buttons';

// QuickHourPicker renders a row of 1..12 hour chips plus a free numeric
// input, and emits the chosen duration in hours. It deliberately knows
// nothing about start/end semantics — the parent decides whether N hours
// means "end = start + N" (finished entry) or "start = now - N" (running
// timer), so the same component fits every picker surface.
const emit = defineEmits<{
    select: [hours: number];
}>();

const quickHours = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

const customHours = ref('');

// applyCustom accepts plain numbers as hours ("3", "7.5", "7,5"), unlike the
// duration text input whose bare numbers can mean minutes depending on the
// organization's interval format.
function applyCustom() {
    const value = parseFloat(customHours.value.replace(',', '.'));
    if (!isNaN(value) && value > 0 && value <= 24) {
        emit('select', value);
        customHours.value = '';
    }
}
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div class="grid grid-cols-6 gap-1">
            <Button
                v-for="h in quickHours"
                :key="h"
                variant="outline"
                size="xs"
                class="px-0 tabular-nums"
                :data-testid="`quick_hour_${h}`"
                @click="emit('select', h)">
                {{ h }}h
            </Button>
        </div>
        <input
            v-model="customHours"
            type="text"
            inputmode="decimal"
            placeholder="hours, e.g. 7.5"
            data-testid="quick_hour_custom"
            class="w-full h-7 rounded border-input-border border bg-input-background text-text-primary text-xs px-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            @keydown.enter.prevent="applyCustom"
            @blur="applyCustom" />
    </div>
</template>

<style scoped></style>
