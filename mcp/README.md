# solidtime MCP server

An [MCP](https://modelcontextprotocol.io) server that exposes the solidtime REST API to AI agents, so they can track time, manage projects and rates, and administer organizations.

It is a thin client over the public `/api/v1` surface — it does not touch the Laravel app and can point at solidtime cloud or any self-hosted instance.

## Setup

```bash
cd mcp
npm install
npm run build
```

### Get an API token

In solidtime: **Profile → API Tokens → Create**. Tokens are personal access tokens and carry the permissions of the user that created them — an agent using an employee's token cannot edit other members' time.

### Configure

| Variable | Required | Default | Purpose |
| --- | --- | --- | --- |
| `SOLIDTIME_API_TOKEN` | yes | — | Personal access token. |
| `SOLIDTIME_API_URL` | no | `https://app.solidtime.io/api` | Instance URL. The `/api` suffix is added if you omit it. |
| `SOLIDTIME_ORGANIZATION_ID` | no | — | Default organization, so agents can omit `organization_id` on every call. |
| `SOLIDTIME_TIMEOUT_MS` | no | `30000` | Per-request timeout. |

### Register with Claude Code

```bash
claude mcp add solidtime \
  --env SOLIDTIME_API_TOKEN=your-token-here \
  --env SOLIDTIME_API_URL=https://app.solidtime.io \
  -- node /absolute/path/to/solidtime/mcp/dist/index.js
```

Or in `.mcp.json` / `claude_desktop_config.json`:

```json
{
    "mcpServers": {
        "solidtime": {
            "command": "node",
            "args": ["/absolute/path/to/solidtime/mcp/dist/index.js"],
            "env": {
                "SOLIDTIME_API_TOKEN": "your-token-here",
                "SOLIDTIME_API_URL": "https://app.solidtime.io"
            }
        }
    }
}
```

## Tools

46 tools across eight groups. Deletes are annotated `destructiveHint` so MCP clients prompt before running them.

**Discovery** — `list_my_memberships`, `get_my_user`, `list_currencies`

**Organizations** — `get_organization`, `create_organization`, `update_organization` (currency, default billable rate, number/date/time formats), `delete_organization`

**Members** — `list_members`, `update_member` (role, rate override), `remove_member`, `make_member_placeholder`, `invite_placeholder_member`, `merge_member`, `list_invitations`, `invite_member`, `delete_invitation`

**Projects** — `list_projects`, `get_project`, `create_project`, `update_project`, `delete_project`

**Project members** — `list_project_members`, `add_project_member`, `update_project_member`, `remove_project_member` (per-person, per-project rate overrides)

**Tasks / Clients / Tags** — full CRUD on each

**Time entries** — `list_time_entries`, `aggregate_time_entries`, `create_time_entry`, `update_time_entry`, `update_time_entries_bulk`, `delete_time_entry`, `delete_time_entries_bulk`, `get_my_active_time_entry`, `list_my_time_entries`

## API conventions worth knowing

These trip up agents (and humans), so they are repeated in every relevant tool description:

- **`member_id` is a membership UUID, not a user UUID.** Resolve it with `list_members`.
- **Billable rates are integers in cents.** `8500` is 85.00/hour. `null` clears an override so the rate is inherited.
- **Rates cascade**: project member → project → organization member → organization.
- **Timestamps are UTC in exactly `YYYY-MM-DDTHH:MM:SSZ`.** The API rejects offsets and fractional seconds.
- **Estimated times are in seconds.**
- **A time entry with no `end` is a running timer.** Stop it by setting `end` via `update_time_entry`.
- `update_project`, `update_task` and `update_client` are full replacements — the API requires `name` (and for projects `color`/`is_billable`) on every call. Read the current record first.

## Notes

- Errors are returned as tool content with `isError: true`, including flattened Laravel validation messages (`field: reason`), so an agent can correct itself rather than failing the turn.
- The bundled `openapi.json` at the repo root is stale (4 endpoints); this server was written against `routes/api.php` and the `app/Http/Requests/V1` validation rules.
- Report and chart endpoints are not yet exposed — `aggregate_time_entries` covers most reporting needs.
