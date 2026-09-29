# Repository Guidelines

## Project Structure & Module Organization
Diwan combines a static storefront with a PHP payment, authentication, licensing, and download backend backed by MySQL.
- `frontend/src/`: editable HTML, `css/`, `js/`, and `assets/`. `frontend/dist/` is generated and ignored; never edit it directly.
- `backend/public/`: HTTP entrypoints served under `/api/`. Keep these thin; place business logic in domain modules under `backend/src/` (`Auth`, `Payment`, `License`, `Download`).
- `database/schema.sql` and `database/migrations/`: full schema and incremental changes.
- `scripts/`: development, smoke checks, and deployment utilities. `.github/workflows/` defines CI/deployment; `docs/` contains hosting instructions.

## Build, Test, and Development Commands
Run commands from the repository root. Use PHP 8.1–8.3, MySQL 8.0, Bash, and Perl; no Node build tooling is required.
- `bash scripts/dev.sh`: builds and serves at `http://localhost:8000`; creates local configuration if missing. Set database credentials before exercising backend flows. Restart after frontend edits to refresh the served copy.
- `bash frontend/build.sh`: rebuilds static output, validates local asset references, and stamps asset URLs.
- `find backend -name '*.php' -exec php -l {} \;`: checks PHP syntax.
- `for f in scripts/*.sh frontend/build.sh; do bash -n "$f"; done`: checks shell syntax.
- `bash scripts/smoke-test.sh <base-url>`: checks a running deployment, including health and private-path protection.

## Coding Style & Naming Conventions
Match surrounding formatting: four spaces in PHP, two in frontend JavaScript/CSS. Use strict PHP types, `Diwan\` namespaces, PascalCase class filenames, and camelCase methods. Endpoint filenames use kebab-case. Reuse CSS custom properties in `styles.css`. No dedicated formatter or style linter is configured; avoid unrelated reformatting and changes to bundled `backend/src/ThirdParty/` code.

## Testing Guidelines
There is no unit-test framework or coverage threshold. CI checks PHP/shell syntax, builds assets, and applies the schema twice to verify idempotency. Repeat relevant checks and manually exercise changed flows. Smoke-test exit code `2` means inconclusive, not passing.

## Commit & Pull Request Guidelines
Recent commits favor `fix(scope): description` and `feat: description`. Keep commits focused. PRs should describe behavior changes, link relevant issues, report validation, and include screenshots for UI changes and migration/configuration instructions when needed.

## Security & Database Changes
Never commit `.env` values, signing keys, logs, or installer binaries. Keep configuration and private storage outside the document root. Add idempotent `NNN_short_description.sql` migrations and update the full schema; migrations are applied manually before dependent deployments.
