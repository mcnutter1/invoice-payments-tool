# Permissions

Named permissions (`App\Support\Permissions::ALL`) are checked server-side by route middleware (`perm:`) and, for finer cases, in controllers/services. Roles are per business; a member may hold several roles (union of permissions). Default roles are created for every business and can be edited (except Owner); custom roles can be created.

| Permission | Owner | Admin | Accountant | Billing | Viewer |
|---|:-:|:-:|:-:|:-:|:-:|
| business.view | ✓ | ✓ | ✓ | ✓ | ✓ |
| business.manage (profile, branding, payment instructions, email) | ✓ | ✓ | | | |
| members.manage | ✓ | ✓ | | | |
| roles.manage | ✓ | ✓ | | | |
| ownership.transfer | ✓ | | | | |
| clients.view | ✓ | ✓ | ✓ | ✓ | ✓ |
| clients.manage, catalog.manage | ✓ | ✓ | ✓ | ✓ | |
| invoices.view | ✓ | ✓ | ✓ | ✓ | ✓ |
| invoices.manage / issue / send | ✓ | ✓ | ✓ | ✓ | |
| invoices.void (void, credit notes, write-offs) | ✓ | ✓ | ✓ | | |
| recurring.view | ✓ | ✓ | ✓ | ✓ | ✓ |
| recurring.manage | ✓ | ✓ | ✓ | ✓ | |
| contracts.view | ✓ | ✓ | ✓ | ✓ | ✓ |
| contracts.manage, contracts.send, contracts.countersign | ✓ | ✓ | | (delegable) | |
| payments.view | ✓ | ✓ | ✓ | ✓ | ✓ |
| payments.record, payments.confirm, payments.reverse | ✓ | ✓ | ✓ | (separate grant) | |
| expenses.view/manage, banking.view/manage, journals.view/manage | ✓ | ✓ | ✓ | | (grant) |
| reports.ar.view | ✓ | ✓ | ✓ | ✓ | (grant) |
| reports.pnl.view, reports.export | ✓ | ✓ | ✓ | | (grant) |
| books.close | ✓ | (delegable) | (grant) | | |
| books.reopen | ✓ | (delegable) | | | |
| automation.view, audit.view | ✓ | ✓ | ✓ | | |

Rules enforced in `MemberController`:
* You can only grant/revoke permissions — or assign roles containing permissions — that you hold yourself.
* Only owners can grant the Owner role; ownership transfer requires typing `TRANSFER` and fresh central validation.
* The last active owner cannot be removed or demoted (checked inside the transaction).
* Removing a membership takes effect on the very next request (membership is checked on every request, including downloads).
* Pending members (invitation accepted, email unverified) have no access until approved.

Platform (non-tenant) capabilities: `businesses.create` (default: any app user, `AUTH_BUSINESS_CREATION=all`), `platform.operations` (view integration health at `/platform`). Mappings are managed only by principals in `FINANCE_PLATFORM_ADMINS` and are audited. No platform capability grants access to any business's books; support access requires being invited as a member, which is visible and audited in that business.

The full route → permission inventory is in [ROUTES.md](ROUTES.md).
