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

/** Roles assignable through the API — owner and placeholder are managed by solidtime itself. */
const assignableRole = z.enum(['admin', 'manager', 'employee']);

export function registerOrganizationTools(server: McpServer): void {
    registerTool(server, {
        name: 'list_my_memberships',
        title: 'List my organizations',
        description:
            'List the organizations the authenticated user belongs to, with the membership UUID and role for each. Start here to discover organization_id and your own member_id.',
        readOnly: true,
        schema: {},
        handler: async () => json(await api.get('/v1/users/me/memberships')),
    });

    registerTool(server, {
        name: 'get_my_user',
        title: 'Get current user',
        description: 'Return the authenticated user, including their current organization.',
        readOnly: true,
        schema: {},
        handler: async () => json(await api.get('/v1/users/me')),
    });

    registerTool(server, {
        name: 'get_organization',
        title: 'Get organization',
        description:
            'Fetch an organization with its currency, default billable rate and formatting settings.',
        readOnly: true,
        schema: { organization_id: organizationIdSchema },
        handler: async ({ organization_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.get(`/v1/organizations/${orgId}`));
        },
    });

    registerTool(server, {
        name: 'create_organization',
        title: 'Create organization',
        description:
            'Create a new organization owned by the authenticated user. Only the name is set here — use update_organization afterwards for currency and default rate.',
        schema: {
            name: z.string().min(1).max(255),
        },
        handler: async ({ name }) => json(await api.post('/v1/organizations', { name })),
    });

    registerTool(server, {
        name: 'update_organization',
        title: 'Update organization',
        description:
            'Update organization settings: name, currency, the default billable rate applied when no project or member override exists, and display formats.',
        schema: {
            organization_id: organizationIdSchema,
            name: z.string().min(1).max(255).optional(),
            currency: z
                .string()
                .length(3)
                .optional()
                .describe('ISO 4217 currency code, e.g. EUR. Use list_currencies.'),
            billable_rate: billableRateSchema,
            employees_can_see_billable_rates: z.boolean().optional(),
            employees_can_manage_tasks: z.boolean().optional(),
            prevent_overlapping_time_entries: z.boolean().optional(),
            number_format: z
                .enum([
                    'point-comma',
                    'comma-point',
                    'space-comma',
                    'space-point',
                    'apostrophe-point',
                ])
                .optional(),
            currency_format: z
                .enum([
                    'iso-code-before-with-space',
                    'iso-code-after-with-space',
                    'symbol-before',
                    'symbol-after',
                    'symbol-before-with-space',
                    'symbol-after-with-space',
                ])
                .optional(),
            date_format: z
                .enum([
                    'point-separated-d-m-yyyy',
                    'slash-separated-mm-dd-yyyy',
                    'slash-separated-dd-mm-yyyy',
                    'hyphen-separated-dd-mm-yyyy',
                    'hyphen-separated-mm-dd-yyyy',
                    'hyphen-separated-yyyy-mm-dd',
                ])
                .optional(),
            interval_format: z
                .enum([
                    'decimal',
                    'hours-minutes',
                    'hours-minutes-colon-separated',
                    'hours-minutes-seconds-colon-separated',
                ])
                .optional(),
            time_format: z.enum(['12-hours', '24-hours']).optional(),
        },
        handler: async ({ organization_id, ...body }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.put(`/v1/organizations/${orgId}`, compact(body)));
        },
    });

    registerTool(server, {
        name: 'delete_organization',
        title: 'Delete organization',
        description:
            'Permanently delete an organization and everything in it. Irreversible — always confirm with the user first.',
        destructive: true,
        schema: { organization_id: organizationIdSchema },
        handler: async ({ organization_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.delete(`/v1/organizations/${orgId}`));
        },
    });
}

export function registerMemberTools(server: McpServer): void {
    registerTool(server, {
        name: 'list_members',
        title: 'List members',
        description:
            'List organization members with their role and billable rate override. The `id` returned here is the member_id that time entry tools require.',
        readOnly: true,
        schema: {
            organization_id: organizationIdSchema,
            page: z.number().int().min(1).optional(),
        },
        handler: async ({ organization_id, page }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.get(`/v1/organizations/${orgId}/members`, compact({ page })));
        },
    });

    registerTool(server, {
        name: 'update_member',
        title: 'Update member',
        description: "Change a member's role or their organization-level billable rate override.",
        schema: {
            organization_id: organizationIdSchema,
            member_id: uuid,
            role: assignableRole.optional(),
            billable_rate: billableRateSchema,
        },
        handler: async ({ organization_id, member_id, ...body }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.put(`/v1/organizations/${orgId}/members/${member_id}`, compact(body))
            );
        },
    });

    registerTool(server, {
        name: 'remove_member',
        title: 'Remove member',
        description:
            'Remove a member from the organization. Their time entries are deleted — consider make_member_placeholder to keep the history.',
        destructive: true,
        schema: {
            organization_id: organizationIdSchema,
            member_id: uuid,
        },
        handler: async ({ organization_id, member_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.delete(`/v1/organizations/${orgId}/members/${member_id}`));
        },
    });

    registerTool(server, {
        name: 'make_member_placeholder',
        title: 'Deactivate member',
        description:
            'Convert a member into a placeholder: they lose access but their tracked time is preserved for reporting.',
        schema: {
            organization_id: organizationIdSchema,
            member_id: uuid,
        },
        handler: async ({ organization_id, member_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.post(`/v1/organizations/${orgId}/members/${member_id}/make-placeholder`)
            );
        },
    });

    registerTool(server, {
        name: 'invite_placeholder_member',
        title: 'Invite placeholder',
        description:
            'Send an invitation to a placeholder member so they can claim their account and existing time entries.',
        schema: {
            organization_id: organizationIdSchema,
            member_id: uuid,
        },
        handler: async ({ organization_id, member_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.post(`/v1/organizations/${orgId}/members/${member_id}/invite-placeholder`)
            );
        },
    });

    registerTool(server, {
        name: 'merge_member',
        title: 'Merge members',
        description:
            'Merge one member into another, moving all their time entries and project assignments. Irreversible.',
        destructive: true,
        schema: {
            organization_id: organizationIdSchema,
            member_id: uuid.describe('Member to merge FROM — this one disappears.'),
            member_id_to_merge_into: uuid.describe(
                'Member to merge INTO — this one keeps everything.'
            ),
        },
        handler: async ({ organization_id, member_id, member_id_to_merge_into }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.post(`/v1/organizations/${orgId}/member/${member_id}/merge-into`, {
                    member_id: member_id_to_merge_into,
                })
            );
        },
    });

    registerTool(server, {
        name: 'list_invitations',
        title: 'List invitations',
        description: 'List pending invitations for an organization.',
        readOnly: true,
        schema: {
            organization_id: organizationIdSchema,
            page: z.number().int().min(1).optional(),
        },
        handler: async ({ organization_id, page }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(await api.get(`/v1/organizations/${orgId}/invitations`, compact({ page })));
        },
    });

    registerTool(server, {
        name: 'invite_member',
        title: 'Invite member',
        description:
            'Invite someone to the organization by email. This sends them a real email — confirm the address with the user first.',
        schema: {
            organization_id: organizationIdSchema,
            email: z.string().email(),
            role: assignableRole,
        },
        handler: async ({ organization_id, email, role }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.post(`/v1/organizations/${orgId}/invitations`, {
                    email,
                    role,
                })
            );
        },
    });

    registerTool(server, {
        name: 'delete_invitation',
        title: 'Revoke invitation',
        description: 'Revoke a pending invitation.',
        destructive: true,
        schema: {
            organization_id: organizationIdSchema,
            invitation_id: uuid,
        },
        handler: async ({ organization_id, invitation_id }) => {
            const orgId = resolveOrganizationId(organization_id);
            return json(
                await api.delete(`/v1/organizations/${orgId}/invitations/${invitation_id}`)
            );
        },
    });
}

export function registerReferenceTools(server: McpServer): void {
    registerTool(server, {
        name: 'list_currencies',
        title: 'List currencies',
        description: 'List supported ISO 4217 currency codes for update_organization.',
        readOnly: true,
        schema: {},
        handler: async () => json(await api.get('/v1/currencies')),
    });
}
