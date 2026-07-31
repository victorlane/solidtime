import { z } from 'zod';
import { config } from './config.js';

/** RFC3339 in UTC with a literal Z — the only format the solidtime API accepts. */
export const utcTimestamp = z
    .string()
    .regex(
        /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/,
        'Must be UTC in the format YYYY-MM-DDTHH:MM:SSZ, e.g. 2026-07-21T14:58:59Z'
    );

export const uuid = z.string().uuid();

export const organizationIdSchema = uuid
    .optional()
    .describe(
        'Organization UUID. Optional when SOLIDTIME_ORGANIZATION_ID is configured; otherwise required. Use list_my_memberships to discover it.'
    );

/**
 * Money in solidtime is always minor units (cents), never a decimal.
 * Say so on every rate field so agents do not send 85 meaning "85 euro".
 */
export const billableRateSchema = z
    .number()
    .int()
    .min(0)
    .max(2147483647)
    .nullable()
    .optional()
    .describe(
        'Billable rate in CENTS of the organization currency (e.g. 8500 = 85.00 per hour). Null clears the override so the rate is inherited.'
    );

/**
 * Free-form key-value pairs for linking a record to an external system.
 * Replaces the whole object on write, so always send every key you want to keep.
 */
export const metadataSchema = z
    .record(z.string().max(500))
    .nullable()
    .optional()
    .describe(
        'Custom metadata as key-value string pairs, f.e. {"external_id": "12345"}. Replaces all existing metadata, so send the full object; null clears it and omitting it keeps it unchanged. Max. 50 keys, values max. 500 characters.'
    );

export function resolveOrganizationId(provided?: string): string {
    const id = provided ?? config.defaultOrganizationId;
    if (!id) {
        throw new Error(
            'No organization_id given and SOLIDTIME_ORGANIZATION_ID is not set. Call list_my_memberships to find the organization UUID.'
        );
    }
    return id;
}

/** Drop undefined keys so a PATCH-style update never nulls a field by accident. */
export function compact<T extends Record<string, unknown>>(input: T): Partial<T> {
    return Object.fromEntries(
        Object.entries(input).filter(([, value]) => value !== undefined)
    ) as Partial<T>;
}

export function toBooleanFilter(value: boolean | undefined): string | undefined {
    return value === undefined ? undefined : value ? 'true' : 'false';
}
