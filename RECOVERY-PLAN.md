# Recovery plan: reintroduce the unpushed 1.0.1-metadata work

**Status (2026-07-30):** production (`uren.bmlabs.eu`) runs `harbor.bmlabs.eu/victorlane/solidtime:1.0.1-metadata`.
That image is the **only surviving copy** of a large body of unpushed work — the
source tree it was built from is lost. Everything committed on `feat/mcp-server`
after `4d5a898a` (quick hour picker `9930130`, weekly billable targets `3365d0b`)
is safe in git but **not deployed**, because deploying it from this branch is what
wiped the unpushed work off production in the first place (rolled back in
hetzner-infra `4546321`).

## What only exists inside the 1.0.1-metadata image

~160 files differ from `main` (`f0de89b0`). Known features in the image tree:

- **Client & project metadata** — migration
  `2026_07_30_000000_add_metadata_columns_to_projects_and_clients_table.php`,
  `metadata` casts on `Client`/`Project`, metadata fields in the
  Client/Project store/update requests and resources, and the **client
  overview + metadata pane** UI in `Clients.vue`. The bmlabs-invoicing
  `stripe_account` routing (`internal/billing/routing.go`) and the bfiscal
  project form depend on the project-metadata API.
- **Time-entry breaks** — `app/Enums/TimeEntryType.php`, migrations
  `2026_07_11_..._add_type_to_time_entries_table.php` and
  `2026_07_13_..._add_breaks_enabled_to_organizations_table.php`, plus changes
  across the TimeEntry requests/resources/aggregation services.
- **Newer upstream baseline** — report/export/dashboard/import changes beyond
  the fork's `main`.
- **Build scaffolding** — a `.dockerignore`, an `extensions/` autoload stub
  (empty; no private extension code), and a modified `docker/prod/Dockerfile`.

The **database already has all of it**: the metadata migration, the breaks
migrations, and the (currently unused) weekly-target migrations all ran.
Recovery is purely a source/code problem; no DB work is needed.

## Recovery steps

1. **Extract the source from the image** (it was built with `COPY . .`, so the
   full tree is inside; there is no `.git` in it):

   ```sh
   docker pull --platform linux/amd64 harbor.bmlabs.eu/victorlane/solidtime:1.0.1-metadata
   docker create --platform linux/amd64 --name st101 harbor.bmlabs.eu/victorlane/solidtime:1.0.1-metadata
   docker export st101 | tar -x -C /tmp/st101 \
     --exclude 'var/www/html/vendor' --exclude 'var/www/html/node_modules' \
     --exclude 'var/www/html/public/build' --exclude 'var/www/html/storage' \
     'var/www/html'
   docker rm st101
   ```

2. **Create a recovery branch**: check out `main` (`f0de89b0`, the closest
   base), copy the extracted tree over it (excluding `.env`,
   `bootstrap/cache/*`, `public/build`), and commit as one
   `recovered: source of 1.0.1-metadata image` commit.
   Verify byte-for-byte: `diff -r` of the extracted tree against the branch
   must be empty apart from the excluded paths.

3. **Rebase the committed features on top**: cherry-pick `9930130` (quick hour
   picker) and `3365d0b` (weekly billable targets) onto the recovery branch.
   Expected conflicts: `resources/js/packages/api/src/openapi.json.client.ts`
   (both sides touched it — prefer regenerating, next step) and possibly the
   time-entry modals (the breaks feature changed them too).

4. **Regenerate the API client properly** instead of keeping hand-edits: run
   the app locally (`docker compose up` — note the recovered tree deleted
   `docker-compose.yml`; use the one from `main`), then
   `npm run zod:generate && npm run build:api`.

5. **Run the checks**: `npm run type-check`, `npx eslint resources/js`,
   `npm run test:unit`, `php artisan view:cache`, `php -l` on changed PHP.
   (State at rollback: type-check clean, eslint clean, all 90 unit tests
   passing for the committed features.)

6. **Build & ship `1.1.1-metadata`** — the exact ritual:

   ```sh
   composer install --no-dev --no-interaction --ignore-platform-req=php
   cp .env.production .env       # restore .env.production from main if the recovered tree lacks it
   npm ci && npm run build
   docker buildx build --platform linux/amd64 \
     -f docker/prod/Dockerfile --build-arg DOCKER_FILES_BASE_PATH=docker/prod/ \
     -t harbor.bmlabs.eu/victorlane/solidtime:1.1.1-metadata --push .
   ```

   Then in hetzner-infra: bump the three image tags in
   `solidtime/deployment.yaml`, commit, **push first**, then
   `kubectl -n argocd annotate application solidtime-stack argocd.argoproj.io/refresh=normal --overwrite`
   and wait for the rollout. (ArgoCD runs `selfHeal`: a manual `kubectl apply`
   that races ahead of its git cache gets silently reverted — this bit us.)

7. **Verify on the pod**: client overview/metadata pane opens; quick hour
   picker present; `php artisan migrate:status` clean;
   `php artisan member:send-weekly-billable-target-mails --dry-run` runs.

## Lessons / guardrails

- **Never build a production image from a tree without `git status` being
  clean and pushed.** The `-metadata` image only existed because the build ran
  on a dirty tree; two later "clean" builds erased it from production.
- Before shipping a new image over a running one, **diff the running image's
  source against the build context** (`docker export | tar -t`) when there is
  any doubt about provenance.
- Prefer CI-built images (the `build-*.yml` workflows) over laptop builds so
  the image ↔ commit mapping is always exact.

---

## Original asks these features came from (context, with status)

> Type-check clean (the only error is the pre-existing missing PHP vendor/
> ziggy stub), eslint clean, all 90 unit tests pass. The changes are
> uncommitted in ~/Personal/Projects/solidtime (branch feat/mcp-server) for
> you to review — and note the cluster runs image 1.0.1-metadata, so shipping
> it needs a commit + Harbor image build + tag bump in deployment.yaml.
> **SHIP IT**

Quick hour picker: committed (`9930130`), briefly live as `1.0.2-metadata`,
currently rolled back. Re-ship via the steps above.

> Turn on the fork's tracking-reminder emails — now that SMTP works, the
> "time tracker alert email" feature your fork already patched can actually
> reach you. Same-day tracking is the single biggest data-quality lever for
> uurtje-factuurtje billing.
> **Add weekly billable hours to employees (members), let me set billable
> hours on the employee and for an employee assigned to a project. then send
> these reminders.**

Weekly billable targets: committed (`3365d0b`) — member + project-member
`weekly_billable_target` (seconds), edit UIs, hourly gated reminder command
(`member:send-weekly-billable-target-mails`, flag
`SCHEDULING_TASK_MEMBER_SEND_WEEKLY_BILLABLE_TARGET_MAILS`), Resend SMTP is
live (`solidtime@brinkhorst.consulting`). DB migrations already ran in
production. Currently rolled back with the picker; re-ship via the steps
above. The upstream still-running alert emails work on 1.0.1 already, since
SMTP is configured at the cluster level.

> Tag every project with stripe_account metadata now, before the BC account
> exists — the routing filter you built treats untagged projects as
> default-account, so pre-tagging makes the eventual split a non-event. Worth
> adding to the bfiscal project form (it writes ProjectWrite already).
> **add it**

Done in bmlabs-invoicing (project form Account row, `ProjectWrite.Metadata`,
routing filter) and it works against the **1.0.1-metadata** API, which is
another reason production must keep the metadata feature when re-shipping.
