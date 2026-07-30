# Recovery of the unpushed 1.0.1-metadata work — COMPLETED 2026-07-30

The source tree behind `harbor.bmlabs.eu/victorlane/solidtime:1.0.1-metadata`
was lost; the deployed image was its only copy. It has been recovered into git
on branch **`recover/1.0.1-metadata`** and re-shipped as **`1.1.1-metadata`**.

## What was recovered and how

`docker export` of the running image's `/var/www/html` (the image is built with
`COPY . .`, so it carries the full source; it has no `.git`), committed on top
of `main` (`f0de89b0`) as `553b7a86 recovered: source of the 1.0.1-metadata image`.

Verified **byte-for-byte**: `diff -r` between the extracted tree and the
committed branch reports zero differing files. The only extra files on the
branch are ones the image cannot carry — everything matched by the image's
`.dockerignore` (`tests/`, `docs/`, `e2e/`, `.github/`, `docker-compose.yml`,
tool configs), plus the `.env.*` templates and `storage/` placeholders, all
kept from `main`.

Recovered features:

- **Client & project metadata** — migration
  `2026_07_30_000000_add_metadata_columns_to_projects_and_clients_table.php`,
  `metadata` casts/fields through the Client & Project models, requests and
  resources, and `resources/js/Components/Common/Client/ClientDetailPane.vue`
  (the client overview + metadata pane). bmlabs-invoicing's `stripe_account`
  project routing depends on this API.
- **Time-entry breaks** — `app/Enums/TimeEntryType.php`, migrations
  `2026_07_11_000001_add_type_to_time_entries_table.php` and
  `2026_07_13_000001_add_breaks_enabled_to_organizations_table.php`, the
  break modals/labels/placement helpers and their unit tests, plus the
  `TimeRangeFields` extraction and `TimeTrackerProjectControls`.
- **Newer upstream baseline** — report/export/dashboard/import changes.

Then cherry-picked on top, in order:

| Commit | Contents |
|---|---|
| `13fd996f` | MCP server (`mcp/`) — was only on `feat/mcp-server`, never in the image |
| `c9d9af4a` | Quick hour picker |
| `1183eca0` | Weekly billable-hours targets + reminder mails |

**Conflict resolved:** the picker commit and the recovered tree both rewrote
`TimeEntryCreateModal.vue`. The recovered `TimeRangeFields` extraction won, and
the picker moved *into* `TimeRangeFields.vue`, so every surface using that
component gets it. The now-dead `setQuickDuration` was dropped from the create
modal.

## Verification before shipping

- `npm run type-check` — clean (no errors at all; the old ziggy stub error is
  gone now that `vendor/` is installed).
- `npx eslint resources/js` — 0 errors (83 pre-existing warnings).
- `npm run test:unit` — **185 passed** (13 files; the recovered tree brought
  its own break-placement and time-tracker tests on top of the previous 90).
- `php -l` on all 65 changed PHP files — clean.
- `php artisan view:cache` — Blade templates compile.
- All five migrations on this branch were **already applied** in the production
  database, so the deploy is a no-op for the schema.

**Deviation from the original plan:** step 4 (regenerate the API client via
`docker compose up` + `npm run zod:generate`) was skipped. The cherry-pick
merged the hand-written schema edits onto the recovered client cleanly and both
feature sets are present and consistent (`metadata` on Client/Project,
`weekly_billable_target` on Member/ProjectMember), and `vue-tsc` is clean.
Regenerate from a running app the next time the API surface changes.

## Guardrails (the lesson that caused this)

- **Never build a production image from a tree that is not committed and
  pushed.** The `-metadata` image existed only because a build ran on a dirty
  tree; two later "clean" builds from `feat/mcp-server` erased that work from
  production before it was noticed.
- If an image's provenance is at all unclear, diff its source against your
  build context (`docker export | tar -t`) before shipping over it.
- **Push first, then let ArgoCD converge.** `solidtime-stack` runs with
  `selfHeal: true`: a manual `kubectl apply` that races ahead of ArgoCD's git
  cache is silently reverted. After pushing, force convergence with
  `kubectl -n argocd annotate application solidtime-stack argocd.argoproj.io/refresh=normal --overwrite`.
- Prefer the CI `build-*.yml` workflows over laptop builds so image ↔ commit
  mapping is always exact. Note laptop builds cannot include the private
  `extension-{billing,services,invoicing}` repos; the deployed image has never
  contained them (`extensions/` holds only the autoload stub and manifest).

## Follow-ups

- Fold `recover/1.0.1-metadata` into `main` (or make it the new default
  branch) and retire `feat/mcp-server`, whose only unique commit is now the
  superseded copy of this document.
- Set weekly billable targets on the members who need them; the reminder
  command (`member:send-weekly-billable-target-mails`, hourly, flag
  `SCHEDULING_TASK_MEMBER_SEND_WEEKLY_BILLABLE_TARGET_MAILS`) mails through
  Resend SMTP as `solidtime@brinkhorst.consulting`.
- Tag existing projects with `stripe_account` metadata from the bfiscal
  Customers screen before the Brinkhorst Consulting Stripe account exists.
