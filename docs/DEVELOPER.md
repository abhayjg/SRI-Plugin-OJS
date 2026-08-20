# Developer guide

## Layout

```
src/                  Shared, OJS-free core (namespace SRI\Plugin)
  ApiClient.php       Hardened cURL client (TLS + timeouts + injectable transport)
  ArticleData.php     Normalized article DTO
  CheckCharacter.php  Luhn mod-36 check char (mirror of SRI-Backend checksum.ts)
  MetadataMapper.php  ArticleData -> registration/update/bulk payloads
  StatePresenter.php  stored-SRI + status -> display state/labels
  StatusResolver.php  HTTP/error code -> friendly reason (gate table)
  SuffixGenerator.php %j.%a / custom tokens / manual; sanitize + disambiguation
  RegistrationService.php register / registerWithRetry / checkStatus / updateMetadata / submitBulk / getBulkJobStatus
plugin34/             OJS 3.4/3.5 namespaced adapter
  SriPubIdPlugin.php  extends PKP\plugins\PKPPubIdPlugin
  form/SriSettingsForm.php
  classes/SriMetadataBuilder.php   Submission/Publication/Issue -> ArticleData
plugin33/             OJS 3.3 legacy adapter (identical core)
  SriPubIdPlugin.inc.php  extends PubIdPlugin
  form/SriSettingsForm.inc.php
  classes/SriMetadataBuilder.inc.php
build/                package.php (tar.gz builder) + ojs-sandbox (Docker)
tests/                zero-dependency PHP unit tests + runner
docs/                 install / configuration / compatibility / security
```

## The three-rule contract with OJS

1. **The plugin never mints identifiers.** SRI identifiers are generated and
   registered server-side, atomically, by `POST /api/v1/register` —
   `identifierService.generateAndReserve()` on SRI-Backend. The plugin only
   computes the **suffix** (`suffix` field) and stores the returned `fullSri`.
2. **Registration is a best-effort side action.** Every outbound call is bounded
   by hard timeouts; hooks catch all exceptions; publishing never waits.
3. **All security invariants are enforced in the core.** TLS verification,
   timeouts, CSRF on management verbs, and escaping happen once, in the shared
   core + templates, and hold for every OJS version.

## Wire contract (SRI-Backend)

See the SRI-Backend OpenAPI (production-exposed `/api/docs.json`). Key endpoints:

| Endpoint | Used for |
|---|---|
| `POST /api/v1/register` (`X-SRI-API-Key`) | single registration (201: `{ data: { fullSri, status, recordId, qualityScore, qualityBadge } }`) |
| `POST /api/v1/register/bulk` (multipart CSV) | back-catalog (202: `{ data: { jobId, totalRows } }`) |
| `GET /api/v1/bulk-jobs/{id}` | bulk job polling |
| `GET /api/v1/metadata/{fullSri}` | status polling (public) |
| `PATCH /api/v1/metadata/{fullSri}` | re-deposit on edit (**backend currently JWT-gated** — see Known gaps) |

Payload field names map 1:1 to SRI-Backend's `RegistrationRequest` schema
(title, creators[], resourceType, publicationDate, targetUrl, abstract,
subjects[], language, publisher, license, funders[], relatedIdentifiers[],
issn, volume, issue, pages, suffix, prefix, year, source). Never invent field
names — regenerate docs from the backend's live OpenAPI when in doubt.

## Development loop

```bash
php scripts/sync-core.php   # mirror src/ -> plugin34 & plugin33 classes/core
php scripts/lint.php        # php -l across every .php file
php tests/run-tests.php     # unit tests (no dependencies)
php -d phar.readonly=0 build/package.php   # produce dist/*.tar.gz
```

## Adding a feature

1. Add/extend a unit test in `tests/` first (`run-tests.php` auto-discovers
   `*Test` classes extending `SriTestCase`).
2. Implement in `src/` (version-independent). Only touch the adapters when an
   OJS object API differs between 3.3 and 3.4/3.5.
3. Re-sync (`php scripts/sync-core.php`), lint, test, rebuild.
4. Update `locale/` keys in `plugin34` and copy to `plugin33`
   (`sync-core` copies code; locale files are per-adapter and are mirrored by
   `copy`), and keep `CHANGELOG.md` current.

## Security-focused diffing

Every PR touching the network path should re-verify: no
`CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST` disabled; hard timeouts present; CSRF
checked on management verbs; `|escape` on every dynamic value in templates.
See `docs/SECURITY.md`.

## Known gaps / follow-ups

- **Metadata re-deposit (`PATCH /api/v1/metadata/{fullSri}`) is JWT-only on the
  backend today.** API-key clients get a clear "update via dashboard" message.
  Backend follow-up (plan's open question): wire API-key auth onto the metadata
  update routes so re-deposit-on-edit works end-to-end for the plugin.
- **Galley-level SRI registration** is reserved (`enableRepresentationSri`) but
  not yet implemented end-to-end; article (publication) registration is the
  supported unit.
- Per-key rate-limit scaling with account quota is a backend config follow-up
  (plan Phase 1 #3); the plugin already emits only one call per publish and a
  poll only on demand.
- Icebox: the "harvest-to-register" OAI-PMH backfill bridge (onboarding without
  any plugin install).
