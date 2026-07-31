<script setup lang="ts">
import { computed } from 'vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '..';
import { Field, FieldDescription, FieldLabel } from '../field';
import { BuildingOffice2Icon } from '@heroicons/vue/20/solid';

const isInternal = defineModel<boolean>({ default: false });

const kind = computed({
    get: () => (isInternal.value ? 'internal' : 'client'),
    set: (value: string) => {
        isInternal.value = value === 'internal';
    },
});

const description = computed(() =>
    isInternal.value
        ? 'Own-business work. These hours are never invoiced, but they still count as time spent on the business.'
        : 'Work done for a client. These hours can be invoiced.'
);
</script>

<template>
    <Field>
        <FieldLabel :icon="BuildingOffice2Icon" for="project-kind">Work type</FieldLabel>
        <Select v-model="kind">
            <SelectTrigger id="project-kind">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="client">Client work</SelectItem>
                <SelectItem value="internal">Internal</SelectItem>
            </SelectContent>
        </Select>
        <FieldDescription>{{ description }}</FieldDescription>
    </Field>
</template>

<style scoped></style>
