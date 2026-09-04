# Deployment

## Branch model

| Branch | Goes to | Approval |
|---|---|---|
| `staging` | `staging.diwan.com` → `~/staging` + `~/app-staging` | none — deploys immediately |
| `main` | `diwan.com` → `~/public_html` + `~/app` | **required reviewer** on the `production` environment |

Anything else runs `ci.yml` only (lint, build, schema check) and deploys nowhere.

Normal flow: branch → PR → merge to `staging` → verify on the staging URL →
merge `staging` into `main` → read the dry-run output → approve.

## What the pipeline actually does

### Job 1 — `build`
Runs once, for both environments, so staging and production ship byte-identical
code.

1. `php -l` over every file under `backend/`.
2. `frontend/build.sh` — cleans `dist/`, copies `src/`, strips `.DS_Store` and
   source maps, and **verifies every `href="/…"` / `src="/…"` in the HTML points
   at a file that exists in `dist/`**. A typo'd stylesheet path fails the build.
3. Assembles `bundle/web/` (site + `api/`) and `bundle/app/src/`.
4. **Guard step** — fails the run if the bundle contains any `.exe/.dmg/.apk/
   .msi/.pkg`, any `.env`, or a hardcoded `DB_PASS=`/`JAZZCASH_INTEGRITY_SALT=`.

Every one of these exits non-zero on failure, and the deploy jobs `needs: build`.
**A broken build cannot reach the server**, because no FTP connection is opened
until this job is green.

### Job 2/3 — deploy
`.env` and `api/_app_root.php` are generated on the runner from the environment's
secrets, then three separate FTP transfers run:

| # | Local | Server | State file |
|---|---|---|---|
| 1 | `bundle/app/src/` | `${APP_ROOT}/src/` | `.ftp-deploy-app-src.json` |
| 2 | `bundle/app/config/` | `${APP_ROOT}/config/` | `.ftp-deploy-app-config.json` |
| 3 | `bundle/web/` | `${WEB_ROOT}/` | `.ftp-deploy-web.json` |

Classes and config go up **before** the entrypoints that call them, so there is
no window in which new PHP looks for a class that has not arrived yet.

Each transfer has its own `state-name`. This matters: `SamKirkland/FTP-Deploy-Action`
keeps a JSON manifest on the server of what it last uploaded and diffs against
it. If all three shared one manifest they would each conclude the other two's
files had been deleted, and delete them.

## How `private-storage` survives every deploy

Two independent mechanisms, either of which alone is sufficient:

**1. It is outside every `server-dir`.** The deploy targets `~/app/src/`,
`~/app/config/` and `~/public_html/`. `~/app/private-storage/` is a sibling of
`src/`, not a child of any target. The action only ever walks the directory it
is pointed at, so it never sees the installers — it cannot delete what it never
enumerates. This is the real protection.

**2. An explicit exclude, as a safety net** against a future edit that widens
`server-dir` to `~/app/`:

```yaml
exclude: |
  **/private-storage/**
```

In v4 of the action, `exclude` suppresses **both uploading and deleting** — an
excluded path is skipped in both directions, so a matching file already on the
server is left strictly alone. That is exactly the behaviour needed here, and
it is why the pattern is `**/private-storage/**` (contents) rather than a bare
directory name.

The same exclude list also protects host-managed files that must not be wiped:
`.well-known/` (Let's Encrypt / domain verification), `cgi-bin/`, `error_log`,
`.ftpquota`, and any `php.ini` / `.user.ini` your host placed in the web root.

**Never set `dangerous-clean-slate: true`.** It deletes the entire server
directory before uploading. It is set to `false` explicitly on every step so the
intent is on the record.

## The confirmation gate

Production deploys pass through two checks:

1. **`production-dry-run`** runs first, with `dry-run: true` and
   `log-level: verbose`. It connects, diffs against the server state and prints
   every file it *would* upload or delete — without uploading anything. This job
   deliberately has **no** `environment:`, so it runs unattended and the diff is
   waiting for you before you are asked to approve.
2. **`deploy-production`** declares `environment: production`. Once you add a
   required reviewer to that environment, the job pauses and GitHub asks a human
   to approve. Nothing is transferred until someone clicks Approve.

Set it up: **Settings → Environments → New environment → `production` →
Required reviewers → add yourself → Save.** Without this step the production
job runs straight through; the dry run still happens, but nobody has to read it.

You can also deploy manually from the Actions tab: **Run workflow** → pick
`production` and tick `dry_run` to get the diff and stop there.

## Post-deploy verification

`scripts/smoke-test.sh` runs automatically at the end of every deploy and fails
the workflow if:

- the homepage, CSS or JS is not 200,
- `/api/health.php` does not report `"status":"ok"` (it checks DB connectivity
  and that `PRIVATE_STORAGE_PATH` exists and is readable),
- **any private path returns content** — `/private-storage/`, `/api/_app_root.php`,
  `/.env`, `/src/bootstrap.php`, the FTP state file, and others,
- `download.php` accepts a request with no token or a bogus token.

Run it by hand any time:

```bash
bash scripts/smoke-test.sh https://diwan.com
bash scripts/verify-private-storage.sh https://diwan.com
```

## Rollback

There is no automated rollback — FTP has no atomic swap. To revert:

```bash
git revert <bad-commit>
git push origin main
```

This re-runs the whole pipeline with the previous code. It takes as long as a
normal deploy (usually under a minute, since the action only transfers changed
files). For a true emergency, `git checkout <good-sha>` and deploy manually via
**Run workflow**.

Database changes are **not** rolled back by this — which is precisely why
migrations are applied by hand, before the deploy that needs them, and are
written to be backward-compatible with the currently-live code.

## First-time setup checklist

1. Create the server directories (see `docs/SERVER-CHECKLIST.md`).
2. Import `database/schema.sql` via phpMyAdmin, for both databases.
3. Create the `staging` and `production` environments in GitHub, with a required
   reviewer on `production`.
4. Add secrets and variables to each environment.
5. Push to `staging`, watch it deploy, run the smoke test.
6. Upload an installer manually, insert its `releases` row, and buy a licence
   end-to-end on staging with gateway sandbox credentials.
7. Only then merge to `main`.
