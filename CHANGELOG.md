# Changelog

All notable changes to SRI-Plugin are documented in this file. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.2.2] — 2026-08-26

Version changes only.

### Added
version number changes only.

### Fixed
Nothing fixed.

## [1.2.1] — 2026-08-26

Full OJS 3.5 compatibility, session CSRF hardening, Issue Identifiers tab fixes, intelligent dev/prod URL mapping, and clean SRI check-character slicing.

### Added

- **Dedicated OJS 3.5 Adapter (`plugin35/`)**: Complete native adapter engineered for OJS 3.5.x, aligning with OJS 3.5 class hierarchies, `APP\plugins\PubIdPlugin` method signatures, Smarty strict syntax requirements, and repository facades.
- **Intelligent Dev & Production URL Mapping**: Automatically maps backend API endpoints to correct frontend web applications across all environments:
  - Localhost dev: `http://localhost:4000/api/v1` or `http://127.0.0.1:4000/api/v1` -> `http://localhost:3000` (or `http://127.0.0.1:3000`).
  - Production: `https://api-sri.scitekhub.com/api/v1` or `https://api.scitekhub.com/api/v1` -> `https://sri.scitekhub.com`.
  - Configurable override: Custom `sriResolverUrl` setting takes precedence when explicitly defined.
- **Clean SRI Slicing**: Slices public resolving URLs to clean format (stripping `sri:` prefix and `+CHECKCHAR` suffix, e.g. `sri:2026.1002.wjpst.1-6+S` -> `https://sri.scitekhub.com/2026.1002.wjpst.1-6`).
- **Resolver URL Test Suite**: Added `tests/ResolverUrlTest.php` to continuously validate all dev/prod URL mappings and check-character slicing rules.

### Fixed

- **OJS 3.5 Session CSRF Fatal Error**: Fixed `BadMethodCallException: Method Illuminate\Session\Store::getCSRFToken does not exist` when opening the Issue Identifiers modal on OJS 3.5 by implementing safe polymorphic session inspection (`getSessionCsrfToken`).
- **Issue Identifiers Modal LinkAction Routing**: Corrected `getLinkActions` URL generation using standard OJS PubId component routes and unqualified plugin class name (`SriPubIdPlugin`), restoring full functionality to the Issue Edit Identifiers tab.
- **Template Safety Guards**: Added `{if $clearPubIdLinkActionSri}` guards in `identifierStatus.tpl` across all plugin versions to prevent unassigned template variable notices.
- **Undefined cURL Constant in ApiClient**: Replaced non-existent `CURLE_PEER_FAILED_VERIFICATION` constant in `src/ApiClient.php` with standard `CURLE_SSL_CACERT`.
- **OJS 3.3 Version Constant Reference**: Fixed `$plugin->getVersion()` fatal call in `plugin33/form/SriSettingsForm.inc.php` by referencing `$plugin::VERSION`.

## [1.2.0] — 2026-08-24

Compatibility, Issue Identifiers UI, and back-catalog modal improvements.

### Added

- **Issue Identifiers Article Summary**: Opening the **Issue Management > Identifiers** tab now renders a clean summary table displaying all articles assigned to that issue, their assigned SRI identifiers with clickable resolver links, and their live registration status badge (`Active`, `Pending`, `Not assigned`, etc.).
- **Issue-Level Clear Action**: Added `clearIssueObjectsPubIds` link action allowing editors to clear and re-mint local SRIs for all articles in an issue in one click.
- **Issue & SubmissionFile Object Support**: Registered `Issue` and `SubmissionFile` in `getPubObjectTypes()` and `getDAOs()` to prevent OJS `PKPPubIdPlugin::getPubObjectType()` assertion failures on PHP 8.3+.

### Fixed

- **Issue Publishing & Identifiers 500 Crash**: Fixed `AssertionError: assert(false)` in `PKPPubIdPlugin.php` when opening the *Publish Issue* modal or issue *Identifiers* tab by declaring `Issue` in `getPubObjectTypes()` and safely disabling issue-level SRI by default.
- **Publication Settings DAO Conflict**: Updated `setStoredPubId()` to directly use safe `updateOrInsert` across `publication_settings`, ensuring 100% independence from OJS core and eliminating `1062 Duplicate Entry` errors.
- **Register Back Catalog Modal CSRF & Query**:
  - Removed CSRF blocking on GET modal requests (`verb=bulk` and `verb=accountStatus`) while maintaining strict `{csrf}` protection on the form submission (`bulkRun`).
  - Fixed `Undefined constant APP\issue\Repository::ORDERBY_SEQUENCE` fatal error when querying journal issues for the back-catalog selector dropdown.
  - Added `translate=false` to the issue selector element in `bulkForm.tpl` to prevent dynamic issue names from triggering missing translation dictionary warnings.

## [1.0.6] — 2026-08-22

Connectivity and account visibility fixes.

### Fixed

- All plugin management URLs now explicitly target OJS's component router, so
  actions rendered inside the publication identifiers form no longer fall
  through to a page-router 404.
- Added a bounded, API-key-scoped account status endpoint and a server-side OJS
  settings readout for membership, quota, and prefix state. The API key never
  reaches browser JavaScript.
- Added request cancellation, stale-response protection, and cleanup for the
  settings status refresh handler.

## [1.0.4] — 2026-08-21

Settings form audit fixes — address all confirmed bugs from the settings-form audit
(`docs/SETTINGS-FORM-AUDIT-AND-FIX-PLAN.md`).

### Fixed

- **Base URL rejects localhost/private IPs** (`#2`): The `sriBaseUrl` field used OJS's built-in
  `FormValidatorUrl`, which attaches a jQuery Validate `url` CSS class that enforces a public-URL
  regex client-side — explicitly rejecting `localhost`, `127.0.0.1`, `10.x.x.x`, `192.168.x.x`,
  and any other private/loopback address. This blocked all local development and internal-network
  deployments before the form even submitted. Fixed by replacing `FormValidatorUrl` with
  `FormValidatorRegExp` using a permissive `scheme://host[:port]/path` pattern (consistent with
  how `sriPrefix` already uses its own custom regex validator). Also added a compile-time default
  (`DEFAULT_BASE_URL = 'https://api-sri.scitekhub.com/api/v1'`) so new journals see a working
  value instead of a blank required field.
- **Suffix pattern falsely required** (`#4`): Selecting "Default" or "Manual" suffix mode and
  saving still failed validation, demanding the custom-pattern field be filled in — even though
  that field is only meant to be required when "Custom pattern" is selected. Root cause: OJS
  core's `FormValidator` base class unconditionally adds a `required` CSS class to any field
  registered with type `'required'`, regardless of the PHP-side closure's actual logic; jQuery
  Validate then blocks submission client-side. Fixed by creating `SriSettingsFormHandler.js`
  (both adapters) — a custom form handler extending `AjaxFormHandler` that toggles the
  `required` class on `sriPublicationSuffixPattern` based on the selected suffix-mode radio,
  mirroring the conditional suffix handling shipped by OJS's own DOI plugin
  (`plugins/pubIds/doi/js/DOISettingsFormHandler.js`).
- **Galley checkbox does nothing** (`#1a`): The "Also register SRIs for galleys" checkbox was
  shipped ahead of the feature it controls — no hook, no code path ever reads
  `enableRepresentationSri` to actually register galley-level identifiers. The checkbox was
  hidden from the settings form to avoid misleading publishers. The setting is preserved in the
  database for forward compatibility when the feature is built.
- **Bulk registration not gated by article checkbox** (`#1b`): Unchecking "Register an SRI for
  each article" correctly prevented automatic registration on publish and hid the per-article
  status card, but the "Register back catalog" bulk tool had no relationship to
  `isObjectTypeEnabled` and would still happily register everything. Added
  `isObjectTypeEnabled('Publication', $contextId)` guard at the top of `actionBulkRun()` and
  `registerSubmission()` in both adapters, so every registration path respects the checkbox.

Fourth pass, found by inspection while preparing a user walkthrough of the settings form (not
a reported crash) — same live-install session.

### Fixed

- **UX**: Saving plugin settings displayed the raw `{"status":true,"content":"",...}` JSON
  response as a full page instead of closing the modal and showing a save confirmation.
  `templates/settingsForm.tpl` had no `pkpHandler` binding at all — every other OJS settings
  form (the shipped URN pubIds plugin, googleAnalytics, webFeed, and this plugin's own
  `bulkForm.tpl`) binds one, but this file was missing it entirely, so clicking Save did a plain
  browser form submission instead of an AJAX one. Fixed by binding
  `$.pkp.controllers.form.AjaxFormHandler` (the same class OJS's own `PKPPubIdPlugin` reference
  implementations use), matching the established pattern exactly.
- **Same bug, different form**: `templates/bulkForm.tpl` (both adapters) bound the plain
  `$.pkp.controllers.form.FormHandler` instead of `AjaxFormHandler`. `FormHandler` only handles
  client-side validation events — it does not perform the AJAX submit or process the returned
  JSONMessage at all, so "Register back catalog" would have hit the identical raw-JSON bug the
  first time anyone used it. Not yet reported (untested), found while auditing the settings-save
  fix for other instances of the same mistake. Fixed the same way.
- **Deeper, related issue**: the per-article "Register now" / "Refresh status" / "Attach
  existing SRI" controls in `templates/statusCard.tpl` had the same missing-binding problem, but
  a `<script>`-tag fix alone would not have worked there. That card is injected into the OJS
  3.4+ Vue-based publication identifiers form as raw HTML via `FieldHTML`'s `description` field
  (see `addPublicationFormFields`), and Vue's `v-html` — like any plain `innerHTML` assignment —
  never executes injected `<script>` tags; this is standard browser behavior, not an OJS quirk,
  so no jQuery handler can ever bind there and every click is a plain navigation. Fixed
  robustly instead of fighting the rendering context: `SriPubIdPlugin::manage()`'s dispatch for
  these three verbs now goes through a new `objectAction()` wrapper that detects a real AJAX
  call via the `X-Requested-With: XMLHttpRequest` header jQuery always sends. A genuine AJAX
  caller gets the existing `JSONMessage` unchanged; a plain navigation performs the action,
  raises a flash notification via `createTrivialNotification` (the same pattern OJS's own
  `PKPPubIdPlugin::manage()` uses for a settings save), and redirects to
  `workflow/access/{submissionId}` — the same URL pattern OJS core itself uses everywhere to
  return a user to a submission's workflow page — so a fresh page load re-renders the status
  card with the updated state instead of ever showing raw JSON. Not currently reachable through
  any 3.3 UI element (the Vue form doesn't exist there), but applied to both adapters for
  consistency and because `manage()`'s structure is otherwise identical.

## [1.0.2] — 2026-08-20

Third bug-fix pass from the same live-install testing session. Diagnosed with certainty this time
by reading the actual OJS instance's PHP error log directly (`php_errors.log`), which also
confirmed the 1.0.1 fixes below were genuinely effective — the errors they targeted stop
appearing in the log after that install, replaced by this new one.

### Fixed

- **Critical**: `templates/settingsForm.tpl` (both adapters) had `{fbvFormSection}` (no `list`
  attribute) wrapping the `sriIncludeDoi` checkbox `{fbvElement}`. OJS's `FormBuilderVocabulary`
  renders every checkbox/radio `fbvElement` as an `<li>`-wrapped list item regardless of the
  section's `list` setting, and deliberately self-checks for this: if the section's content
  starts with `<li>` but `list="true"` wasn't declared, it throws `Exception('FBV: list
  attribute not set on form section containing lists')` — an uncaught fatal, 500 on every
  attempt to open the settings form. Every *other* checkbox/radio section in the same template
  correctly declares `list="true"`; this one section was the sole miss. Confirmed directly
  against the live OJS install's own `lib/pkp/classes/form/FormBuilderVocabulary.php` (the exact
  code and line that throws), not inferred. Swept every template in both adapters for the same
  pattern (`fbvFormSection` containing a checkbox/radio `fbvElement` without `list="true"`) —
  this was the only occurrence. Fixed by adding `list="true"`.

## [1.0.1] — 2026-08-20

Bug-fix release, found during real install testing against a local OJS 3.4 instance. Version
bumped from 1.0.0 (which never actually shipped/installed anywhere) specifically so OJS's plugin
upload treats this as an upgrade and actually replaces the on-disk files — installing over an
existing 1.0.0.0 with no version change is not guaranteed to do that.

### Fixed

- **Critical**: `addPublicationFormFields()` (the `Form::config::before` hook callback, in both
  the 3.4/3.5 and 3.3 adapters) was typed to receive `array $args`, but pkp-lib fires this
  specific hook via the modern `Hook::run()` convention (`Hook::run('Form::config::before',
  [$this])`), which unpacks the args array into positional parameters — the callback actually
  receives the `FormComponent` object directly as the 2nd parameter. This threw a `TypeError` on
  every Vue-based form OJS rendered anywhere in the backend (settings, announcements, review
  forms, context forms, etc.) while the plugin was enabled, which is what crashed the entire
  admin/settings area (not just this plugin's own settings screen) after install. Root-caused by
  reading pkp-lib's actual `Hook.php`/`FormComponent.php` source; the plugin's other two hooks
  (`Publication::publish`, `ArticleHandler::view`) were verified to use the legacy `Hook::call()`
  convention (args passed as a single array) and were already correct. Fixed by accepting the
  form object directly instead of `array $args`.
- `getResolvingURL()` used `ltrim($pubId, 'sri:')`, which strips a *character class*
  (`s`/`r`/`i`/`:`) rather than the literal `sri:` prefix. Always safe in practice today (a valid
  SRI always has a digit immediately after `sri:`), but fragile; replaced with an explicit
  prefix check.
- **Critical (3.4/3.5 adapter only)**: `templates/settingsForm.tpl` built the form's `action` URL
  with `{url router=$smarty.const.ROUTE_COMPONENT ...}`, which requires `ROUTE_COMPONENT` to be
  registered as a bare global PHP constant. In pkp-lib's namespaced core, that global alias is
  only backfilled `if (!PKP_STRICT_MODE)`; when strict mode is on, referencing it throws an
  uncaught `Error: Undefined constant "ROUTE_COMPONENT"` the instant the settings form template
  renders — a 500 every time the plugin's own settings gear icon is opened, while the rest of
  OJS (which doesn't hit this code path) works fine. Confirmed by diffing directly against
  `plugins/pubIds/urn/templates/settingsForm.tpl` in the official `pkp/ojs` `stable-3_4_0`
  source, which uses the namespaced class-constant form (`\PKP\core\PKPApplication::ROUTE_COMPONENT`)
  precisely to avoid this. Fixed by switching to the same namespaced form in the 3.4/3.5 adapter.
  The OJS 3.3 adapter's template is intentionally left unchanged — `ROUTE_COMPONENT` is an
  unconditional legacy `define()` there (pre-namespace core), and `PKP\core\PKPApplication` does
  not exist as a class on 3.3, so applying the same change there would break it instead.

## [1.0.0] — 2026-08-19

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
