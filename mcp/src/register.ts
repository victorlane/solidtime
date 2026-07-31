import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { ZodRawShape, objectOutputType, ZodTypeAny } from 'zod';
import { SolidtimeApiError } from './client.js';

export interface ToolResult {
    content: { type: 'text'; text: string }[];
    isError?: boolean;
}

export function json(value: unknown): ToolResult {
    return {
        content: [{ type: 'text', text: JSON.stringify(value, null, 2) }],
    };
}

interface ToolDefinition<Schema extends ZodRawShape> {
    name: string;
    title: string;
    description: string;
    schema: Schema;
    /** Marks the tool as non-mutating so clients can auto-approve it. */
    readOnly?: boolean;
    /** Marks the tool as irreversible so clients prompt before running it. */
    destructive?: boolean;
    handler: (args: objectOutputType<Schema, ZodTypeAny>) => Promise<ToolResult>;
}

/**
 * Wraps every handler so an API failure comes back as tool content the agent can
 * read and correct, rather than a protocol-level exception that ends the turn.
 */
export function registerTool<Schema extends ZodRawShape>(
    server: McpServer,
    definition: ToolDefinition<Schema>
): void {
    server.registerTool(
        definition.name,
        {
            title: definition.title,
            description: definition.description,
            inputSchema: definition.schema,
            annotations: {
                readOnlyHint: definition.readOnly ?? false,
                destructiveHint: definition.destructive ?? false,
                idempotentHint: definition.readOnly ?? false,
                openWorldHint: true,
            },
        },
        (async (args: objectOutputType<Schema, ZodTypeAny>) => {
            try {
                return await definition.handler(args);
            } catch (error) {
                const message =
                    error instanceof SolidtimeApiError
                        ? error.message
                        : error instanceof Error
                          ? error.message
                          : String(error);
                return {
                    content: [{ type: 'text' as const, text: message }],
                    isError: true,
                };
            }
        }) as never
    );
}
