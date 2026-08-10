# Working in this repository

This is a fork of `solidtime-io/solidtime`. Upstream is merged in periodically, which means
upstream-only assumptions get pulled back in on a regular basis. The notes below are the ones
that keep costing time when they are forgotten.

## The invoicing extension must stay out

`solidtime-io/extension-invoicing` is a private repository belonging to upstream. This fork has
no access to it and no deploy key for it, so any workflow step that tries to clone it fails with
`remote: Repository not found` and takes the whole build down with it.

Rules:

- Do **not** add an `Invoicing` entry to `extensions/manifest.json`.
- Do **not** add checkout, `composer install`, `npm ci` or `php artisan module:enable` steps for
  `extensions/Invoicing` to any workflow.
- Do **not** reference `secrets.SSH_PRIVATE_KEY_INVOICING_EXTENSION`.
- When an upstream merge reintroduces any of the above, remove it again as part of that merge
  rather than leaving the build red. Search for `Invoicing`, `invoicing` and
  `extension-invoicing` across `.github/workflows/` and `extensions/manifest.json`.

The same access problem applies to `extension-billing` and `extension-services`, which are still
referenced by `build-private.yml`. Those are left in place deliberately; see below.

## Which builds actually matter here

`build-harbor.yml` is the one that produces the image this fork deploys, to
`harbor.bmlabs.eu/victorlane/solidtime`. Keep it green.

`build-public.yml`, `build-private.yml` and `build-onpremise.yml` are upstream's publishing
pipelines. They depend on registry credentials and extension deploy keys that only upstream has,
so they fail in this fork for reasons unrelated to the code. Do not interpret their failure as a
regression, and do not try to fix them by inventing credentials.

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
