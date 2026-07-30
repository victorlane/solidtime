<script setup lang="ts">
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { ref } from 'vue';
import type { CreateProjectMemberBody, ProjectMember } from '@/packages/api/src';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import { useFocus } from '@vueuse/core';
import { useProjectMembersStore } from '@/utils/useProjectMembers';
import MemberCombobox from '@/Components/Common/Member/MemberCombobox.vue';
import BillableRateInput from '@/packages/ui/src/Input/BillableRateInput.vue';
import WeeklyTargetInput from '@/packages/ui/src/Input/WeeklyTargetInput.vue';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import { getOrganizationCurrencyString } from '@/utils/money';
const { createProjectMember } = useProjectMembersStore();
const show = defineModel('show', { default: false });
const saving = ref(false);

const props = defineProps<{
    projectId: string;
    existingMembers: ProjectMember[];
}>();

const projectMember = ref<CreateProjectMemberBody>({
    member_id: '',
    billable_rate: null,
    weekly_billable_target: null,
});

async function submit() {
    await createProjectMember(props.projectId, projectMember.value);
    show.value = false;
    projectMember.value = {
        member_id: '',
        billable_rate: null,
        weekly_billable_target: null,
    };
}

const projectNameInput = ref<HTMLInputElement | null>(null);

useFocus(projectNameInput, { initialValue: true });
</script>

<template>
    <DialogModal closeable :show="show" @close="show = false">
        <template #title>
            <div class="flex space-x-2">
                <span>Add Project Member</span>
            </div>
        </template>

        <template #content>
            <div class="grid grid-cols-3 items-center space-x-4">
                <div class="col-span-3 sm:col-span-2">
                    <MemberCombobox
                        v-model="projectMember.member_id"
                        :hidden-members="props.existingMembers"></MemberCombobox>
                </div>
                <div class="col-span-3 sm:col-span-1 flex-1">
                    <BillableRateInput
                        v-model="projectMember.billable_rate"
                        name="billable_rate"
                        :currency="getOrganizationCurrencyString()"></BillableRateInput>
                </div>
            </div>
            <div class="grid grid-cols-3 items-center space-x-4 pt-4">
                <Field class="col-span-3 sm:col-span-1">
                    <FieldLabel for="weekly_billable_target"
                        >Weekly billable hours target</FieldLabel
                    >
                    <WeeklyTargetInput
                        v-model="projectMember.weekly_billable_target"
                        name="weekly_billable_target"></WeeklyTargetInput>
                </Field>
            </div>
        </template>
        <template #footer>
            <SecondaryButton @click="show = false">Cancel</SecondaryButton>
            <PrimaryButton
                class="ms-3"
                :class="{ 'opacity-25': saving }"
                :disabled="saving"
                @click="submit">
                Add Project Member
            </PrimaryButton>
        </template>
    </DialogModal>
</template>

<style scoped></style>
