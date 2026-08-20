# Changelog

All notable changes to SRI-Plugin are documented in this file. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased / 1.0.0] — 2026-08-19

Initial release — implements Phase 2 / Strategy A of the OJS Integration & Metadata Standards
plan (backed by the SRI-Backend API-key + metadata-hardening work from that plan's Phase 1).

### Added

- `pubIds`-category OJS plugin extending `PKPPubIdPlugin`, named **SRI-Plugin**
  (identifier type `sri`, display name SRI-Plugin).
- Automatic registration on publish via the `Publication::publish` hook.
- Per-article manual "Register now" / "Refresh status" / "Attach existing SRI" actions.
- Status model surfaced in OJS: Not registered / Pending SRI review / Active / Failed (+ reason).
- Re-deposit on metadata edit (best-effort `PATCH /api/v1/metadata/{fullSri}`).
- "Register Back Catalog" bulk registration screen (multipart CSV → `/register/bulk` → job poll).
- `citation_sri` meta tag injection on the article landing page.
- Suffix generation: default `%j.%a`, custom token patterns, manual override; `409` collision
  retry with a disambiguator.
- Settings: SRI API base URL, scoped API key, numeric prefix, auto-register toggle, object
  enablement (Publication / Galleys), suffix mode + patterns, fallback publisher/license.
- Shared version-independent core (`src/`) with per-version adapters for OJS 3.3 / 3.4 / 3.5.
- Install/configuration/security/developer documentation.
- Zero-dependency PHP unit tests for the shared core.
- Packaging script producing `dist/sri-pubid-{3.3,3.4,3.5}.tar.gz` and a Docker sandbox for
  local OJS 3.3 + 3.5 testing.

### Security

- Outbound calls always verify TLS (`CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST` on, never disabled).
- Hard connect/read timeouts on every outbound call — a slow/unreachable SRI API never blocks
  publishing.
- All plugin settings/action forms are CSRF-protected (OJS `FormValidatorCSRF` + `checkCSRF`).
- All dynamic output in Smarty templates is escaped (`|escape:"html"`).
- API key is stored in OJS plugin settings; recommend a narrow `identifier:register`-scoped key
  and rotation on compromise (SRI-Backend supports key rotation).
