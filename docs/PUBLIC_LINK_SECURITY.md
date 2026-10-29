# Public link security

## Tokens
* 256-bit random (`random_bytes(32)`, base64url, 43 chars). Only `sha256(token)` is stored (`public_tokens.token_hash`).
* Each token records business, purpose (`invoice_view`, `contract_sign`, `contract_view`), the exact resource (invoice; contract version + recipient), allowed actions, intended recipient email, expiry, revocation and usage counters.
* Defaults: invoice links 90 days, signing links 30 days (per-business settings, bounded 1–365 / 1–90). Completed-agreement view links: 90 days.
* A new token is minted per recipient per send; resending never reuses an old link. Staff can revoke all links for an invoice; voiding an invoice or envelope revokes them automatically; completing an envelope revokes signing links.
* Raw tokens exist only in the encrypted outbox body until the mail service accepts the message, then the body is purged (failed/unknown sends are purged after 14 days).

## Exchange → clean URL
`GET /i/{token}` or `/c/{token}`: validate (purpose, expiry, revocation, active business) → store a grant (token id) in the session → regenerate session id → 302 to `/i/v/{invoice-ulid}` or `/c/r/{recipient-id}`. Subsequent requests authorize against the session grant **and** the specific resource; the ULID/ID in the clean URL is only a locator. Invalid/expired/revoked → neutral "link not available" 404 (no information about whether a document exists).

## What a link can reach
* Invoice link: that invoice's page, its current issued PDF, remittance slip, payment report form, and — only if the token allows `view_contract` and the invoice references a **completed** contract version — that exact signed agreement and its certificate. Never other invoices, other versions, drafts, or internal notes.
* Signing link: only that recipient's page for that version. Invoice access can never sign; signing pages require the `contract_sign` purpose and a fresh email OTP.

## GET safety
GET requests never sign, pay, consume one-time codes or change financial state. Link scanners (Safe Links, Proofpoint…) only create a `link_accessed` access event, flagged `probable_automation` by user-agent heuristics; staff see "possibly automated". Pixel tracking is not used (the mail body is plain text), so opens are not claimed.

## Headers & limits
Public responses: `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow, noarchive`, CSP `default-src 'self'` with no external assets, `X-Frame-Options: DENY`. Rate limits: token exchange 20/min/IP, page views 30/min/IP, writes (payment reports, OTP, sign) 10/min and 60/h per IP; payment reports also capped at 5 per invoice per day. All POSTs are CSRF-protected. Uploads are MIME-sniffed (PDF/PNG/JPEG only), size-limited and stored privately.

## Logs
The vhost logs `%U` (path without query string) and rewrites token-bearing paths (`/i/…`, `/c/…`, `/invite/…`) to `[redacted-link]`. The app never logs raw tokens.
