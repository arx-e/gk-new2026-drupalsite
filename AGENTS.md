# AGENTS.md

## Project Overview
This is a Drupal 11 site built on `drupal/recommended-project` composer template, managed via Composer with a relocated document root (`web/`).

## Version Control
- Git repo, remote `origin` → `github.com/arx-e/gk-new2026-drupalsite`, default branch `main`.
- `web/modules/custom/gk_application_setup` and `gk_application_respond` previously had their own nested `.git` dirs (separate repos: `arx-e/gk_application_setup`, `arx-e/gk_application_respond`). They are now fully flattened into this repo (gitlinks removed, files tracked normally) — do not re-add `.git` inside those directories or they'll become broken submodules.
- **Secrets live in `web/sites/default/settings.local.php` (gitignored, not in the repo)**: DB credentials and `$settings['hash_salt']`. This file must exist for the site to bootstrap (included at the bottom of `settings.php`). If missing (e.g. fresh clone), the site will not connect to the DB — get the file from a secrets store, it is NOT reconstructable from git history.
- `.gitignore` excludes: `settings*.php` (except `default.settings.php`), `services*.yml` (except `default.services.yml`), `vendor/`, `web/core`, contrib modules/themes, `web/themes/custom/*/node_modules/` and `/build/` (npm-rebuildable), `web/sites/*/files`.
- Theme `build/` output is intentionally NOT committed — must run `npm run build` after clone/deploy for CSS/JS to exist (theme is otherwise broken).

## Structure
- `composer.json` / `composer.lock` — dependency management (Composer, not manual `web/` edits for contrib)
- `web/` — Drupal document root
  - `web/core` — Drupal core (do not edit)
  - `web/modules/contrib` — contributed modules (managed via Composer)
  - `web/modules/custom/` — custom modules:
    - `gk_application_respond` — has controllers/services (`src/`), routing, libraries, JS/CSS, templates
    - `gk_application_setup` — permissions, menu links, routing (README is still the default Drupal scaffold, not filled in)
    - `gk_migrations` — custom migration definitions (`migrations/`)
  - `web/themes/custom/gkradix` — custom theme, a Radix (Bootstrap 5) subtheme
    - Uses Node/npm build tooling: `build.mjs`, `build-dev.mjs`, `watch.mjs`, Biome for lint/format, Stylelint for SCSS
    - `package.json` scripts: `npm run dev`, `npm run watch`, `npm run build`, `npm run biome:lint`, `npm run biome:format`, `npm run stylint`
  - `web/sites/default` — site settings (settings.php, services.yml, etc.)
- `config/sync/` — exported Drupal configuration (458+ YAML files); this is the source of truth for config, sync via `drush config:import` / `drush config:export`
- `recipes/` — Drupal recipes directory (currently empty besides README)

## Key Modules/Dependencies (composer.json)
Notable contrib modules in use: ECA (event-condition-action), ECK (entity construction kit), Paragraphs, Field Group, Inline Entity Form, Migrate Plus/Tools, Geofield/Geofield Map, Gin admin theme + Gin Toolbar/Login, Radix theme, Pathauto, Redirect, Views Bulk Operations/Edit, Tamper, Drush 13.

Composer patches applied (see `composer.json` extra.patches):
- drupal/core — typeerror fix (MR 12073)
- drupal/eck — revision support (MR 74)
- drupal/views_bulk_edit — token support patch

## Common Commands
- `composer install` — install PHP dependencies
- `drush config:export` / `drush config:import` — sync config with `config/sync/`
- `drush cr` — rebuild cache
- `drush updb` — run pending DB updates
- Theme (from `web/themes/custom/gkradix`): `npm install`, `npm run build` (production), `npm run dev`/`npm run watch` (development), `npm run biome:lint`, `npm run stylint`

## Notes for Agents
- This is a live production-adjacent site path (`admingk.akri.net`); be careful with destructive commands.
- Config changes to Drupal entities (content types, views, etc.) should be exported to `config/sync/` to persist them.
- Custom module `gk_application_setup` README is still the Drupal module template boilerplate and doesn't reflect actual functionality — inspect `src/` and `.routing.yml`/`.permissions.yml` directly for real behavior.
