<script setup lang="ts">
import { ref } from 'vue';
import TimezoneMismatchModal from '@/packages/ui/src/TimezoneMismatchModal.vue';
import { useUserQuery, useUpdateUserMutation } from '@/utils/useUserQuery';

const show = defineModel('show', { default: false });
const saving = ref(false);

const { user } = useUserQuery();
const updateUserMutation = useUpdateUserMutation();

async function handleUpdate(timezone: string) {
    if (!user.value) {
        return;
    }
    saving.value = true;
    try {
        await updateUserMutation.mutateAsync({
            userId: user.value.id,
            body: { timezone },
        });
        show.value = false;
        location.reload();
    } catch {
        // Notification is handled by the mutation; keep the modal open.
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <TimezoneMismatchModal v-model:show="show" :saving="saving" @update="handleUpdate" />
</template>

<style scoped></style>
