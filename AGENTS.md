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
- `php scripts/tests/run.php <test.env> [filter]`: backend test suite against a disposable MySQL database named `diwan_test_*` (the env file needs only `DB_*` and `APP_KEY`).
- `php scripts/test-trial.php <test.env>`: trial licensing regression checks (CI also runs it with `TRIAL_SIGNUP_ENABLED=false`).
- `bash scripts/http-check.sh <test.env>`: serves the site and API with `php -S` and runs the smoke test against it, without touching `backend/config/.env`.
- `bash scripts/smoke-test.sh <base-url>`: checks a running deployment, including health, API refusals and private-path protection.

## Coding Style & Naming Conventions
Match surrounding formatting: four spaces in PHP, two in frontend JavaScript/CSS. Use strict PHP types, `Diwan\` namespaces, PascalCase class filenames, and camelCase methods. Endpoint filenames use kebab-case. Reuse CSS custom properties in `styles.css`. Frontend scripts call the backend only through `DiwanApi` (`frontend/src/js/api.js`) and must not declare their own top-level helpers. No dedicated formatter or style linter is configured; avoid unrelated reformatting and changes to bundled `backend/src/ThirdParty/` code.

Error handling: services throw for failures a caller must handle; only `Diwan\Support\UserFacingException` messages may be returned to a client, and every other exception is logged and answered with generic text. Best-effort side effects (sending mail, revoking an Apple token) return `bool`/`null` and log instead of throwing. Compare stored times in SQL against `NOW()`, never against PHP's clock.

## Testing Guidelines
There is no third-party test framework or coverage threshold. Tests are plain PHP files in `scripts/tests/*.test.php`, each returning `[name => callable]` and using `TestKit` fixtures (customers on `@tests.invalid`, IPs in 203.0.113.0/24) so cleanup removes exactly what a run created. Add tests with any change to payments, licensing, auth or downloads. CI checks PHP/shell syntax, builds assets, applies the schema and every migration twice, runs the test suite and trial checks, and runs the HTTP checks; `deploy.yml` requires all of it before deploying. Smoke-test exit code `2` means inconclusive, not passing.

## Commit & Pull Request Guidelines
Recent commits favor `fix(scope): description` and `feat: description`. Keep commits focused. PRs should describe behavior changes, link relevant issues, report validation, and include screenshots for UI changes and migration/configuration instructions when needed.

## Security & Database Changes
Never commit `.env` values, signing keys, logs, or installer binaries. Keep configuration and private storage outside the document root. Add idempotent `NNN_short_description.sql` migrations and update the full schema; migrations are applied manually before dependent deployments.
