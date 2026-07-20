import { config } from './config.js';

export class SolidtimeApiError extends Error {
    constructor(
        readonly status: number,
        readonly method: string,
        readonly path: string,
        readonly body: unknown
    ) {
        super(
            `solidtime API ${method} ${path} failed with ${status}: ${formatErrorBody(body)}`
        );
        this.name = 'SolidtimeApiError';
    }
}

/**
 * Laravel validation failures come back as { message, errors: { field: [msg] } }.
 * Flatten them so the agent sees which field it got wrong instead of "[object Object]".
 */
function formatErrorBody(body: unknown): string {
    if (typeof body === 'string') {
        return body;
    }
    if (body && typeof body === 'object') {
        const record = body as Record<string, unknown>;
        const parts: string[] = [];
        if (typeof record.message === 'string') {
            parts.push(record.message);
        }
        if (record.errors && typeof record.errors === 'object') {
            for (const [field, messages] of Object.entries(
                record.errors as Record<string, unknown>
            )) {
                const text = Array.isArray(messages)
                    ? messages.join(', ')
                    : String(messages);
                parts.push(`${field}: ${text}`);
            }
        }
        if (parts.length > 0) {
            return parts.join(' | ');
        }
    }
    return JSON.stringify(body);
}

type Query = Record<
    string,
    string | number | boolean | string[] | undefined | null
>;

function buildQuery(query: Query | undefined): string {
    if (!query) {
        return '';
    }
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query)) {
        if (value === undefined || value === null) {
            continue;
        }
        if (Array.isArray(value)) {
            // Laravel expects repeated `key[]` entries for array filters.
            for (const item of value) {
                params.append(`${key}[]`, String(item));
            }
        } else {
            params.append(key, String(value));
        }
    }
    const serialized = params.toString();
    return serialized ? `?${serialized}` : '';
}

async function request<T>(
    method: string,
    path: string,
    options: { query?: Query; body?: unknown } = {}
): Promise<T> {
    const url = `${config.baseUrl}${path}${buildQuery(options.query)}`;

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), config.timeoutMs);

    let response: Response;
    try {
        response = await fetch(url, {
            method,
            headers: {
                Authorization: `Bearer ${config.apiToken}`,
                Accept: 'application/json',
                ...(options.body !== undefined
                    ? { 'Content-Type': 'application/json' }
                    : {}),
            },
            body:
                options.body !== undefined
                    ? JSON.stringify(options.body)
                    : undefined,
            signal: controller.signal,
        });
    } catch (error) {
        if (error instanceof Error && error.name === 'AbortError') {
            throw new Error(
                `solidtime API ${method} ${path} timed out after ${config.timeoutMs}ms`
            );
        }
        throw error;
    } finally {
        clearTimeout(timeout);
    }

    if (response.status === 204) {
        return { success: true } as T;
    }

    const text = await response.text();
    let payload: unknown = text;
    if (text.length > 0) {
        try {
            payload = JSON.parse(text);
        } catch {
            // Keep the raw text — an HTML error page is more useful than a parse failure.
        }
    }

    if (!response.ok) {
        throw new SolidtimeApiError(response.status, method, path, payload);
    }

    return (text.length > 0 ? payload : { success: true }) as T;
}

export const api = {
    get: <T>(path: string, query?: Query) =>
        request<T>('GET', path, { query }),
    post: <T>(path: string, body?: unknown) =>
        request<T>('POST', path, { body }),
    put: <T>(path: string, body?: unknown) => request<T>('PUT', path, { body }),
    patch: <T>(path: string, body?: unknown) =>
        request<T>('PATCH', path, { body }),
    delete: <T>(path: string, query?: Query) =>
        request<T>('DELETE', path, { query }),
};
