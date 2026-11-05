<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import DatePicker from '@/packages/ui/src/Input/DatePicker.vue';
import { getDayJsInstance, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import dayjs from 'dayjs';
import TimePickerSimple from '@/packages/ui/src/Input/TimePickerSimple.vue';
import QuickHourPicker from '@/packages/ui/src/Input/QuickHourPicker.vue';
import { Button } from '@/packages/ui/src/Buttons';

const props = defineProps<{
    start: string;
    end: string | null;
    focus?: boolean;
}>();

// The timestamps for the changed event are UTC
const emit = defineEmits(['changed', 'close']);

const tempStart = ref(props.start ? getLocalizedDayJs(props.start).format() : dayjs().format());
const tempEnd = ref(props.end ? getLocalizedDayJs(props.end).format() : null);
const showEndTimePicker = ref(false);
// quickDurationPending is true after a quick hour pick that has not been
// applied yet. The pick only fills in the pickers; nothing is saved until the
// user presses Apply, so they can check or adjust the range first.
const quickDurationPending = ref(false);

watch(props, () => {
    tempStart.value = getLocalizedDayJs(props.start).format();
    tempEnd.value = props.end ? getLocalizedDayJs(props.end).format() : null;
    showEndTimePicker.value = false;
    quickDurationPending.value = false;
});

// onPickerChanged saves edits made directly in the pickers, except while a
// quick duration is waiting to be applied: then the edit only adjusts it.
function onPickerChanged() {
    if (!quickDurationPending.value) {
        updateTimeEntry();
    }
}

function updateTimeEntry() {
    const tempStartUtc = getDayJsInstance()(tempStart.value).utc().format();
    const tempEndUtc = tempEnd.value ? getDayJsInstance()(tempEnd.value).utc().format() : null;

    if (tempStartUtc !== props.start || tempEndUtc !== props.end) {
        emit(
            'changed',
            getDayJsInstance()(tempStart.value).utc().format(),
            tempEnd.value ? getDayJsInstance()(tempEnd.value).utc().format() : null
        );
    }
}

function setEndTime() {
    showEndTimePicker.value = true;
    tempEnd.value = getDayJsInstance()().format();
}

function confirmEndTime() {
    quickDurationPending.value = false;
    // wait for the v-model for the end time to update
    nextTick(() => {
        updateTimeEntry();
        showEndTimePicker.value = false;
        emit('close');
    });
}

// setQuickDuration applies an "N hours" pick. For a finished entry the end
// moves to start + N. For a running entry (no end yet) N means "I have been
// working N hours": the entry is stopped at the current minute and the start
// moves to N hours before that. Stopping it keeps the duration at exactly N
// hours; leaving it running would let seconds accumulate on top, which then
// leak into invoices.
//
// The pick is not saved here. It fills in the pickers and waits for Apply.
function setQuickDuration(hours: number) {
    const minutes = Math.round(hours * 60);
    const isRunning =
        props.end === null && (!showEndTimePicker.value || quickDurationPending.value);
    if (isRunning) {
        const end = getDayJsInstance()().startOf('minute');
        tempStart.value = end.subtract(minutes, 'm').format();
        tempEnd.value = end.format();
        showEndTimePicker.value = true;
    } else {
        tempEnd.value = getDayJsInstance()(tempStart.value).add(minutes, 'm').format();
    }
    quickDurationPending.value = true;
}

function applyQuickDuration() {
    quickDurationPending.value = false;
    nextTick(() => {
        updateTimeEntry();
        showEndTimePicker.value = false;
        emit('close');
    });
}

const dropdownContent = ref();
</script>

<template>
    <div ref="dropdownContent" class="flex flex-col py-2">
        <div class="grid grid-cols-2 divide-x divide-card-background-separator text-center">
            <div class="px-2" @keydown.enter.prevent="nextTick(() => emit('close'))">
                <div class="font-semibold text-text-primary text-sm pb-2">Start</div>
                <div class="flex flex-col items-center space-y-2 w-28 mx-auto">
                    <TimePickerSimple
                        v-model="tempStart"
                        class="w-full"
                        data-testid="time_entry_range_start"
                        tabindex="0"
                        :focus
                        @keydown.exact.tab.shift.stop.prevent="emit('close')"
                        @changed="onPickerChanged"></TimePickerSimple>
                    <DatePicker
                        v-model="tempStart"
                        class="w-full"
                        size="sm"
                        @changed="onPickerChanged"></DatePicker>
                </div>
            </div>
            <div class="px-2">
                <div class="font-semibold text-text-primary text-sm pb-2">End</div>
                <div
                    v-if="end !== null && tempEnd !== null"
                    class="flex flex-col items-center space-y-2 w-28 mx-auto">
                    <TimePickerSimple
                        v-model="tempEnd"
                        class="w-full"
                        data-testid="time_entry_range_end"
                        @changed="onPickerChanged"></TimePickerSimple>
                    <DatePicker
                        v-model="tempEnd"
                        class="w-full"
                        size="sm"
                        @changed="onPickerChanged"></DatePicker>
                </div>
                <div v-else-if="end === null && !showEndTimePicker">
                    <Button variant="outline" size="sm" @click="setEndTime"> Set End Time </Button>
                </div>
                <div
                    v-else-if="showEndTimePicker && tempEnd !== null"
                    class="flex flex-col items-center space-y-2 w-28 mx-auto">
                    <TimePickerSimple
                        v-model="tempEnd"
                        class="w-full"
                        data-testid="time_entry_range_end"
                        @keydown.enter.prevent.stop="confirmEndTime"></TimePickerSimple>
                    <DatePicker v-model="tempEnd" class="w-full" size="sm"></DatePicker>
                    <Button
                        v-if="!quickDurationPending"
                        variant="outline"
                        size="sm"
                        class="w-full"
                        @click="confirmEndTime">
                        Confirm
                    </Button>
                </div>
                <div v-else class="text-text-secondary">-- : --</div>
                <div tabindex="0" @focusin="emit('close')"></div>
            </div>
        </div>
        <div class="border-t border-card-background-separator mt-2 pt-2 px-2">
            <div class="font-semibold text-text-primary text-sm pb-2 text-center">Duration</div>
            <QuickHourPicker @select="setQuickDuration"></QuickHourPicker>
            <Button
                v-if="quickDurationPending"
                size="sm"
                class="w-full mt-2"
                data-testid="quick_hour_apply"
                @click="applyQuickDuration">
                Apply
            </Button>
        </div>
    </div>
</template>

<style></style>
