# SRI-Plugin OJS Sandbox

Local Docker sandbox for testing SRI-Plugin against real OJS 3.3 and 3.5
instances **without** touching production SRI data or quota.

It stands up:

| Service | Image | Port | Purpose |
|---|---|---|---|
| `db` | mariadb:10.11 | 3306 | Shared OJS database |
| `ojs33` | php:8.1-apache | 8083 | OJS 3.3 (legacy adapter target) |
| `ojs35` | php:8.1-apache | 8085 | OJS 3.5 (namespaced adapter target) |

Each OJS service mounts `./ojs/plugins` (read-only) so you can drop the built
plugin tarballs from `dist/` into it and upload them from the OJS browser UI —
the same flow a real journal manager uses.

> OJS is always finished via its browser installer (like any PKP install). The
> sandbox only skips the manual webserver/database setup, not the one-time
> journal configuration step.

## Prerequisites

- Docker (with the Compose plugin) and a few GB of disk.
- The plugin packages built:

  ```bash
  php -d phar.readonly=0 build/package.php
  ```

## Quick start

```bash
cd build/ojs-sandbox
docker compose up -d --build
```

Then complete each instance in the browser:

1. **OJS 3.5** — http://localhost:8085
   - Run the OJS installer (choose `MySQL`/`MariaDB`; host = `db`, database =
     `ojs35`, user/password from `docker-compose.yml`).
   - Settings → Website → Plugins → **Upload a new plugin** →
     `dist/sri-pubid-3.5.tar.gz`.
   - Enable **SRI-Plugin** under *Public Identifier Plugins*.
2. **OJS 3.3** — http://localhost:8083 (same, upload
   `dist/sri-pubid-3.3.tar.gz`).

Point the plugin at your sandbox **SRI API** (a test-mode `sriBaseUrl`, never
production) and paste a `identifier:register`-scoped API key created for the
sandbox account. Run through the [acceptance checklist](../docs/CONFIGURATION.md#acceptance-checklist).

## What it does NOT do

- It does not bundle an SRI-Backend sandbox instance. Point `sriBaseUrl` at a
  test/staging deployment of SRI-Backend (or the `SRI_BACKEND_SANDBOX_URL`
  provided to you) so no production quota or partner data is touched.
- It does not automate the OJS browser installer or the plugin upload — PKP
  deliberately keeps those interactive.

## Files

```
docker-compose.yml   # db + ojs33 + ojs35
ojs/Dockerfile       # php:8.1-apache + extensions; mounts OJS source
ojs/install-ojs.sh   # downloads + extracts an OJS release into the webroot
ojs/plugins/         # host folder: drop built tarballs here (mounted into each OJS)
```

`ojs/install-ojs.sh` defaults to OJS 3.5; set `OJS_VERSION` when building an
image (see `docker-compose.yml` build args).
