# SRI-Plugin

SRI-Plugin is an Open Journal Systems (OJS) plugin that lets a journal automatically register
[Scitekhub Research Identifiers](https://github.com/anomalyco/opencode) (SRI) for its published
articles — the same way publishers already expect a **Crossref** or **DataCite** DOI plugin to
behave: identifiers are minted server-side by the SRI API in the same call that registers the
record, and the article still publishes normally even if SRI registration fails.

It is a `pubIds`-category plugin that extends PKP's own `PKPPubIdPlugin` — the same base class the
official DOI plugin extends and the same one the shipped third-party **ARK**, **PURL** and **URN**
plugins extend.

> **Plugin name.** This project and plugin are named **SRI-Plugin**. Internal PHP class names and
> OJS identifiers follow CPK plugin conventions (`sri`, `SriPubIdPlugin`) because OJS requires
> folder/class naming rules — the identifier type, the display name and everything a publisher sees
> is **SRI-Plugin**.

---

## What it does

| Feature | Detail |
|---|---|
| Automatic registration on publish | Hooked into OJS's publish event; calls `POST /api/v1/register` with `X-SRI-API-Key` |
| Manual "Register now" | Per-article action in OJS when auto-registration is off, failed, or needs a retry |
| Status display | Not registered / Pending SRI review / Active / Failed (with the exact reason) |
| Account connection status | Settings readout for membership, SRI quota, prefix quota, and configured-prefix availability |
| Re-deposit on edit | Offers to push metadata updates via `PATCH /api/v1/metadata/{fullSri}` |
| Back-catalog registration | "Register Back Catalog" screen in the plugin settings |
| Manual SRI attach | Attach an already-registered SRI without triggering a new registration |
| Citation meta tag | Emits `<meta name="citation_sri" content="...">` on the article page for indexers |
| Suffix generation | Default `%j.%a`, custom token patterns, or per-article manual override |

SRI-Plugin is a **thin client** to the SRI registration API. It introduces no new business rules:
membership expiry, quota, prefix and approval-state behaviour are all enforced by the SRI backend
and merely surfaced (with friendly reasons) inside OJS. Registration is always a best-effort side
action — it never blocks an article from publishing.

## Supported OJS versions

| OJS | Support | Package |
|---|---|---|
| 3.5.x | ✅ Primary (LTS) | `dist/sri-pubid-3.5.tar.gz` |
| 3.4.x | ✅ Primary | `dist/sri-pubid-3.4.tar.gz` |
| 3.3.x | ✅ Supported (LTS; min. 3.3.0-18) | `dist/sri-pubid-3.3.tar.gz` |

See [docs/VERSION-COMPATIBILITY.md](docs/VERSION-COMPATIBILITY.md) for the per-version adapter
design, why the minimum supported 3.3 patch is `3.3.0-18`, and how each version's plugin API
differs.

## Repo layout

```
src/                 Shared, version-independent core (namespaced SRI\Plugin)
plugin35/            OJS 3.5 installable plugin (modern namespaced adapter)
plugin34/            OJS 3.4 installable plugin (namespaced adapter)
plugin33/            OJS 3.3 installable plugin (legacy adapter)
build/               Packaging script (tar.gz builder) + OJS sandbox (Docker)
tests/               PHP unit tests (zero-dependency runner; phpunit-ready)
docs/                Install, configuration, compatibility, security, developer guides
dist/                (generated) install-ready .tar.gz packages
```

## Quick start (installation)

1. Build the install packages:

   ```bash
   php build/package.php
   ```

   This copies the shared core into each adapter and produces
   `dist/sri-pubid-{3.3,3.4,3.5}.tar.gz`.

2. In OJS **Settings → Website → Plugins**, use **Upload a new plugin** and install the package
   matching your OJS version.

3. Enable the plugin under **Public Identifier Plugins**.

4. In the SRI dashboard, create a scoped API key (`identifier:register`), then open the SRI-Plugin
   settings in OJS and paste the key, the SRI API base URL, and your numeric SRI prefix.

Full install + configuration walkthrough: [docs/INSTALL.md](docs/INSTALL.md).
Security design: [docs/SECURITY.md](docs/SECURITY.md).

## Development

```bash
php scripts/sync-core.php   # mirror src/ core into plugin34/plugin33 classes/core (after editing src/)
php scripts/lint.php        # php -l across every .php file
php tests/run-tests.php     # zero-dependency unit tests (no composer needed)
php -d phar.readonly=0 build/package.php   # rebuild dist/sri-pubid-{3.3,3.4,3.5}.tar.gz
```

No external dependencies are required to build or test — plain PHP ≥ 8.1
suffices. `composer.json` is provided for autoload convenience and `composer
test` / `composer lint` / `composer build` shortcuts.

## License

GNU GPL v2. See [LICENSE](LICENSE). (GPL matches OJS's plugin ecosystem; the plugin talks to SRI
over a documented HTTPS JSON API.)
