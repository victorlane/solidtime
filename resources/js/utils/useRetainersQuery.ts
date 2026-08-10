import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { api } from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';
import type { Retainer } from '@/packages/api/src';
import { computed, type Ref } from 'vue';

export async function fetchRetainersForClient(
    organizationId: string,
    clientId: string
): Promise<Retainer[]> {
    const response = await api.getRetainersForClient({
        params: { organization: organizationId, client: clientId },
    });
    return response.data;
}

export function useRetainersForClientQuery(clientId: Ref<string | null> | string) {
    const queryClient = useQueryClient();

    const clientIdValue = computed(() => {
        return typeof clientId === 'string' ? clientId : clientId.value;
    });

    const query = useQuery({
        queryKey: computed(() => ['retainers', getCurrentOrganizationId(), clientIdValue.value]),
        queryFn: async () => {
            const organizationId = getCurrentOrganizationId();
            const cid = clientIdValue.value;
            if (!organizationId || !cid) throw new Error('No organization or client');
            const data = await fetchRetainersForClient(organizationId, cid);
            return { data };
        },
        enabled: () => !!getCurrentOrganizationId() && !!clientIdValue.value,
        staleTime: 1000 * 30, // 30 seconds
    });

    const retainers = computed<Retainer[]>(() => query.data.value?.data ?? []);

    /** The retainer currently active for the client (at most one, enforced server-side). */
    const activeRetainer = computed<Retainer | undefined>(() => {
        const today = new Date().toISOString().slice(0, 10);
        return retainers.value.find(
            (retainer) =>
                retainer.starts_at <= today && (!retainer.ends_at || retainer.ends_at >= today)
        );
    });

    const invalidateRetainers = () => {
        queryClient.invalidateQueries({
            queryKey: ['retainers', getCurrentOrganizationId(), clientIdValue.value],
        });
    };

    return {
        ...query,
        retainers,
        activeRetainer,
        invalidateRetainers,
    };
}
