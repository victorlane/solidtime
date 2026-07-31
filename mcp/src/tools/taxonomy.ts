import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { api } from '../client.js';
import { compact, organizationIdSchema, resolveOrganizationId, uuid } from '../shared.js';
import { json, registerTool } from '../register.js';

const page = z.number().int().min(1).optional().describe('Page number — results are paginated.');

export function registerTaskTools(server: McpServer): void {
    registerTool(server, {
        name: 'list_tasks',
        title: 'List tasks',
        description:
            'List tasks in an organization, optionally scoped to one project and filtered by done state.',
        readOnly: true,
        schema: {
            organization_id: organizationIdSchema,
            project_id: uuid.optional(),
            done: z
                .enum(['true', 'false', 'all'])
                .optional()
                .describe('Filter by completion state. Defaults to all.'),
            page,
        },
        handler: async ({ organization_id, ...query }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.get(`/v1/organizations/${orgId}/tasks`, compact(query)));
        },
    });

    registerTool(server, {
        name: 'create_task',
        title: 'Create task',
        description:
            'Create a task inside a project. Task names must be unique within their project.',
        schema: {
            organization_id: organizationIdSchema,
            name: z.string().min(1).max(255),
            project_id: uuid,
            estimated_time: z
                .number()
                .int()
                .min(0)
                .nullable()
                .optional()
                .describe('Estimated time budget in SECONDS.'),
        },
        handler: async ({ organization_id, ...body }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.post(`/v1/organizations/${orgId}/tasks`, compact(body)));
        },
    });

    registerTool(server, {
        name: 'update_task',
        title: 'Update task',
        description:
            'Update a task name, done state or time estimate. name is required by the API — pass the current name if you are only marking it done.',
        schema: {
            organization_id: organizationIdSchema,
            task_id: uuid,
            name: z.string().min(1).max(255),
            is_done: z.boolean().optional(),
            estimated_time: z.number().int().min(0).nullable().optional(),
        },
        handler: async ({ organization_id, task_id, ...body }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.put(`/v1/organizations/${orgId}/tasks/${task_id}`, compact(body))
            );
        },
    });

    registerTool(server, {
        name: 'delete_task',
        title: 'Delete task',
        description: 'Permanently delete a task.',
        destructive: true,
        schema: {
            organization_id: organizationIdSchema,
            task_id: uuid,
        },
        handler: async ({ organization_id, task_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.delete(`/v1/organizations/${orgId}/tasks/${task_id}`));
        },
    });
}

export function registerClientTools(server: McpServer): void {
    registerTool(server, {
        name: 'list_clients',
        title: 'List clients',
        description: 'List clients in an organization.',
        readOnly: true,
        schema: {
            organization_id: organizationIdSchema,
            archived: z
                .enum(['true', 'false', 'all'])
                .optional()
                .describe('Filter by archived state.'),
            page,
        },
        handler: async ({ organization_id, ...query }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.get(`/v1/organizations/${orgId}/clients`, compact(query)));
        },
    });

    registerTool(server, {
        name: 'create_client',
        title: 'Create client',
        description: 'Create a client. Client names must be unique within the organization.',
        schema: {
            organization_id: organizationIdSchema,
            name: z.string().min(1).max(255),
        },
        handler: async ({ organization_id, name }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.post(`/v1/organizations/${orgId}/clients`, { name }));
        },
    });

    registerTool(server, {
        name: 'update_client',
        title: 'Update client',
        description: 'Rename a client or change its archived state. name is required by the API.',
        schema: {
            organization_id: organizationIdSchema,
            client_id: uuid,
            name: z.string().min(1).max(255),
            is_archived: z.boolean().optional(),
        },
        handler: async ({ organization_id, client_id, ...body }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.put(`/v1/organizations/${orgId}/clients/${client_id}`, compact(body))
            );
        },
    });

    registerTool(server, {
        name: 'delete_client',
        title: 'Delete client',
        description:
            'Permanently delete a client. Fails while projects still reference it — archive instead when in doubt.',
        destructive: true,
        schema: {
            organization_id: organizationIdSchema,
            client_id: uuid,
        },
        handler: async ({ organization_id, client_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.delete(`/v1/organizations/${orgId}/clients/${client_id}`));
        },
    });
}

export function registerTagTools(server: McpServer): void {
    registerTool(server, {
        name: 'list_tags',
        title: 'List tags',
        description:
            'List tags in an organization. Use this to resolve tag names to the UUIDs that time entry tools expect.',
        readOnly: true,
        schema: { organization_id: organizationIdSchema },
        handler: async ({ organization_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.get(`/v1/organizations/${orgId}/tags`));
        },
    });

    registerTool(server, {
        name: 'create_tag',
        title: 'Create tag',
        description: 'Create a tag. Tag names must be unique within the organization.',
        schema: {
            organization_id: organizationIdSchema,
            name: z.string().min(1).max(255),
        },
        handler: async ({ organization_id, name }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.post(`/v1/organizations/${orgId}/tags`, { name }));
        },
    });

    registerTool(server, {
        name: 'update_tag',
        title: 'Update tag',
        description: 'Rename a tag.',
        schema: {
            organization_id: organizationIdSchema,
            tag_id: uuid,
            name: z.string().min(1).max(255),
        },
        handler: async ({ organization_id, tag_id, name }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.put(`/v1/organizations/${orgId}/tags/${tag_id}`, {
                    name,
                })
            );
        },
    });

    registerTool(server, {
        name: 'delete_tag',
        title: 'Delete tag',
        description: 'Permanently delete a tag and remove it from all time entries.',
        destructive: true,
        schema: {
            organization_id: organizationIdSchema,
            tag_id: uuid,
        },
        handler: async ({ organization_id, tag_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.delete(`/v1/organizations/${orgId}/tags/${tag_id}`));
        },
    });
}
