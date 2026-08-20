# Configuration

## Settings reference

| Setting | Default | Meaning |
|---|---|---|
| SRI API base URL | — | Root of the SRI REST API, ending `/api/v1`. Use a sandbox for testing. |
| SRI API key | — | `X-SRI-API-Key` credential. Create **scoped** (`identifier:register`) in the SRI dashboard. |
| SRI prefix | — | Numeric prefix (e.g. `1001`); part of every registered identifier. |
| SRI resolver URL (optional) | derived from base URL | Used to build clickable "Resolve" links. |
| Register article (publication) | on | Enables SRI-Plugin identifiers for articles at the publication level. |
| Also register galleys | off | (Reserved; article-level registration is the default and recommended scope.) |
| Register automatically on publish | on | Hook into the OJS publish event. Best-effort — publishing never waits. |
| Suffix generation | `default` ( `%j.%a` ) | `default`, `pattern`, or `manual`. |
| Article suffix pattern | — | Token pattern when mode = `pattern` (see below). |
| Include companion DOI | on | Adds the article's DOI as a related identifier (`relationType: IsIdenticalTo`) when the journal also assigns DOIs. |
| Fallback publisher | — | Overrides the journal name sent as SRI `publisher`. |
| Connect / request timeout | 10 s / 30 s | Hard bounds on every outbound call. |

## Suffix tokens

Same vocabulary as OJS's own DOI suffix patterns — zero new concepts for
journal managers who have configured DOI before:

| Token | Meaning |
|---|---|
| `%j` | Journal acronym/initials |
| `%v` | Volume |
| `%i` | Issue |
| `%Y` | 4-digit year |
| `%y` | 2-digit year |
| `%a` / `%x` | Article (submission) id |
| `%g` | Galley id |
| `%f` | File id |
| `%p` | First page |
| `%%` | Literal `%` (stripped from the final suffix — `%` is not a valid suffix char) |

The suffix is sent as the existing `suffix` field of `POST /api/v1/register` —
SRI identifiers are minted **server-side** by the API in the same call that
registers the record. Colliding suffixes return `409`; the plugin automatically
retries with a disambiguator (`suffix-2`, `suffix-3`, …).

## Registration flow and gates

SRI-Plugin adds no business rules. Every registration is checked by the SRI
backend (`partnerGuard` + `registrationService`). Failures never block the
article — they surface in OJS with a readable reason:

| API response | What OJS shows | Publish? |
|---|---|---|
| 401 `API_KEY_INVALID` | "Reconnect your SRI API key" | Yes |
| 403 `ACCOUNT_SUSPENDED` | "Account suspended — contact SRI support" | Yes |
| 402 `ACCOUNT_EXPIRED` | "Your SRI membership has expired — renew to register" | Yes |
| 402 `QUOTA_EXCEEDED` / `NO_QUOTA` | "Quota exceeded — upgrade your plan" | Yes |
| 403/404 `PREFIX_INACTIVE` / `PREFIX_NOT_FOUND` | "Prefix not set up — contact SRI support" | Yes |
| 201 `status: ACTIVE` | SRI shown live on the article page (resolves) | Yes, fully registered |
| 201 `status: PENDING_REVIEW` | "Pending SRI review" badge | Yes, pending |

Because the membership gate is evaluated **fresh on every registration call**
(never cached against the key), a lapsed membership stops new identifiers
immediately with that same still-valid API key; renewal restores registrations
with no plugin change.

## Per-article actions

- **Register now** — manual registration (auto-registration off, failed, or retry).
- **Refresh status** — re-fetches `GET /api/v1/metadata/{fullSri}` to catch a
  PENDING_REVIEW → ACTIVE transition.
- **Attach existing SRI** — paste an already-registered SRI to attach it without
  triggering a new registration (e.g. migrated/backfilled records).
- **Clear SRI** — removes the locally stored identifier from OJS (does NOT
  withdraw/delete the identifier in the SRI registry).

## Back catalog

Settings → **Register back catalog**: pick an issue and the plugin registers
every published article in it that doesn't already have an SRI. It submits a
CSV to `POST /api/v1/register/bulk` (requires an API key whose user has the
`PUBLISHER_ADMIN` role) and reports the job id + counts; per-article status
appears on each submission.

## Acceptance checklist

Run this against your sandbox after configuring (sandbox SRI API + sandbox key):

1. Install the plugin on a fresh OJS 3.5 (and separately 3.3) instance.
2. Configure base URL / key / prefix — an invalid key is rejected up front with
   a clear message.
3. Publish an article with auto-registration on → SRI appears; publishing
   doesn't hang.
4. Click the SRI on the article page → resolves to the article.
5. View page source → `citation_sri` meta tag present.
6. Register 10 back-catalog articles from one issue → all 10 get SRIs (or a
   clear per-article reason).
7. Attach an existing SRI to an unpublished article → saved without a new call.
8. Simulate each gate failure (bad key, suspended/expired/quota/prefix) →
   article still publishes, reason shown in OJS.
9. Edit a registered article's metadata → the plugin offers re-deposit.
10. Point the base URL at an unreachable host and publish → bounded timeout,
    no hang.
11. Set `sriBaseUrl` to a sandbox SRI API → no production quota/data touched.
