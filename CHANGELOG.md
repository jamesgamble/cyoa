# Changelog

All notable, public-safe changes to Branching Paths. Newest release first.

## 0.12.0 — 2026-07-19

### Added
- SMTP configuration and email queue migration `private/migrations/0003_smtp_and_email_queue.sql` adding `user_roles` (with CHECK on `admin`/`moderator` and unique `(user_id, role)`), a single-row `smtp_settings` table with CHECK constraints on `encryption`, `retry_limit`, and `batch_size`, an `email_templates` table seeded with `verify_email`, `welcome`, `admin_approved`, and `operator_test`, and an `email_queue` outbox with CHECK-constrained `status` (`pending`/`sending`/`sent`/`failed`/`cancelled`), `attempts`, `next_attempt_at`, `claimed_at`, `sent_at`, and readiness / status indexes.
- `App\Encryption` service reading a 32-byte application key from `private/keys/app.key` (created on first use, mode 0600) and providing authenticated symmetric encryption via libsodium `crypto_secretbox` when available, falling back to OpenSSL AES-256-GCM. The key file lives outside SQLite so a database dump alone cannot recover any password.
- `App\SmtpSettingsRepository` with `load()`, `loadForApi()` (redacts the password to a sentinel), and `save()` that validates every field, only rewrites the ciphertext when the sentinel is missing, and encrypts the plaintext before writing.
- `App\EmailTemplateRepository` rendering `{placeholder}` templates with HTML escaping for the HTML body and pass-through for the text body; unknown placeholders are preserved so gaps are visible.
- `App\EmailQueueRepository` with `enqueue`, `claimBatch` (atomic transactional flip to `sending`), `markSent`, `markFailedOrRetry` (exponential backoff plus jitter, capped at one hour), `recoverStale` (returns abandoned `sending` rows to `pending`), `cancel`, `recent`, and `counts`.
- `App\Mailer\MailerTransport` interface with a bundled `App\Mailer\SmtpTransport` implementing EHLO / optional STARTTLS / AUTH LOGIN / MAIL FROM / RCPT TO / DATA against real servers, and `App\Mailer\MockTransport` used by every test.
- `App\EmailQueueService` orchestrating the worker pass: recover stale, claim batch, render template, call transport, and record outcome. Every persisted error is normalised through `EmailQueueService::redact()` so raw provider replies never reach `email_queue.last_error`.
- `App\AdminSession` — HMAC-SHA-256 signed cookies (`bp_admin`, HttpOnly, SameSite=Strict, Secure when HTTPS) keyed off a random 32-byte secret at `private/keys/session.key`. Every authenticated request re-checks the `user_roles` row so admin revoke is instant.
- HTTP endpoints on `public/api/index.php`: `POST /api/master/login`, `POST /api/master/logout`, `GET /api/master/session`, `GET/PUT /api/master/settings/email`, `POST /api/master/settings/email/test`, `GET /api/master/email-queue`, and `POST /api/master/email-queue/{id}/cancel`. All mutating routes require a fresh CSRF token; every route beyond login also requires an administrator session.
- CLI script `scripts/bootstrap-admin.php` creating or promoting the initial administrator account. Accepts a password on the command line or from stdin, refuses passwords shorter than twelve characters, and holds the write lock while it inserts or updates.
- CLI script `scripts/process-email-queue.php` — the email worker. Guarded by a dedicated `flock()` lock file at `private/locks/email-worker.lock` so overlapping cron ticks never spawn duplicate workers. Reports claimed/sent/retried/failed/recovered on stdout.
- React pages for `/master/login`, `/master`, `/master/settings/email`, and `/master/email-queue` (`frontend/src/pages/protected.tsx`) driving the new API. The password field displays only a placeholder when a password is on file; typing a value rotates it, clicking "Clear stored password" removes it.
- Frontend API client additions in `frontend/src/lib/apiClient.ts`: `fetchMasterSession`, `masterLogin`, `masterLogout`, `fetchSmtpSettings`, `saveSmtpSettings`, `sendTestEmail`, `fetchEmailQueue`, and `cancelQueuedMessage`, together with a `PASSWORD_UNCHANGED` sentinel constant.
- Contextual help topics for email settings, the email queue, and administrator sign-in.
- Focused PHP tests (`tests/php/email_queue_test.php`): password ciphertext never contains plaintext, redaction round-trip through `loadForApi`, unchanged-password preserves the stored value, validation rejects invalid input, `claimBatch` is bounded and ordering-stable, retry uses backoff and hits the final-fail branch, `recoverStale` reclaims abandoned `sending` rows, cancel only affects `pending`, template render substitutes and HTML-escapes, unknown placeholders survive, worker happy path sends and marks `sent`, worker retries a transient failure, worker redacts a raw provider reply, worker is a no-op when SMTP is disabled, redactor rejects whitespace and symbols, and admin session login requires the `admin` role.

### Security
- SMTP passwords are encrypted at rest with an application key kept outside SQLite; a database dump alone cannot recover them. The API always returns a redaction sentinel; only the server-side `load()` decrypts.
- The queue's `last_error` column is populated exclusively from an allow-list of `smtp_\d+`-style tokens through `EmailQueueService::redact()`. Recipient addresses, credentials, and free-form provider strings can never leak into an operator's browser through the queue view.
- The email worker uses a dedicated file lock separate from the database write lock, so it cannot stall reads while sending, and a second cron tick backs off immediately instead of spawning a duplicate worker.
- Administrator cookies are HMAC-signed with a random secret and validated with `hash_equals`; role revocation invalidates the cookie on the very next request through a `user_roles` re-check.
- The test-email endpoint queues the operator-only `operator_test` template — it never accepts an arbitrary body from the browser, so the endpoint cannot be turned into an open relay.
- Tests exclusively use `MockTransport`; no test path can accidentally open a socket to a real SMTP server.

## 0.11.0 — 2026-07-19



### Added
- Registration migration `private/migrations/0002_registration.sql` extending `users` with `email`, `email_normalized`, `username_normalized`, `password_hash`, `password_algo` (CHECK `argon2id` or `bcrypt`), `status` (CHECK `pending_verification` / `active` / `suspended` / `deleted`), `terms_accepted_at`, `email_verified_at`, `approved_at`, and `updated_at`; unique partial indexes on the normalized columns; a `settings` key/value table seeded with `registration_enabled`, `minimum_password_length`, `registrations_per_ip_per_hour`, `require_email_verification`, and `require_admin_approval`; and a `registration_attempts` ledger indexed by `(ip, occurred_at)`.
- `App\PasswordHasher` selects Argon2id when available and falls back to bcrypt; the chosen algorithm is stored per row for future silent upgrades.
- `App\Csrf` double-submit cookie helper (`bp_csrf`, SameSite=Strict) issued by `GET /api/csrf-token` and verified on every unsafe request through a timing-safe compare.
- `App\RegistrationRateLimiter` records every attempt in `registration_attempts` and blocks further submissions from an IP once the configured hourly ceiling is reached.
- `App\SettingsRepository`, `App\UserRepository`, and `App\RegistrationService` orchestrating gate checks, validation, case-insensitive duplicate detection, and Argon2id/bcrypt hashing under the write lock.
- HTTP endpoints on `public/api/index.php`: `GET /api/csrf-token`, `GET /api/registration/settings`, and `POST /api/register`. Successful registrations respond `202 Accepted`; validation errors return `422` with per-field codes; disabled registration returns `403 registration_disabled`; missing CSRF returns `403 csrf_failed`; ceiling exceeded returns `429 rate_limited`; unexpected failures return `503 service_unavailable`.
- React registration page at `/register` (`frontend/src/pages/RegisterPage.tsx`) with client-side validation mirroring the server rules, a visually-hidden honeypot (`nickname_url`), and settings-driven copy for verification/approval flows.
- Frontend API client additions: `fetchRegistrationSettings`, `fetchCsrfToken`, and `submitRegistration` with a normalized outcome discriminator.
- Focused PHP tests (`tests/php/registration_test.php`): happy path, case-insensitive duplicate email and username, missing fields, short password, password mismatch, missing terms, invalid email shape, invalid username characters, honeypot, missing CSRF, disabled registration, per-IP rate limit, `PasswordHasher` round-trip, `Csrf` double-submit and short-token gate, `UserRepository` normalisation, and rate-limiter counting.
- Focused frontend tests (`frontend/src/__tests__/registration.test.tsx`): form skeleton, empty submission, terms enforcement, CSRF header and body payload on success, server 422 field surfacing, 429 banner, disabled state, and hidden honeypot.

### Security
- Registration responses are enumeration-safe: duplicate email, duplicate username, honeypot triggers, and fresh signups all return the same `202 accepted` payload so the endpoint cannot be used to probe existing accounts.
- Passwords are always hashed with Argon2id or bcrypt through `password_hash`; the raw value never touches disk or logs.
- The write lock spans the duplicate check and insert so a race cannot squeeze a second row through the unique-index gap.
- The honeypot silently records a rejection and returns the same accepted payload so a scripted attacker cannot detect the trap by response shape.
- The CSRF cookie is scoped to the site origin with `SameSite=Strict`; validation uses `hash_equals` and a strict length gate before comparison.

## 0.10.0 — 2026-07-19

### Added
- Initial public data model migration `private/migrations/0001_public_adventure_data.sql` creating `users`, `adventures`, `scenes`, `choices`, and `content_warnings` with CHECK constraints on every state, visibility, scene-type, and content-rating column so invalid enum values are rejected at the schema level.
- Discovery and traversal indexes covering adventure state/visibility, genre, content rating, contribution state, updated-at ordering, per-adventure scene lookup by state and by scene number, choice edges by source and target, and content-warning ordering.
- Read-only `App\PublicRepository` implementing the v0.10.0 visibility rules: draft and suspended adventures are never returned; unlisted adventures are excluded from the Discover list but readable by slug; hidden and draft scenes are never returned; choices pointing to unpublished destinations are filtered out so the response cannot leak the existence of unpublished scenes.
- New read-only HTTP endpoints on `public/api/index.php`: `GET /api/adventures` (Discover list with `q`, `genre`, `rating`, `status`, `contributions`, `sort`), `GET /api/adventures/{slug}`, `GET /api/adventures/{slug}/outline`, and `GET /api/adventures/{slug}/scenes/{sceneId}`. Non-GET requests return `405`; unknown routes and private records return a generic `404`; database failures return `503` without leaking the underlying error.
- Development seed script `scripts/seed-dev-data.php` populating twelve fixture-parity adventures plus one suspended and one unlisted adventure, full scene / choice / ending content for *The Lantern Road* and *The Inn at the Crossing*, and a hidden scene inside *The Lantern Road* referenced by a choice so the visibility rules can be exercised end to end.
- Frontend API client `frontend/src/lib/apiClient.ts` with `fetchDiscover`, `fetchAdventure`, `fetchScene`, and `fetchOutline`; every call resolves to `null` on any error so callers fall back to fixtures.
- `Discover`, `Adventure`, and `Reader` pages progressively enhanced: initial render uses the typed fixtures so tests and offline reloads continue to work, and the API response replaces the fixture data when it arrives.

### Changed
- Fixtures under `frontend/src/data/discover.ts` and `frontend/src/data/scenes.ts` are now the offline fallback; live public pages prefer API data.

### Security
- Every response filters unpublished destinations at the query layer so a caller cannot enumerate hidden or draft scene slugs by inspecting choice targets on a published scene.
- HTTP responses never surface PDO error text, filesystem paths, or stack traces; failures are logged privately and returned as `503 service_unavailable` or `404 not_found`.

## 0.9.0 — 2026-07-19

### Added
- Plain-PHP application bootstrap under `app/`: `bootstrap.php` with a namespaced autoloader and JSON-only error and exception handlers that log full detail to `private/logs/php-error.log` and return an opaque payload to clients.
- Centralised configuration in `app/config/config.php` with environment-variable overrides for `APP_ENV`, `APP_URL`, `DATABASE_PATH`, and `WRITE_LOCK_PATH`, plus a memoised `bp_config()` helper.
- SQLite adapter `App\Database` that enforces WAL journaling, foreign-key checks, a 10-second `busy_timeout`, and `synchronous = NORMAL` on every connection.
- Bounded file-based `App\WriteLock` built on `flock()` with a configurable timeout, non-blocking polling, guaranteed release via `withLock()`, and a destructor safety net.
- Migration runner `App\Migrator` that tracks applied versions in a `schema_migrations` table and applies each `private/migrations/NNNN_*.sql` file inside its own transaction with automatic rollback on failure.
- Public HTTP entry point `public/api/index.php` exposing `GET /api/health` that returns only api status, database status, schema version, and application version, with `no-store` caching and `X-Content-Type-Options: nosniff`.
- CLI scripts: `scripts/initialize.php` (create private directories and warm the database), `scripts/migrate.php` (apply or list migrations, holding the write lock), and `scripts/system-check.php` (operator diagnostics for PHP version, extensions, filesystem, pragmas, and locking).
- React API pathing via `frontend/src/config/api.ts` (`API_BASE_URL`, `apiUrl`, `HEALTH_URL`) with `VITE_API_BASE_URL` override, and a Vite dev proxy from `/api` to the local PHP server.
- Help topic "Hosting and health" describing what the health endpoint reveals and the guarantee that it never exposes paths, secrets, stack traces, or raw SQL errors.
- PHP test harness at `tests/php/run.php` with focused tests covering pragma enforcement, foreign-key rejection, WAL survival across reopen, migration tracking and rollback, lock acquire/release, idempotent release, `withLock` release on throw, bounded acquire timeout under contention, health payload shape, and error-leak containment.

### Security
- The health endpoint whitelists response keys and swallows the underlying exception message on failure so filesystem paths, PDO error strings, and stack frames cannot be enumerated over HTTP.
- PHP `display_errors` is disabled at runtime; every uncaught error and exception is logged privately and returned to clients as a generic JSON error.

## 0.8.0 — 2026-07-19

### Added
- Help center at `/help` with a keyword search across every topic, an empty-result state, and a topic index listing the eleven canonical topics: Getting started, Discovering, Reading, Creating, Contributing, Accounts, Managing adventures, Moderation, Privacy and security, Changelog, and Common errors.
- Individual help topic pages at `/help/<slug>` with breadcrumbs, a one-sentence summary, full body, and a Related topics section that links to sibling topics.
- Persistent floating Help button rendered on every page by the global HelpProvider.
- Contextual help drawer that suggests the four most relevant topics for the current route, sign-in state, role, adventure state, and section, with a link out to the full help center.
- `useHelpContext` hook that lets pages push section, adventure-status, or setting overrides onto the contextual selection stack.
- Keyboard support in the drawer: initial focus on Close, Tab/Shift+Tab focus trap, Escape to close, and focus return to the element that opened it.
- Help URL sanitisation that strips query strings and hash fragments before any pathname appears in help copy or links so credentials and tokens can never leak through help.

### Changed
- Help topics rewritten to describe only implemented behaviour; planned features are labelled as such.
- Home, Discover, Adventure, and Reader pages register their section (and adventure status where known) with the contextual help selector.

## 0.7.0 — 2026-07-19

### Added
- Public story reader at `/adventure/:slug/read/:sceneId` rendered from typed scene fixtures.
- Scene view shows adventure title, optional chapter label, scene title, story body, scene number, and either numbered choices or an ending panel.
- Reader actions: Back one scene, Restart, Story map, Bookmark, Add a branch (when contributions are open), Report, and Help.
- Local reading history per adventure powers the Back button and the resume marker.
- Local bookmarks and discovered-ending counts stored per adventure in the browser.
- Explore another path action at endings that jumps back to the most recent branching scene in the reader's trail.
- Clear local progress control that erases reading history, bookmarks, and discovered endings for the adventure.
- Invalid-scene state when the URL references a scene that does not exist in the adventure, with a link back to the start.
- Empty-reader state when an adventure has no readable scenes yet.
- Help topic covering the reader and its local-only storage model.
- Story fixtures for The Lantern Road (seven scenes, three endings) and The Inn at the Crossing (two scenes, one ending).

### Changed
- Adventure landing page Read and Resume links now target `/adventure/:slug/read` and `/adventure/:slug/read/:sceneId` respectively.
- Local progress record extended with history, bookmarks, and `discoveredEndings` while remaining backwards-compatible with the v0.6.0 shape.

## 0.6.0 — 2026-07-19

### Added
- Public adventure landing page at `/adventure/:slug` rendered from typed fixtures.
- Title, creator, description, genre, content rating, content warnings, scene and ending counts, and last-updated line on the landing page.
- Adventure status field with four states: In progress, Complete, On hold, and Archived.
- Contribution status field with three states: Immediate publishing, Approval required, and Closed.
- Author-authored writing guidelines panel.
- Read from beginning, View story map, and Add a branch actions; Add a branch is hidden when contributions are closed.
- Resume reading action shown only when the visitor's browser has a local progress marker for the adventure.
- Follow placeholder visible to signed-in visitors, wired to a client-only toggle until the account system ships.
- Help topic covering the adventure page and the meaning of each status.
- Not-found state for `/adventure/:slug` when the slug does not resolve.

### Changed
- `AdventureSummary` gained optional `description`, `storyStatus`, `contributionState`, `contentWarnings`, and `writingGuidelines` fields; existing consumers continue to work.
- Discover fixtures enriched with the new fields so the same records back both Discover and the adventure landing page.

## 0.5.0 — 2026-07-19

### Added
- Public Discover page listing the current library of adventures from typed fixtures.
- Keyword search across title, author, and synopsis.
- Filters for genre, content rating, adventure status, and contribution status.
- Sorting by recently updated, newest, title, scene count, and ending count.
- Clear-filters control that resets the view to defaults.
- Mobile filter panel rendered in the shared dialog for narrow screens.
- Empty-result state with a clear-filters shortcut.
- URL-backed filter state so a filtered view can be bookmarked or shared.
- Contribution-availability tag and ending-count metadata on adventure cards.
- New help topic explaining how to browse the library.

### Changed
- `AdventureSummary` gained optional genre, content rating, ending count, contribution flag, and ISO timestamp fields; existing cards continue to render without them.

## 0.4.0 — 2026-07-19

### Added
- Public homepage with a concise introduction and three primary reader actions: read an adventure, create an adventure, or continue someone else's story.
- Featured adventure and recently-updated adventure fixtures rendered with the established literary card components.
- Three-step explanation of how the library works.
- Notice that reading published adventures does not require an account.
- Registration call to action alongside an existing-account shortcut.
- Summary of the community guidelines with a link to the full help topic.
- Directory of the primary public destinations: Discover, Create, Help, and Changelog.
- Expanded community-guidelines help topic to match the homepage summary.

### Changed
- Homepage stylesheet extends the existing design tokens; no new colors or typefaces were introduced.

## 0.3.0 — 2026-07-19

### Added
- Shared component library covering navigation, story, discovery, forms, management, and state.
- Literary masthead with an inline text wordmark and a fine editorial rule.
- Mobile navigation drawer with Escape-close, focus containment, and reduced tab order when hidden.
- Text-based wordmark component.
- Colophon-style footer with attribution line, editorial rule, and directory of secondary links.
- Story components: story page container, scene title, story body, numbered choice, choice list, and ending panel.
- Adventure card and featured adventure card for the discovery experience.
- Form components: search field, select, checkbox, radio group, toggle, text area with counter, password field with reveal, form section, step indicator, validation message, and inline help.
- Management components: management navigation rail, queue item, activity item, warning panel, danger zone, administrative table, and search-and-filter bar.
- Empty state and error state components with optional actions.
- Every documented component is exhibited on the internal design system preview.

### Changed
- Legacy Footer and PrimaryNav names now transparently render the new Colophon and Masthead components.
- Empty state and error state accept an optional action node.

### Security
- User-authored story content flows exclusively through the sanitizing story components. It cannot control font, color, width, alignment, background, border, or position; every string is escaped and rendered inside fixed presentational elements owned by the design system.

## 0.2.0 — 2026-07-19

### Added
- Complete design system tokens, three layout modes, and internal design system preview.
- Primitives: Button, Badge, Alert, Dialog, HelpDrawer, Panel.
- Subtle local paper texture and numbered choice styling.
- Design system documentation.

### Changed
- Layouts adopt their respective modes; header, footer, and navigation restyled.
- Accessibility: visible focus ring, reduced-motion and high-contrast support, increased text size setting, 44px minimum tap targets.

## 0.1.0 — 2026-07-19

### Added
- Initial project scaffolding, application shell, public routes, placeholder protected routes, layouts, state primitives, primary navigation, footer, version component, changelog data source and pages, documentation set, and initial tests.
