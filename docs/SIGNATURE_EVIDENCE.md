# Electronic signature evidence

## Lifecycle
Agreement and template text is rich text (headings, bold/italic/underline, lists, quotes, links, tables) edited in a built-in editor and stored as HTML sanitized server-side with a strict whitelist (`App\Support\RichText`: no scripts, styles, images, iframes or event handlers; links limited to https/http/mailto). Merge-field values are HTML-escaped when inserted. Older plain-text bodies (# headings, **bold**, - lists) still render and convert to rich text when edited.

draft → prepared (merge fields validated, PDF rendered, SHA-256 recorded, version locked) → sent → partially_signed → completed; or declined / expired / voided. Changes after sending require voiding and a new version; amendments are new versions referencing the signed original (`amends_version_id`) and never alter it or past invoices. Owners may upload an externally signed PDF with written provenance; it is labeled "externally signed" and no in-app signature trail is fabricated.

## Signer verification & ceremony
1. Recipient-specific link → session grant (`contract_sign`).
2. Full document shown (HTML of the immutable rendered text, plus PDF download).
3. Email one-time code (per-agreement option, on by default): 6 digits, HMAC-hashed with `APP_KEY`, 10-minute expiry, max 5 attempts, max 5 sends, 60 s resend cooldown. Verification proves inbox access only — not legal identity. When the agreement waives the code, the recipient-specific emailed link is the verification and the certificate records "Personal signing link emailed to … (no one-time code)".
   Countersigners may save a drawn signature (stored privately per business membership) and reuse it; each use is still a separate, hashed signature event.
4. Consent to electronic records (versioned text, downloadable), typed legal name/title, authority acknowledgement, typed or drawn signature, explicit "Sign agreement" confirmation.
5. Business countersigners sign inside the authenticated app (verification method `authenticated_session`, fresh McNutt Cloud validation).

## Integrity controls
* The form posts the document SHA-256; signing is rejected if it differs from the version's hash.
* Version and recipient rows are locked during signing; a recipient can sign once; voided/expired/declined envelopes reject signing; signing links are revoked at completion.
* Sequential order is enforced; the next signer is invited only after the previous one signs.

## Evidence recorded
Per recipient: typed name/title, signature kind, drawn image (private PNG), signature hash = sha256(name | kind | image | document hash), UTC time, IP (via trusted-proxy config), user agent, verified email. Per version: ordered `signature_events` (prepared, sent, invited, viewed, code sent, verified/failed, consented, signed, declined, voided, completed) each with the document hash. Consent rows store the exact text and version.

## Completion artifacts
* Signed PDF (document + signature blocks) and its SHA-256.
* Audit certificate PDF: original and final hashes, signers, verification methods, times, IP/device, event log, consent text and limitations.
* All recipients get a 90-day view link to both; downloads never count as signing.

## Limitations (disclosed on the certificate)
SHA-256 hashes are application-level integrity evidence, not a certificate-based digital signature or trusted timestamp. Records are protected by application permissions and encrypted backups, but a system administrator with direct database/file access could alter them. The application does not claim blanket legal enforceability; businesses should have counsel review their workflow, retention period and consent text. A staff-assisted paper alternative is offered on the signing page.
