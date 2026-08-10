# Working in this repository

This is a fork of `solidtime-io/solidtime`. Upstream is merged in periodically, which means
upstream-only assumptions get pulled back in on a regular basis. The notes below are the ones
that keep costing time when they are forgotten.

## Upstream's private extensions must stay out

`extension-invoicing`, `extension-billing` and `extension-services` are private repositories
belonging to upstream. This fork has no access and no deploy keys, so any workflow step that
tries to clone one fails with `remote: Repository not found` and takes the whole build down.

Rules:

- Do **not** add `extensions/manifest.json` back, or any entry describing an upstream extension.
- Do **not** add checkout, `composer install`, `npm ci` or `php artisan module:enable` steps for
  `extensions/Invoicing`, `extensions/Billing` or `extensions/Services`.
- Do **not** reference `secrets.SSH_PRIVATE_KEY_INVOICING_EXTENSION`,
  `SSH_PRIVATE_KEY_BILLING_EXTENSION` or `SSH_PRIVATE_KEY_SERVICES_EXTENSION`.
- When an upstream merge reintroduces any of the above, remove it again as part of that merge
  rather than leaving the build red. Search for `Invoicing`, `Billing`, `Services`,
  `extension-` and `manifest.json` under `.github/workflows/`.

The extension mechanism itself still works: `extensions/extensions_autoload.php` scans the
directory and loads whatever is present, so a self-contained extension dropped in there is fine.
Only upstream's inaccessible ones are the problem.

## Only one image build exists here, and it is ours

`build-harbor.yml` produces the image this fork deploys, to
`harbor.bmlabs.eu/victorlane/solidtime`. It is the only build that publishes anything. Keep it
green.

Upstream's three publishing pipelines were deliberately deleted, because they push to registries
this fork has no credentials for and clone extensions it has no access to:

| Deleted | Published to |
| --- | --- |
| `build-private.yml` | `rg.fr-par.scw.cloud/solidtime` |
| `build-public.yml` | `ghcr.io` |
| `build-onpremise.yml` | `registry.on-premise.solidtime.io` |

`generate-api-docs.yml` had the same problem in one step: it deployed the generated spec to
upstream's Fastfront account using `FASTFRONT_API_KEY`. That step is gone. The workflow still
exports the OpenAPI document and uploads it as a build artifact, which is the part worth keeping,
because it proves the spec still generates.

Do not restore any of this, and do not try to fix it by inventing credentials. If an upstream
merge brings it back, remove it again.

Everything else in `.github/workflows/` is a quality gate — phpunit, phpstan, pint, playwright,
the npm jobs — and should stay green.

## Dependencies must resolve for PHP 8.3

CI and the production image both run PHP 8.3, while local machines may be newer. `composer.json`
pins `config.platform.php` to `8.3.33` so resolution matches where the code actually runs. Leave
that pin in place. Without it, running `composer require` on a newer local PHP silently locks
packages that 8.3 cannot install, and every CI job then dies at `composer install` before doing
any work.

## Static analysis

`phpstan.neon` is configured to reject the usual escape hatches. Do not add `@phpstan-ignore`
comments, baseline entries, `assert()` calls, inline `@var` overrides, or casts whose only
purpose is to silence an error. Fix the underlying cause. Where a third-party library carries a
wrong annotation, put a real typed boundary around it and say why in a comment.

## Migrations

Migration filenames carry a timestamp prefix and are ordered by filename. Check that a new
migration's prefix does not collide with one that already exists before committing it.
