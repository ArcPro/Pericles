# Pericles PHP API — Phase 5

API PHP 8.2/MySQL du launcher Pericles. Le document root Apache recommandé est `Site/public`. Toutes les routes modules exigent un Bearer token dont la session est liée à un appareil cryptographiquement vérifié.

## Installation

```powershell
composer install
Copy-Item .env.example .env
php bin/migrate.php
```

Configuration modules :

```env
MODULE_TICKET_TTL=30
MAX_MODULE_SIZE_MB=50
MODULE_TICKET_RATE_LIMIT=20
MODULE_DOWNLOAD_RATE_LIMIT=10
MODULE_RATE_WINDOW=60
MODULE_SIGNING_KEY_ID=pericles-modules-2026-01
MODULE_SIGNING_PRIVATE_KEY_PATH=/secure/path/module-signing-private.pem
```

La clé privée doit rester hors de Git et hors du webroot. La clé présente dans `tests/Fixtures` est exclusivement une clé de test automatisé.

## Endpoints Phase 5

| Méthode | Route | Description |
|---|---|---|
| `POST` | `/api/v1/modules/ticket` | Recalcule le droit et émet un ticket court hashé en base |
| `GET` | `/api/v1/modules/download` | Exige Bearer + `X-Module-Ticket`, consomme le ticket et retourne un paquet signé/chiffré |

La réponse de téléchargement a le type `application/vnd.pericles.module-package`, interdit le cache et transporte la clé AES aléatoire de ce téléchargement dans `X-Module-Session-Key` en Base64URL. Aucun ticket n’est placé dans l’URL.

## Administration

```powershell
php bin/generate-module-signing-key.php <private-path> <public-path>
php bin/publish-module.php deadlock <source-file> 1.0.0
php bin/activate-module-version.php deadlock 1.0.0
```

Les modules publiés sont conservés dans `storage/modules/<game>/<version>/Module.dll`, jamais sous `public`. La publication crée une version `draft`; l’activation transactionnelle sélectionne l’unique version active.

## Sécurité du ticket et du paquet

- ticket : 32 octets CSPRNG, Base64URL, seulement SHA-256 en base, TTL configurable, user/device/game/version-bound;
- consommation : transaction, `SELECT ... FOR UPDATE`, update conditionnel et audit `module_downloads`;
- revalidation : utilisateur, device, jeu, module, version, subscription et binding au téléchargement;
- payload : AES-256-GCM, clé 32 octets/nonce 12 octets/tag 16 octets frais par téléchargement;
- authenticité : ECDSA P-256/SHA-256, signature DER couvrant manifeste, nonce, tag et ciphertext;
- intégrité finale : SHA-256 et taille du plaintext contrôlés par le launcher.

## Tests

```powershell
composer test
```

Les tests utilisent SQLite en mémoire et `Fixtures/TestModule.bin`. Le test .NET complémentaire appelle `tests/build_module_package_fixture.php` pour valider l’interopérabilité réelle PHP/OpenSSL → C#.
