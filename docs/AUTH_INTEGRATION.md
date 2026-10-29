# McNutt Cloud authentication

Sources: https://login.mcnutt.cloud/docs/ (OpenAPI) and the official PHP client helper samples (`auth.php`, `config.php`) supplied by the owner on 2026-10-29. The samples are kept locally in `Auth_samples/` (git-ignored — `config.php` contains another app's secret) and are **not** vendored; `App\Services\Auth\McNuttAuthGateway` reimplements the same protocol inside Laravel.

## Helper behavior → this app

| Helper (`auth.php`) | This app |
|---|---|
| Login redirect `{login_base}/?return_url=…&app_id=…` | Same (`AUTH_LOGIN_URL_TEMPLATE`), `return_url=https://invoices.mcnutt.cloud/auth/callback` |
| Callback `?payload=<JSON>&sig=…&app_id=…`; `sig = base64url(HMAC-SHA256(raw payload, app_secret))` | Same; only the raw `payload` value is accepted |
| Payload fields used: `identity`, `roles`, `session_token`, `exp` | Same; `roles` are inside the signed payload. `iss`, `principal`, `iat` are enforced only when present (principal defaults to `user` / `identity.uid`) |
| Bad signature / expired → `{login_base}/access_denied.php?reason=invalid_signature\|expired&app_id&return_url` | Same, plus `reason=not_authorized` when validate.php refuses the session for this app |
| Revalidates every request via `validate.php?token&app_id&client_ip`; response `{ok, payload, sig}`, `sig` over `json_encode(payload, JSON_UNESCAPED_SLASHES)` | Same endpoint, parameters and signature check; revalidates every `AUTH_REVALIDATE_SECONDS` (300 s) and before sensitive actions |
| Identity JSON in an unsigned `mc_auth` cookie on `.mcnutt.cloud` | **Not used.** Identity lives server-side in this app's host-only encrypted session (`__Host-mcfin_session`). `cookie_name`, `cookie_domain`, `ttl_sec`, `refresh_sec` have no equivalent |
| Logout `{login_base}/logout_confirm.php?token=…&return_url=…` (central UI revokes) | Same (`AUTH_LOGOUT_URL_TEMPLATE`); the local session is destroyed first |
| `ensure_role()` on central roles | Not used for tenant access (see below) |
| `validate_api_key_c()` (system API keys) | Not supported; only `user` principals can sign in |

## Flow implemented

1. `GET /auth/login` → central login.
2. `GET /auth/callback`:
   * `app_id` must equal `AUTH_APP_ID` (constant-time); HMAC verified with `hash_equals`.
   * `exp` in the future (30 s skew); `iat` (if present) within `AUTH_CALLBACK_MAX_AGE_SECONDS`; non-`user` principals rejected.
   * Replay: sha256(sig) stored in `auth_callback_nonces`; a second use is rejected (403).
   * With `AUTH_VALIDATE_ON_CALLBACK=true` (default) the `session_token` is immediately validated server-to-server for this `app_id`; the validated principal must match the callback.
   * Local session regenerated (user id, principal id, encrypted session token, expiry, `validated_at`); the redirect strips `payload`/`sig` from the address bar.
3. Every request: revalidate when older than 300 s or expired. `ok:false` or bad signature → local logout. Network error / 5xx / 429 → **503 (fail closed)**.
4. `central:fresh` routes (member/role changes, payment instructions, claim confirmation, reversals/refunds, manual journals, period close/reopen, countersigning, platform mappings) force revalidation if older than 60 s.
5. `POST /auth/logout` → audit, destroy local session, redirect to central `logout_confirm.php` with the session token.

## Identity & authorization

* `users` keyed by `(central_principal_type, central_principal_id)`; email/name are refreshed on each validation but never used as keys.
* Effective access = verified identity + app access (validate.php with our app_id) + **active membership in the business** + local permission. Central roles do not grant tenant access. Central roles can only map to platform capabilities (`businesses.create`, `platform.operations`) via audited `central_role_mappings`; local membership removal always wins.
* Invitations bind business + role, are single-use, hashed, 7-day expiry, and require the signed-in identity email to equal the invited email. Because the identity schema has no verified-email claim, membership starts `pending_approval` until an owner/admin approves (`AUTH_EMAIL_IS_VERIFIED=true` skips this only if McNutt Cloud guarantees verified emails).

## Cookies

The app uses its own host-only session cookie (`SESSION_COOKIE=__Host-mcfin_session`, Secure, HttpOnly, SameSite=Lax, no Domain) and does not set or read the helper's `.mcnutt.cloud` cookie, avoiding a parent-domain cookie. Session ids rotate on login.

## Logging

Apache logs omit query strings entirely (`%U`) so `payload`/`sig` never reach access logs; the app never logs tokens or secrets (`Audit::sanitize` redacts token/secret/sig/otp keys).

## Remaining items

1. Register a **dedicated** app id/secret for this application with callback `https://invoices.mcnutt.cloud/auth/callback`. Never reuse the sample `notifications` app id/secret.
2. Callback browser binding: the protocol has no `state` parameter; mitigated by short `exp`, single-use signatures and immediate server-side validation.
3. Live check after registration: sign in; confirm a user **not** assigned to the app lands on `access_denied.php?reason=not_authorized`; confirm logout returns to the app.

## Configuration

`AUTH_LOGIN_BASE`, `AUTH_APP_ID`, `AUTH_APP_SECRET`, `AUTH_EXPECTED_ISSUER`, `AUTH_VALIDATE_ENDPOINT`, `AUTH_LOGIN_URL_TEMPLATE`, `AUTH_LOGOUT_URL_TEMPLATE`, `AUTH_REVALIDATE_SECONDS`, `AUTH_CALLBACK_MAX_AGE_SECONDS`, `AUTH_VALIDATE_ON_CALLBACK`, `AUTH_EMAIL_IS_VERIFIED`, `AUTH_BUSINESS_CREATION`, `FINANCE_PLATFORM_ADMINS`.

Tests: `tests/Feature/AuthTest.php` (valid callback, helper-shaped payload without `principal`/`iss`, not-authorized app, logout redirect, bad HMAC, expired, wrong app, magic/system principals, wrong issuer, unsigned role tampering, replay, outage fail-closed, revocation).
