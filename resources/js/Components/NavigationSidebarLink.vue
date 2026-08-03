<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, type Component } from 'vue';

const props = withDefaults(
    defineProps<{
        title: string;
        icon?: Component;
        current?: boolean;
        href: string;
        // Renders a plain anchor that opens in a new tab instead of an Inertia visit. Needed for
        // targets that are not Inertia pages, e.g. the server rendered API documentation.
        external?: boolean;
    }>(),
    {
        icon: undefined,
        current: false,
        external: false,
    }
);

const linkComponent = computed<Component | string>(() => (props.external ? 'a' : Link));

const linkAttributes = computed(() =>
    props.external
        ? { href: props.href, target: '_blank', rel: 'noopener noreferrer' }
        : { href: props.href, prefetch: true }
);
</script>

<template>
    <component :is="linkComponent" v-bind="linkAttributes" class="block group">
        <div
            :class="[
                current
                    ? 'bg-menu-active text-text-primary'
                    : 'text-text-secondary group-hover:text-text-primary group-hover:bg-menu-active ',
                'group flex gap-x-2 rounded-md transition leading-6 py-0.5 px-2 font-medium text-sm items-center',
            ]">
            <component
                :is="icon"
                v-if="icon"
                :class="[
                    current ? 'text-icon-active' : 'text-icon-default group-hover:text-icon-active',
                    'transition h-4 w-4 shrink-0',
                ]"
                aria-hidden="true" />
            {{ title }}
        </div>
    </component>
</template>

<style scoped></style>
