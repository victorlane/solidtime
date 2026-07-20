function requireEnv(name: string): string {
    const value = process.env[name];
    if (!value || value.trim() === '') {
        throw new Error(
            `Missing required environment variable ${name}. See mcp/README.md for setup.`
        );
    }
    return value.trim();
}

/**
 * Accepts either the API root ("https://app.solidtime.io/api") or the bare host
 * ("https://app.solidtime.io") and normalises to the API root without a trailing slash.
 */
function normaliseBaseUrl(raw: string): string {
    const trimmed = raw.trim().replace(/\/+$/, '');
    return trimmed.endsWith('/api') ? trimmed : `${trimmed}/api`;
}

const timeoutRaw = process.env.SOLIDTIME_TIMEOUT_MS;
const parsedTimeout = timeoutRaw ? Number.parseInt(timeoutRaw, 10) : NaN;

export const config = {
    baseUrl: normaliseBaseUrl(
        process.env.SOLIDTIME_API_URL ?? 'https://app.solidtime.io/api'
    ),
    apiToken: requireEnv('SOLIDTIME_API_TOKEN'),
    /** Optional default so agents can omit organization_id on every call. */
    defaultOrganizationId:
        process.env.SOLIDTIME_ORGANIZATION_ID?.trim() || undefined,
    timeoutMs: Number.isFinite(parsedTimeout) ? parsedTimeout : 30_000,
};
