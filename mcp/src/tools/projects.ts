import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { api } from '../client.js';
import {
    billableRateSchema,
    compact,
    organizationIdSchema,
    resolveOrganizationId,
    uuid,
} from '../shared.js';
import { json, registerTool } from '../register.js';

const colorSchema = z
    .string()
    .regex(/^#[0-9a-fA-F]{6}$/, 'Must be a hex colour such as #ef5350')
    .describe('Hex colour used in the solidtime UI, e.g. #ef5350.');

export function registerProjectTools(server: McpServer): void {
    registerTool(server, {
        name: 'list_projects',
        title: 'List projects',
        description:
            'List projects in an organization, including their billable rate override, client and estimated time.',
        readOnly: true,
        schema: {
            organization_id: organizationIdSchema,
            archived: z
                .enum(['true', 'false', 'all'])
                .optional()
                .describe('Filter by archived state. Defaults to non-archived.'),
            page: z
                .number()
                .int()
                .min(1)
                .optional()
                .describe('Page number — results are paginated.'),
        },
        handler: async ({ organization_id, archived, page }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.get(`/v1/organizations/${orgId}/projects`, compact({ archived, page }))
            );
        },
    });

    registerTool(server, {
        name: 'get_project',
        title: 'Get project',
        description: 'Fetch a single project by UUID.',
        readOnly: true,
        schema: {
            organization_id: organizationIdSchema,
            project_id: uuid,
        },
        handler: async ({ organization_id, project_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.get(`/v1/organizations/${orgId}/projects/${project_id}`));
        },
    });

    registerTool(server, {
        name: 'create_project',
        title: 'Create project',
        description:
            'Create a project. Set billable_rate to override the organization default rate for all time on this project. Project names must be unique per client.',
        schema: {
            organization_id: organizationIdSchema,
            name: z.string().min(1).max(255),
            color: colorSchema,
            is_billable: z.boolean().describe('Whether new time entries default to billable.'),
            billable_rate: billableRateSchema,
            client_id: uuid
                .nullable()
                .optional()
                .describe('Client UUID, or null for an internal project.'),
            estimated_time: z
                .number()
                .int()
                .min(0)
                .nullable()
                .optional()
                .describe('Estimated project budget in SECONDS.'),
            is_public: z
                .boolean()
                .optional()
                .describe('Public projects are visible to every member, not just assigned ones.'),
        },
        handler: async ({ organization_id, ...body }) => {
            const orgId = resolveOrganizationId(organization_id);
            // client_id is `present` in the API rules — send it explicitly even when null.
            return json(
                await api.post(`/v1/organizations/${orgId}/projects`, {
                    client_id: null,
                    ...compact(body),
                })
            );
        },
    });

    registerTool(server, {
        name: 'update_project',
        title: 'Update project',
        description:
            'Update a project, including its billable rate override and archived state. name, color and is_billable are required by the API — pass the current values for fields you are not changing (get_project first).',
        schema: {
            organization_id: organizationIdSchema,
            project_id: uuid,
            name: z.string().min(1).max(255),
            color: colorSchema,
            is_billable: z.boolean(),
            billable_rate: billableRateSchema,
            client_id: uuid.nullable().optional(),
            estimated_time: z.number().int().min(0).nullable().optional(),
            is_public: z.boolean().optional(),
            is_archived: z.boolean().optional(),
        },
        handler: async ({ organization_id, project_id, ...body }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.put(`/v1/organizations/${orgId}/projects/${project_id}`, {
                    client_id: null,
                    ...compact(body),
                })
            );
        },
    });

    registerTool(server, {
        name: 'delete_project',
        title: 'Delete project',
        description:
            'Permanently delete a project. Fails if time entries or tasks still reference it — archive it via update_project instead when in doubt.',
        destructive: true,
        schema: {
            organization_id: organizationIdSchema,
            project_id: uuid,
        },
        handler: async ({ organization_id, project_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.delete(`/v1/organizations/${orgId}/projects/${project_id}`));
        },
    });

    registerTool(server, {
        name: 'list_project_members',
        title: 'List project members',
        description:
            'List members assigned to a project together with their per-project billable rate override.',
        readOnly: true,
        schema: {
            organization_id: organizationIdSchema,
            project_id: uuid,
        },
        handler: async ({ organization_id, project_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.get(`/v1/organizations/${orgId}/projects/${project_id}/project-members`)
            );
        },
    });

    registerTool(server, {
        name: 'add_project_member',
        title: 'Add member to project',
        description:
            'Assign an organization member to a project, optionally with a billable rate that overrides both the project and organization rate for that person.',
        schema: {
            organization_id: organizationIdSchema,
            project_id: uuid,
            member_id: uuid.describe('Organization membership UUID.'),
            billable_rate: billableRateSchema,
        },
        handler: async ({ organization_id, project_id, ...body }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.post(
                    `/v1/organizations/${orgId}/projects/${project_id}/project-members`,
                    compact(body)
                )
            );
        },
    });

    registerTool(server, {
        name: 'update_project_member',
        title: 'Update project member rate',
        description:
            'Update a project member assignment — in practice, their billable rate override. Takes the project_member UUID from list_project_members, not the member UUID.',
        schema: {
            organization_id: organizationIdSchema,
            project_member_id: uuid,
            billable_rate: billableRateSchema,
        },
        handler: async ({ organization_id, project_member_id, billable_rate }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.put(`/v1/organizations/${orgId}/project-members/${project_member_id}`, {
                    billable_rate: billable_rate ?? null,
                })
            );
        },
    });

    registerTool(server, {
        name: 'remove_project_member',
        title: 'Remove member from project',
        description: 'Remove a member assignment from a project.',
        destructive: true,
        schema: {
            organization_id: organizationIdSchema,
            project_member_id: uuid,
        },
        handler: async ({ organization_id, project_member_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.delete(`/v1/organizations/${orgId}/project-members/${project_member_id}`)
            );
        },
    });
}
