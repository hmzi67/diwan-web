# Temporary free-trial onboarding

Apply `database/migrations/007_free_trial.sql` manually **before** deploying this
code. It adds the `fulfilled` order status, the permanent `trial_grants` ledger,
and an inactive `diwan-pos-trial` SKU. The full schema includes the same changes.
Do not run an older copy of migration 005: only the updated version excludes
trial orders from the historical paid-expiry repair.

`TRIAL_SIGNUP_ENABLED=true` enables new grants (the temporary default). This is
also an environment-level GitHub deployment Variable. Set it to the string
`false` and redeploy to stop new grants; existing trial and paid access continues.
`LICENSE_VALIDITY_DAYS=0` remains the perpetual paid-licence setting. Trials use
an explicit 30-day duration independently of that setting and of download-token
or offline-refresh lifetimes.

## Behavior and boundaries

- New password signup commits account, trial order, grant, and licence together.
  Sign-in is established only after commit. Failed issuance rolls back signup.
- Existing users explicitly choosing a platform invoke authenticated POST
  `/api/start-trial.php`. Active paid owners use their paid entitlement; prior
  trial recipients keep their existing trial, even if expired or revoked.
- Dashboard GET is read-only. Full keys are decrypted only for the authenticated
  order owner; JSON responses use `Cache-Control: no-store`.
- Signup does not depend on email delivery. The key is immediately in the
  dashboard, with the existing optional resend action available.
- Platform context is allowlisted and passed through sign-in, signup and reset
  links. It does not affect trial eligibility, duration, or device binding.
- `TrialService` owns trial eligibility and order creation. It locks the customer
  row inside a transaction; unique customer/email constraints on `trial_grants`
  and the existing unique licence/order constraint prevent duplicate grants.
- Trial orders have `gateway=trial`, `status=fulfilled`, zero amount, null
  `paid_at`, and no gateway transaction. Never mark a free trial as paid. The
  trial SKU is inactive for checkout. Payment transitions and licence issuers
  reject orders belonging to the other issuance path.
- Trial and paid licences share key format, protected downloads, and one-time
  fingerprint activation. Trial expiry starts at issuance, not activation.
- Release files must still be installed in private storage and represented by
  active `releases` rows for Windows, macOS and Android. Missing installers
  produce the existing unavailable message; this change adds no binaries.

## Later paid upgrades

Keep trial records unchanged for reporting and retain the grant ledger to
prevent another free trial. Add a purchase CTA when the trial expires, then
create a separate paid order through checkout and the verified payment webhook.
Issue a perpetual paid licence after verified payment and let the customer
activate that replacement key. A future relation from the paid order to the
trial order can attribute conversions without rewriting trial history.

Turning off the issuance flag and removing the signup/start-trial call sites
retires this temporary offer. Retain the schema and the ability to read existing
licences. The static marketing copy should be updated when the offer is disabled.

The server already signs `expires_at` in device status. Each installed client
must enforce that timestamp offline as well as its refresh deadline; verify this
in the application repositories before releasing the trial installers.

## Validation

With PHP 8.1–8.3 and a disposable MySQL 8 database named `diwan_test_*`, apply the
schema and migration 007 twice. Create a test env file with DB credentials,
`APP_KEY`, `LICENSE_VALIDITY_DAYS=0`, and `TRIAL_SIGNUP_ENABLED=true`, then run:

```sh
php scripts/test-trial.php /path/to/test.env
```

The integration check covers issuance, retries, concurrent requests, expiry, revocation, activation,
paid/trial isolation, perpetual paid licences, migration 005, and rollback.
Repeat with `TRIAL_SIGNUP_ENABLED=false` to check the issuance kill switch.
CI runs both modes across PHP 8.1–8.3 with disposable credentials.

Manual browser checks: select each platform, switch between auth pages, sign up,
copy the dashboard key, download, sign out/in, and revisit the platform cards.
An expired trial must retain the same key and expiry with no download action.
Check mobile layout and keyboard focus. Test concurrent trial-start requests for
one account and confirm only one order, licence and grant are stored.
