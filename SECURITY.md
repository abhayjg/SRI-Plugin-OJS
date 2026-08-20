# Security

SRI-Plugin is a client of the SRI registration API. It must never weaken the security of the OJS
installation it runs inside, and it must never exfiltrate or mishandle credentials.

## Design principles

1. **TLS is always verified.** Every outbound HTTP call sets `CURLOPT_SSL_VERIFYPEER` and
   `CURLOPT_SSL_VERIFYHOST` to the verified default (and fails loudly if curl reports a TLS
   error). We deliberately never expose a "skip TLS" option — the known PHP courtesy-bug
   (`ssl_verifypeer=false "to make it work" during dev`) is a regression we refuse to ship.

2. **Bounded timeouts.** Every outbound call has a hard connect timeout (default 10 s) and a
   total read timeout (default 30 s). A slow, misconfigured, or unreachable SRI endpoint can
   therefore never hang the publish action.

3. **Best-effort, never a blocker.** Registration runs after the article is published; any API
   failure (network, auth, membership/quota/prefix gate) is captured, surfaced as a status +
   reason in OJS, and leaves the article fully published. See `docs/CONFIGURATION.md` for the
   exact mapping of HTTP status/error codes to the reasons shown in OJS.

4. **CSRF everywhere.** The settings form uses `FormValidatorCSRF`; every plugin management
   action (Register now, Refresh status, Attach existing SRI, Register Back Catalog, Clear)
   calls `$request->checkCSRF()` before doing anything. Without a valid token the action is
   rejected, so a forged settings/action submission cannot quietly repoint the configured API
   key or base URL.

5. **Output escaping.** Every dynamic value rendered in a Smarty template — the SRI value,
   status text, and especially any API-controlled error message — is escaped with
   `|escape:"html"` (or `{$var|escape}`), preventing reflected XSS through API-controlled
   error strings. We never use `{verbatim}` around API data or raw HTML from the API.

6. **Least privilege for the API key.** The plugin only ever needs to register identifiers and
   read/verify status. Create a scoped SRI API key with `identifier:register` (optionally also
   `identifier:read` for status polling) — NOT `admin`. If an OJS server is compromised, a
   leaked key can then only register SRIs, nothing else.

7. **Credential storage.** The API key is stored in OJS's own settings store, the same shared
   mechanism every plugin uses. We can't fix OJS-wide settings storage (PKP's own DataCite
   plugin documents storing its password in plain text). The mitigations are therefore the
   narrow scope above plus documented rotation: SRI-Backend supports key revocation/rotation;
   on any suspicion of compromise, rotate the key in the SRI dashboard and re-paste it.

8. **No unsafe URL handling.** The target URL sent to the SRI API is built from the article's
   own landing page URL (OJS-generated) and the configured base URL — the plugin never fetches
   attacker-controlled URLs itself. SSRF protection for registered `targetUrl` values is
   enforced on the SRI backend at registration time (Phase 1 of the plan).

## Reporting a vulnerability

Please report security issues privately to the project maintainers rather than the public issue
tracker. Do **not** open a public issue for a live vulnerability.

## Review checklist applied to this codebase

- [x] No `CURLOPT_SSL_VERIFYPEER` / `CURLOPT_SSL_VERIFYHOST` disabled anywhere.
- [x] Hard timeouts bound every outbound call.
- [x] All plugin management actions require a valid CSRF token.
- [x] All dynamic Smarty output is escaped.
- [x] API key used with the narrowest scope supported by the backend.
- [x] No secrets committed; API key lives only in OJS settings (env overridable in tests).
