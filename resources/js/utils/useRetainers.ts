import { defineStore } from 'pinia';
import { api } from '@/packages/api/src';
import type { CreateRetainerBody, Retainer, UpdateRetainerBody } from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { useNotificationsStore } from '@/utils/notification';
import { useQueryClient } from '@tanstack/vue-query';

export const useRetainersStore = defineStore('retainers', () => {
    const { handleApiRequestNotifications } = useNotificationsStore();
    const queryClient = useQueryClient();

    async function createRetainer(
        clientId: string,
        retainerBody: CreateRetainerBody
    ): Promise<Retainer | undefined> {
        const organization = getCurrentOrganizationId();
        if (organization) {
            const response = await handleApiRequestNotifications(
                () =>
                    api.createRetainer(retainerBody, {
                        params: {
                            organization: organization,
                            client: clientId,
                        },
                    }),
                'Retainer created successfully',
                'Failed to create retainer'
            );
            queryClient.invalidateQueries({ queryKey: ['retainers'] });
            return response?.data;
        }
    }

    async function updateRetainer(retainerId: string, retainerBody: UpdateRetainerBody) {
        const organization = getCurrentOrganizationId();
        if (organization) {
            await handleApiRequestNotifications(
                () =>
                    api.updateRetainer(retainerBody, {
                        params: {
                            organization: organization,
                            retainer: retainerId,
                        },
                    }),
                'Retainer updated successfully',
                'Failed to update retainer'
            );
            queryClient.invalidateQueries({ queryKey: ['retainers'] });
            queryClient.invalidateQueries({ queryKey: ['retainerStatus', retainerId] });
        }
    }

    async function deleteRetainer(retainerId: string) {
        const organization = getCurrentOrganizationId();
        if (organization) {
            await handleApiRequestNotifications(
                () =>
                    api.deleteRetainer(undefined, {
                        params: {
                            organization: organization,
                            retainer: retainerId,
                        },
                    }),
                'Retainer deleted successfully',
                'Failed to delete retainer'
            );
            queryClient.invalidateQueries({ queryKey: ['retainers'] });
            queryClient.invalidateQueries({ queryKey: ['retainerStatus', retainerId] });
        }
    }

    return { createRetainer, updateRetainer, deleteRetainer };
});
