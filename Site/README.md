# Pericles PHP API — Phase 5

PHP 8.2/MySQL API for the Pericles launcher. The recommended Apache document root is `Site/public`. Every module route requires a Bearer token whose session is linked to a cryptographically verified device.

The public storefront, customer account, checkout, and administration use the same MySQL catalog. `games` is the canonical game list and every game cover is read from `games.image_url`.

## Commercial storefront

Migration `008_commerce_platform.sql` adds commerce, content, password reset, support, downtime, orders, and payments. Apply it before serving the storefront:

```powershell
php bin/migrate.php
```

Main routes:

- `/enhancements` and `/enhancements/{game-slug}`: database-driven catalog and Access Plans;
- `/checkout/{token}`: checkout that survives sign-in or registration;
- `/status` and `/changelog`: published product state and updates;
- `/account`: customer Enhancements, access, devices, documentation, billing, and support;
- `/admin/products`, `/admin/payments`, and `/admin/support`: commercial management.

Commercial environment variables:

```env
PASSWORD_RESET_TTL=1800
PASSWORD_RESET_LIMIT=3
MAIL_FROM=no-reply@pericles.gg
PAYMENT_PROVIDER=
PAYMENT_CHECKOUT_URL=
PAYMENT_WEBHOOK_SECRET=
```

Keep payment variables empty until a real hosted provider and signed webhook are configured. The site never simulates payment success and grants access only after a verified `paid` webhook.

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
