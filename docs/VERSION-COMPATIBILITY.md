# OJS version compatibility

SRI-Plugin is built as **one shared core + a thin per-version adapter** (the
plan's explicitly recommended architecture). The shared core (`src/`, namespace
`SRI\Plugin`) — the API client, metadata-mapping, suffix generation, status
resolution and orchestration — is 100% OJS-free and version-independent. Only
the thin adapter (hook registration, class namespacing, OJS object APIs) differs
per version.

## Why the adapters differ (PKP facts, checked against the plan)

- **OJS 3.3** uses the legacy, non-namespaced `plugins/**` class layout and the
  `PubIdPlugin`/`PKPPubIdPlugin` classes via `import('classes.plugins.PubIdPlugin')`.
- **OJS 3.4** moved class declarations to namespaces (`PKP\plugins\PKPPubIdPlugin`),
  and **moved DOI handling out of the pubIds plugin category into core**.
  `PKPPubIdPlugin` remains the correct base for *non-DOI* identifiers such as SRI.
- **OJS 3.5** made namespaced plugin classes mandatory, removed most
  `PKPString`/Stringy helpers, changed `Router::url`/`Dispatcher::url` to require
  array paths, and removed `fatalError()` in favour of exceptions. A plugin
  written against 3.3 idioms does not run unmodified on 3.5.

This is the same situation PKP's own plugin ecosystem lives with: plugins
maintain per-major-version branches (the ARK plugin published a dedicated 3.5
release). We ship three packages from one repo instead.

## Package matrix

| OJS | Package | Adapter | Base class | Locale dir |
|---|---|---|---|---|
| 3.3.x (≥ 3.3.0-18) | `sri-pubid-3.3.tar.gz` | `plugin33` | legacy `PubIdPlugin` | `locale/en_US/` |
| 3.4.x | `sri-pubid-3.4.tar.gz` | `plugin34` | `PKP\plugins\PKPPubIdPlugin` | `locale/en_US/` |
| 3.5.x | `sri-pubid-3.5.tar.gz` | `plugin34` | `PKP\plugins\PKPPubIdPlugin` | `locale/en/` + `en_US/` |

The 3.4 and 3.5 packages share the same namespaced adapter and identical core;
they differ only in the locale-layout and (ostensibly) patch scope described
above, which is why they're built separately.

## LTS guidance

- **3.5.x** — current default for new installs; primary target.
- **3.3.x** — LTS, still security-patched; supported to at least the plan's
  recommended minimum **3.3.0-18**.
- **3.4.x** — non-LTS bridge release; supported but best-effort.

## Shared core pinning

The core is copied into each package at build time (`build/package.php` and
`scripts/sync-core.php`). Always rebuild before uploading:

```bash
php -d phar.readonly=0 build/package.php
```

If you edit `src/`, run `php scripts/sync-core.php` so a working-tree extract
stays current too.
