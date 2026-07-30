#!/usr/bin/env node
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { registerTimeEntryTools } from './tools/time-entries.js';
import { registerProjectTools } from './tools/projects.js';
import {
    registerClientTools,
    registerTagTools,
    registerTaskTools,
} from './tools/taxonomy.js';
import {
    registerMemberTools,
    registerOrganizationTools,
    registerReferenceTools,
} from './tools/organizations.js';

async function main(): Promise<void> {
    const server = new McpServer(
        { name: 'solidtime', version: '0.1.0' },
        {
            instructions: [
                'Tools for the solidtime time-tracking API.',
                '',
                'Conventions that are easy to get wrong:',
                '- member_id is an organization MEMBERSHIP uuid, not a user uuid. Resolve it with list_members.',
                '- All billable rates are integers in CENTS (8500 = 85.00/hour). null clears an override.',
                '- Rates cascade: project member > project > organization member > organization.',
                '- All timestamps are UTC in the exact format YYYY-MM-DDTHH:MM:SSZ.',
                '- Estimated times are in SECONDS.',
                '- A time entry with no end is a running timer.',
                '',
                'Start with list_my_memberships to discover organization and member ids,',
                'and prefer aggregate_time_entries over listing entries for reporting questions.',
            ].join('\n'),
        }
    );

    registerOrganizationTools(server);
    registerMemberTools(server);
    registerProjectTools(server);
    registerTaskTools(server);
    registerClientTools(server);
    registerTagTools(server);
    registerTimeEntryTools(server);
    registerReferenceTools(server);

    const transport = new StdioServerTransport();
    await server.connect(transport);
}

main().catch((error: unknown) => {
    // stdout is the MCP channel — diagnostics must go to stderr.
    console.error(
        `solidtime MCP server failed to start: ${
            error instanceof Error ? error.message : String(error)
        }`
    );
    process.exit(1);
});
