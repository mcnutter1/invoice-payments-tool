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
 "subject":"Invoice INV-000123 from Business Name","body":"…plain text with secure links…",
 "provider":"Twilio Email (SendGrid)"}
```

Mapped fields: **only** `channel`, `to`, `from`, `subject`, `body`, optional `provider`. Not used because undocumented: `template_name`/`data`, HTML bodies, attachments, idempotency keys, delivery webhooks. The origin is fixed and allowlisted (`notifications.mcnutt.cloud`, https only); the path is hard-coded.

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

`from` = sanitized business sender name + `NOTIFICATIONS_FROM_EMAIL`, unless the business's sender email is listed in `NOTIFICATIONS_APPROVED_SENDERS`. CR/LF and header metacharacters are stripped from names, subjects and addresses.

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
