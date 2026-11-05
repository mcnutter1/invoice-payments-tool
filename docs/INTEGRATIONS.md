# External integrations

| Integration | Adapter | Status reported at runtime |
|---|---|---|
| McNutt Cloud auth | `Services/Auth/McNuttAuthGateway` | Settings → Automation monitor and `/platform`: disabled / unconfigured / healthy / failed |
| McNutt Cloud notifications | `Services/Notifications/McNuttNotificationGateway` | same |

Development drivers (`AUTH_DRIVER=dev`, `NOTIFICATIONS_DRIVER=log`) are refused when `APP_ENV=production`.

## Notification service

Contract supplied by the owner (the docs URL returns 404; `POST /api/send.php` answers 401 unauthenticated, confirming the endpoint exists):

```http
POST https://notifications.mcnutt.cloud/api/send.php
Authorization: Bearer <NOTIFICATIONS_API_TOKEN>
Content-Type: application/json

{"channel":"email","to":"client@example.com","from":"Business Name <approved-sender@mcnutt.cloud>",
 "subject":"Invoice INV-000123 from Business Name","body":"<!DOCTYPE html>…branded HTML with secure links…",
 "provider":"Twilio Email (SendGrid)"}
```

Mapped fields: **only** `channel`, `to`, `from`, `subject`, `body`, optional `provider`. `body` carries a complete HTML document (owner confirmed the service auto-detects HTML in `body`); templates live in `resources/views/emails/message.blade.php` with inline styles. Not used: `template_name`/`data`, attachments, idempotency keys, delivery webhooks. The origin is fixed and allowlisted (`notifications.mcnutt.cloud`, https only); the path is hard-coded.

### Open & click tracking (first-party)

Each outbox row gets a random 256-bit tracking token (only its SHA-256 is stored).
* **Opens:** a 1×1 image `GET /t/o/{token}.gif` sets `opened_at`/`open_count` and records `email_opened` (re-fetches within 10 min are counted but not re-listed). Approximate: Apple Mail Privacy Protection may preload images, other clients block them. Gmail's image proxy counts as a real open.
* **Clicks:** email buttons append `?e={token}&a=view|pay|pdf|sign|agreement` to the existing secure link; the link exchange records `email_clicked` only when the tracking token belongs to the same business and document as the link.
* **On-page:** `invoice_viewed`, `pdf_downloaded`, `remittance_downloaded`, `pay_clicked` (“Pay now” in the email or page), `payment_details_copied` (copy buttons, via a CSRF-protected POST), `payment_reported`.
* All events appear in the invoice's Activity timeline and as the strongest signal in the invoice list. None of them change financial state.

Response handling (`OutboxProcessor`):

| Result | Status | Behavior |
|---|---|---|
| 2xx (and not `{"ok":false}`) | `accepted` | "Accepted by mail service" — **not** delivered. `id`/`message_id` stored if present. Encrypted body purged. |
| 429 / 5xx / connection refused | `queued` → retry | exponential backoff (1, 2, 4… min, ≤1 h) + jitter, max 6 attempts → `failed` + review |
| other 4xx / `ok:false` | `failed` | needs correction; listed in the automation monitor |
| timeout after request sent (cURL 28) or worker crash mid-send | `delivery_unknown` | flagged `needs_review`; staff must explicitly resend (may duplicate) |

`delivered`/`bounced` states exist in the schema but are never set, because the service documents no delivery events or signed webhooks. `provider_events` is reserved for that.

### PDF attachments — owner decision

The notification service does not support attachments. Per the owner's decision (2026-10-29), invoice emails include a **secure "Download PDF" link** (the same scoped, expiring invoice token with `?d=pdf`) instead of an attachment. The UI and email copy say the PDF is linked, never "attached". The PDF is generated and stored by this application at issue time and served only through the token-scoped route — there is no permanent public file URL.

If attachments are added to the service later, the proposed backwards-compatible extension is:

```json
"attachments": [{"filename": "INV-000123.pdf", "content_type": "application/pdf",
                 "content_base64": "…", "sha256": "…"}]
```
Constraints: ≤ 5 attachments, ≤ 5 MB decoded each, filename `[A-Za-z0-9._-]{1,100}`, MIME allowlist (`application/pdf`), base64 strict decode, hash verified server-side, no URL fetching. The adapter would map `OutgoingMessage` attachments only after this is verified in a sandbox.

### Sender identity

`from` = sanitized business sender name + `NOTIFICATIONS_FROM_EMAIL`, unless the business's sender email is listed in `NOTIFICATIONS_APPROVED_SENDERS`. When the shared platform address is used the display name reads “Business via McNutt Finance” so it is not mistaken for display-name spoofing. CR/LF, `@` and header metacharacters are stripped from names, subjects and addresses.

### Deliverability (Microsoft 365 / Gmail phishing filters)

Template rules: no hidden preheader or zero-width characters, no payment instructions (Zelle handles, mailing addresses) in the email body — those stay on the invoice page/PDF — no “secure/personal/expires/verify” phrasing, a single clear primary action, and a footer with the business name, postal address and contact details.

Outside the app (required for M365 to trust the mail):
* SPF and DKIM for the sending domain must pass **and align** with the `From` domain; publish DMARC (`p=quarantine` or `reject` once clean).
* Configure the provider's link-branding / click-tracking domain on the same organizational domain as `From`, and keep `APP_URL` on a domain with good reputation (links point there).
* Prefer a dedicated subdomain (e.g. `mail.example.com`) per sender; warm up volume gradually.
* Recipients' M365 admins can add the sending domain to Tenant Allow/Block or a transport rule if their tenant still quarantines.

### Sanitized example payload (invoice)

```json
{"channel":"email","to":"ap@client.example","from":"McNutt Consulting <billing@mcnutt.cloud>",
 "subject":"Invoice INV-000042 from McNutt Consulting",
 "body":"Hello Client Co,\n\n…\nInvoice: INV-000042\nAmount due: $1,125.00\nDue date: November 8, 2026\n\nView invoice & pay: https://invoices.mcnutt.cloud/i/<token>\nDownload PDF: https://invoices.mcnutt.cloud/i/<token>?d=pdf\n…"}
```

## Sandbox verification checklist (operator)

1. Settings → Email → "Send a test email" → automation monitor shows `accepted`.
2. Issue a test invoice to your own address; open both links; confirm the public page and PDF.
3. Record the HTTP response body shape in this file (does it return a message id?).
