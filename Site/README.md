# Pericles PHP API — Phase 5

PHP 8.2/MySQL API for the Pericles launcher. The recommended Apache document root is `Site/public`. Every module route requires a Bearer token whose session is linked to a cryptographically verified device.

The public storefront, customer account, checkout, and administration use the same MySQL catalog. `games` is the canonical game list and every game cover is read from `games.image_url`.

## Commercial storefront

Migrations `008_commerce_platform.sql` through `011_support_category_compatibility.sql` add commerce, content, password reset, persistent support conversations and attachments, product operations, downtime compensation, orders, payments, safe application settings, and support-category compatibility. Apply all pending migrations before serving the storefront:

```powershell
php bin/migrate.php
```

Main routes:

- `/enhancements` and `/enhancements/{game-slug}`: database-driven catalog and Access Plans;
- `/checkout/{token}`: checkout that survives sign-in or registration;
- `/status` and `/changelog`: published product state and updates;
- `/account`: customer Enhancements, Access, devices, documentation, billing, and support;
- `/admin/enhancements`, `/admin/orders`, `/admin/customer-access`, `/admin/access-keys`, and `/admin/support`: commercial operations.

Commercial environment variables:

```env
PASSWORD_RESET_TTL=1800
PASSWORD_RESET_LIMIT=3
MAIL_FROM=no-reply@pericles.gg
SUPPORT_EMAIL=support@pericles.gg
LAUNCHER_DOWNLOAD_URL=
PAYMENT_PROVIDER=stripe
STRIPE_PUBLISHABLE_KEY=
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=

# Legacy generic provider settings; unused by Stripe Elements.
PAYMENT_CHECKOUT_URL=
PAYMENT_WEBHOOK_SECRET=
```

Stripe uses an embedded Payment Element backed by a Checkout Session in `payment` mode. Product names, plan names, amounts, and currencies come from the immutable Pericles order snapshot through inline `price_data`, so new products and future admin price changes need no Stripe code change.

Configure a Stripe webhook endpoint at:

```text
https://events.mazebank.fr/public/api/v1/payments/stripe/webhook
```

Configure it as a **snapshot-event** destination, subscribe it to `checkout.session.completed` and `checkout.session.async_payment_succeeded`, then copy its signing secret (`whsec_...`) into `STRIPE_WEBHOOK_SECRET`. Keep both Stripe secrets outside Git and the public document root. The site never trusts the browser return page to grant Access: it validates Stripe's signature, order reference, amount, and currency, then processes the event idempotently.

## Installation

```powershell
composer install
Copy-Item .env.example .env
php bin/migrate.php
```

Module configuration:

```env
MODULE_TICKET_TTL=30
MAX_MODULE_SIZE_MB=50
MODULE_TICKET_RATE_LIMIT=20
MODULE_DOWNLOAD_RATE_LIMIT=10
MODULE_RATE_WINDOW=60
MODULE_SIGNING_KEY_ID=pericles-modules-2026-01
MODULE_SIGNING_PRIVATE_KEY_PATH=/secure/path/module-signing-private.pem
```

The private key must remain outside Git and the webroot. The key stored under `tests/Fixtures` is exclusively intended for automated tests.

## Phase 5 endpoints

| Method | Route | Description |
|---|---|---|
| `POST` | `/api/v1/modules/ticket` | Revalidates access and issues a short-lived ticket stored as a hash |
| `GET` | `/api/v1/modules/download` | Requires Bearer + `X-Module-Ticket`, consumes the ticket, and returns a signed/encrypted package |

The download response uses the `application/vnd.pericles.module-package` content type, disables caching, and carries the random AES key for that download in `X-Module-Session-Key` as Base64URL. Tickets are never placed in URLs.

## Module administration

```powershell
php bin/generate-module-signing-key.php <private-path> <public-path>
php bin/publish-module.php deadlock <source-file> 1.0.0
php bin/activate-module-version.php deadlock 1.0.0
```

Published modules are stored in `storage/modules/<game>/<version>/Module.dll`, never under `public`. Publishing creates a `draft` version; transactional activation selects the only active version.

## Ticket and package security

- ticket: 32 CSPRNG bytes, Base64URL, only SHA-256 stored in the database, configurable TTL, bound to user/device/game/version;
- consumption: transaction, `SELECT ... FOR UPDATE`, conditional update, and audit in `module_downloads`;
- revalidation: user, device, game, module, version, subscription, and binding at download time;
- payload: AES-256-GCM with a fresh 32-byte key, 12-byte nonce, and 16-byte tag for each download;
- authenticity: ECDSA P-256/SHA-256 DER signature covering the manifest, nonce, tag, and ciphertext;
- final integrity: launcher verification of the plaintext SHA-256 digest and size.

## Tests

```powershell
composer test
```

Tests use in-memory SQLite and `Fixtures/TestModule.bin`. The complementary .NET test calls `tests/build_module_package_fixture.php` to validate real PHP/OpenSSL → C# interoperability.

## Administration panel and permissions

Run migrations after pulling these changes. Migrations `006_roles_admin.sql` and `007_english_labels.sql` create the RBAC system, audit log, and English persisted labels.

```powershell
php bin/migrate.php
```

- `admin`: full access to accounts, roles, licenses, HWIDs, and audit logs;
- `moderator`: account visibility plus license/device unlinking;
- ungraded player: no access to `/admin`.

Promote the first administrator from the command line to prevent privilege escalation through the web interface:

```powershell
php tools/set-role.php admin@example.com admin
```

Further role changes can be made from the Control Center. Every sensitive action is checked server-side and recorded in `admin_audit_logs`.
