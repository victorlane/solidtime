import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { api } from '../client.js';
import {
    compact,
    metadataSchema,
    organizationIdSchema,
    resolveOrganizationId,
    toBooleanFilter,
    utcTimestamp,
    uuid,
} from '../shared.js';
import { json, registerTool } from '../register.js';

const aggregationType = z.enum([
    'day',
    'week',
    'month',
    'year',
    'user',
    'project',
    'task',
    'client',
    'billable',
    'description',
    'tag',
]);

const roundingType = z.enum(['up', 'down', 'nearest']);

const roundingFields = {
    rounding_type: roundingType
        .optional()
        .describe('Round each time entry end. Only applied when rounding_minutes is also set.'),
    rounding_minutes: z
        .number()
        .int()
        .positive()
        .optional()
        .describe('Interval length in minutes that rounding rounds to.'),
};

const filterFields = {
    member_id: uuid.optional().describe('Filter by a single member UUID.'),
    member_ids: z
        .array(uuid)
        .min(1)
        .optional()
        .describe('Filter by several members (OR combined).'),
    project_ids: z
        .array(z.string())
        .min(1)
        .optional()
        .describe('Filter by projects (OR combined). Use "none" for entries without a project.'),
    client_ids: z
        .array(z.string())
        .min(1)
        .optional()
        .describe('Filter by clients (OR combined). Use "none" for entries without a client.'),
    task_ids: z
        .array(z.string())
        .min(1)
        .optional()
        .describe('Filter by tasks (OR combined). Use "none" for entries without a task.'),
    tag_ids: z
        .array(z.string())
        .min(1)
        .optional()
        .describe('Filter by tags (OR combined). Use "none" for entries without tags.'),
    tag_match_type: z
        .enum(['any', 'all'])
        .optional()
        .describe('Whether an entry must match any or all of tag_ids.'),
    start: utcTimestamp
        .optional()
        .describe('Only entries starting at or after this UTC timestamp.'),
    end: utcTimestamp.optional().describe('Only entries starting before this UTC timestamp.'),
    active: z.boolean().optional().describe('True returns only running entries (no end time).'),
    billable: z.boolean().optional().describe('Filter by billable flag.'),
};

function filtersToQuery(input: Record<string, unknown>) {
    return compact({
        ...input,
        active: toBooleanFilter(input.active as boolean | undefined),
        billable: toBooleanFilter(input.billable as boolean | undefined),
    });
}

export function registerTimeEntryTools(server: McpServer): void {
    registerTool(server, {
        name: 'list_time_entries',
        title: 'List time entries',
        description:
            'List time entries in an organization with optional filtering by member, project, client, task, tag, date range and billable status. Returns at most 500 entries per call; page with limit/offset.',
        readOnly: true,
        schema: {
            organization_id: organizationIdSchema,
            ...filterFields,
            ...roundingFields,
            limit: z
                .number()
                .int()
                .min(1)
                .max(500)
                .optional()
                .describe('Max entries to return (API default 150, max 500).'),
            offset: z.number().int().min(0).optional(),
            only_full_dates: z
                .boolean()
                .optional()
                .describe(
                    'Only return entries covering whole dates — useful for day-based exports.'
                ),
        },
        handler: async ({ organization_id, only_full_dates, ...rest }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.get(`/v1/organizations/${orgId}/time-entries`, {
                    ...filtersToQuery(rest),
                    only_full_dates: toBooleanFilter(only_full_dates),
                })
            );
        },
    });

    registerTool(server, {
        name: 'aggregate_time_entries',
        title: 'Aggregate time entries',
        description:
            'Aggregate tracked time and billable amounts, grouped by up to two dimensions (e.g. group by project, sub_group by member). Use this for reporting questions instead of listing every entry.',
        readOnly: true,
        schema: {
            organization_id: organizationIdSchema,
            group: aggregationType.optional().describe('First grouping dimension.'),
            sub_group: aggregationType
                .optional()
                .describe('Second grouping dimension. Requires group.'),
            fill_gaps_in_time_groups: z
                .boolean()
                .optional()
                .describe(
                    'For day/week/month/year groups, emit zero-value buckets for periods with no entries.'
                ),
            ...filterFields,
            ...roundingFields,
        },
        handler: async ({ organization_id, fill_gaps_in_time_groups, ...rest }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.get(`/v1/organizations/${orgId}/time-entries/aggregate`, {
                    ...filtersToQuery(rest),
                    fill_gaps_in_time_groups: toBooleanFilter(fill_gaps_in_time_groups),
                })
            );
        },
    });

    registerTool(server, {
        name: 'create_time_entry',
        title: 'Create time entry',
        description:
            'Create a time entry for a member. Omit `end` to start a running timer. Times must be UTC in YYYY-MM-DDTHH:MM:SSZ format. Note member_id is the organization membership UUID, not the user UUID — get it from list_members or get_my_user.',
        schema: {
            organization_id: organizationIdSchema,
            member_id: uuid.describe(
                'Organization membership UUID the entry belongs to (not the user UUID).'
            ),
            start: utcTimestamp.describe('Start time in UTC.'),
            end: utcTimestamp
                .nullable()
                .optional()
                .describe('End time in UTC. Omit or null to leave the timer running.'),
            billable: z.boolean().describe('Whether this entry is billable.'),
            description: z.string().max(5000).nullable().optional(),
            project_id: uuid.nullable().optional().describe('Required when task_id is given.'),
            task_id: uuid.nullable().optional(),
            tags: z.array(uuid).optional().describe('Tag UUIDs to attach.'),
            metadata: metadataSchema,
        },
        handler: async ({ organization_id, ...body }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.post(`/v1/organizations/${orgId}/time-entries`, compact(body)));
        },
    });

    registerTool(server, {
        name: 'update_time_entry',
        title: 'Update time entry',
        description:
            'Update a single time entry. Only the fields you pass are changed. Pass `end` to stop a running timer.',
        schema: {
            organization_id: organizationIdSchema,
            time_entry_id: uuid,
            member_id: uuid.optional(),
            start: utcTimestamp.optional(),
            end: utcTimestamp
                .nullable()
                .optional()
                .describe('Set to stop a running timer; null makes it run again.'),
            billable: z.boolean().optional(),
            description: z.string().max(5000).nullable().optional(),
            project_id: uuid.nullable().optional(),
            task_id: uuid.nullable().optional(),
            tags: z.array(uuid).optional().describe('Replaces the full tag list.'),
            metadata: metadataSchema,
        },
        handler: async ({ organization_id, time_entry_id, ...body }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.put(
                    `/v1/organizations/${orgId}/time-entries/${time_entry_id}`,
                    compact(body)
                )
            );
        },
    });

    registerTool(server, {
        name: 'update_time_entries_bulk',
        title: 'Bulk update time entries',
        description:
            'Apply the same changes to many time entries at once — e.g. reassign a batch to a project or flip them to billable.',
        schema: {
            organization_id: organizationIdSchema,
            ids: z.array(uuid).min(1).describe('Time entry UUIDs to update.'),
            changes: z
                .object({
                    member_id: uuid.optional(),
                    project_id: uuid.nullable().optional(),
                    task_id: uuid.nullable().optional(),
                    billable: z.boolean().optional(),
                    description: z.string().max(5000).nullable().optional(),
                    tags: z.array(uuid).nullable().optional(),
                    metadata: metadataSchema,
                })
                .describe('Fields to apply to every listed entry.'),
        },
        handler: async ({ organization_id, ids, changes }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.patch(`/v1/organizations/${orgId}/time-entries`, {
                    ids,
                    changes: compact(changes),
                })
            );
        },
    });

    registerTool(server, {
        name: 'delete_time_entry',
        title: 'Delete time entry',
        description: 'Permanently delete a single time entry.',
        destructive: true,
        schema: {
            organization_id: organizationIdSchema,
            time_entry_id: uuid,
        },
        handler: async ({ organization_id, time_entry_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.delete(`/v1/organizations/${orgId}/time-entries/${time_entry_id}`)
            );
        },
    });

    registerTool(server, {
        name: 'delete_time_entries_bulk',
        title: 'Bulk delete time entries',
        description:
            'Permanently delete several time entries at once. Confirm the list with the user before calling.',
        destructive: true,
        schema: {
            organization_id: organizationIdSchema,
            ids: z.array(uuid).min(1).describe('Time entry UUIDs to delete.'),
        },
        handler: async ({ organization_id, ids }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.delete(`/v1/organizations/${orgId}/time-entries`, {
                    ids,
                })
            );
        },
    });

    registerTool(server, {
        name: 'get_my_active_time_entry',
        title: 'Get my running timer',
        description: "Return the authenticated user's currently running time entry, if any.",
        readOnly: true,
        schema: {},
        handler: async () => json(await api.get('/v1/users/me/time-entries/active')),
    });

    registerTool(server, {
        name: 'list_my_time_entries',
        title: 'List my time entries',
        description: 'List time entries belonging to the authenticated user across organizations.',
        readOnly: true,
        schema: {},
        handler: async () => json(await api.get('/v1/users/me/time-entries')),
    });
}
