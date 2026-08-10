import { useQuery } from '@tanstack/vue-query';
import { api } from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { computed, type Ref } from 'vue';

export function useRetainerStatusQuery(retainerId: Ref<string | null> | string) {
    const retainerIdValue = computed(() => {
        return typeof retainerId === 'string' ? retainerId : retainerId.value;
    });

    const query = useQuery({
        queryKey: computed(() => [
            'retainerStatus',
            retainerIdValue.value,
            getCurrentOrganizationId(),
        ]),
        queryFn: async () => {
            const organizationId = getCurrentOrganizationId();
            const rid = retainerIdValue.value;
            if (!organizationId || !rid) throw new Error('No organization or retainer');
            const response = await api.getRetainerStatus({
                params: { organization: organizationId, retainer: rid },
            });
            return response.data;
        },
        enabled: () => !!getCurrentOrganizationId() && !!retainerIdValue.value,
        staleTime: 1000 * 30, // 30 seconds
    });

    return {
        ...query,
        status: computed(() => query.data.value),
    };
}
