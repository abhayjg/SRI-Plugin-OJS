# Installation

SRI-Plugin is distributed as `.tar.gz` packages built from this repository
(`dist/sri-pubid-{3.3,3.4,3.5}.tar.gz`). Use the package matching your OJS
version.

## 0. Pick a package

| OJS | Package | Notes |
|---|---|---|
| 3.5.x | `sri-pubid-3.5.tar.gz` | Primary support (LTS) |
| 3.4.x | `sri-pubid-3.4.tar.gz` | Primary support |
| 3.3.x (≥ 3.3.0-18) | `sri-pubid-3.3.tar.gz` | LTS; see security note below |

> **Minimum supported OJS 3.3 patch: 3.3.0-18.** Earlier 3.3.x releases had
> disclosed vulnerabilities. A plugin cannot compensate for an unpatched host —
> keep OJS itself updated (PKP's shared-responsibility model).

## 1. Build the packages (developers) or download them

```bash
php -d phar.readonly=0 build/package.php
```

This copies the shared core into each adapter and writes the three tarballs to
`dist/`.

## 2. Upload the plugin

In OJS:

1. **Settings → Website → Plugins**.
2. Click **Upload a new plugin**.
3. Choose `dist/sri-pubid-3.5.tar.gz` (or your version's package) and upload.
4. On the **Installed Plugins** list, expand **Public Identifier Plugins** and
   enable **SRI-Plugin** (the enabled checkbox in the plugin's row).

OJS places the plugin at `plugins/pubIds/sri/`. The identifier type shown to
editors/readers is **SRI-Plugin**.

## 3. Configure (one-time)

Open SRI-Plugin's **Settings** (gear icon).

You need three things from the SRI dashboard:

- **API base URL** — the SRI API root ending in `/api/v1` (use a **sandbox /
  test** environment for development; never production quota).
- **API key** — create a key scoped to `identifier:register` only (least
  privilege). If the key is ever compromised, rotate/revoke it in SRI and
  re-paste it here.
- **SRI prefix** — your numeric prefix (e.g. `1001`).

Then choose the registration behaviour you want (auto-register on publish,
suffix pattern, whether the article's companion DOI is included as a related
identifier).

Full walkthrough: [CONFIGURATION.md](CONFIGURATION.md).

## 4. Please note

- Registration is **best-effort and never blocks publishing**. If SRI is down
  or the account has a membership/quota problem, the article still publishes;
  the plugin just records the failed attempt with a reason you can retry.
- The `sri_prefix` must be active and owned by the account that owns the API
  key, or registrations are rejected by the SRI API (surfaced in OJS).

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Plugin doesn't appear after upload | Wrong OJS version of the package; re-check Compatibility notes |
| "Shared core missing" in OJS logs | The package was created before `build/package.php` injected `classes/core` — rebuild and re-upload |
| Registrations all fail with a reason | Check the API base URL (`/api/v1`), key scope, prefix ownership, and that the SRI account is active/in-quota |
| Publishing feels slow | Lower the connect/request timeouts in settings (defaults are 10 s / 30 s) |
