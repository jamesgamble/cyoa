# Changelog

All notable, public-safe changes to Branching Paths. Newest release first.

## 0.26.0 — 2026-09-24

### Added
- Exports (`App\ExportService`): `GET /api/adventures/{slug}/moderation/export?format=json|text|print|play` for owners, editors, and administrators; export panel on the manage page.
- JSON schema version 1: schema version, export timestamp, metadata, content warnings, writing guidelines, published scenes, published choices, endings, safe attribution.
- Help topic: exporting an adventure.

### Security
- Exports are built from an allowlisted snapshot: no credentials, sessions, tokens, SMTP data, IP data, rate limits, reports, private notes, activity, or paths. Drafts, hidden scenes, and anonymous contributors are excluded.
- Plain text contains no HTML; HTML formats re-sanitize all story text. The standalone file sets a no-network Content Security Policy and has no external scripts or tracking.

## 0.25.0 — 2026-09-24

### Added
- Master administration (`App\MasterService`, migration `0014_master_administration.sql`): routes `/master`, `/master/users`, `/master/adventures`, `/master/submissions`, `/master/reports`, `/master/email-queue`, `/master/settings`, `/master/activity`.
- Platform roles user / moderator / administrator, read only from `user_roles`. A single permission table drives the API and the console navigation.
- API: `GET /api/master/{me,overview,users,adventures,submissions,reports,settings,activity}`; `POST /api/master/users/{id}/{role,suspend,restore,escalate,reset}`, `/adventures/{id}/{suspend,restore,transfer}`, `/reports/{id}/{dismiss,resolve,hide,restore}`, `/email-queue/{id}/{cancel,retry}`, `/activity/{id}/close`; `PUT /api/master/settings/{registration,anonymous,limits,maintenance}`.
- `platform_activity` log with a security flag; maintenance mode blocks writes for everyone except administrators.
- Help topic: master administration.
- Tests: `tests/php/master_test.php` — the complete authorization matrix across administrator, moderator, user and signed-out callers, plus reauthentication, safeguards and redaction.

### Changed
- Master sign-in now uses a normal account session plus a platform-role check, so moderators can sign in and reauthentication applies.

### Security
- Recent reauthentication required for role changes, ownership transfer, SMTP credential changes, maintenance mode, and destructive moderation (hiding content, suspending adventures or users).
- Suspending a user revokes all their sessions. Moderators never see email addresses or the security log. Reporter identities are never shown.

## 0.24.0 — 2026-09-24

### Added
- Revision history (`App\RevisionService`, migration `0013_revision_history.sql`): before a published adventure description, published writing guidelines, a published scene title or body, or a published scene's choice text changes, the previous value is stored with its editor and date.
- Manager endpoints: `GET /api/adventures/{slug}/moderation/revisions`, `GET …/revisions/{id}/compare[?with={id}]` (paragraph diff), `POST …/revisions/{id}/restore` (CSRF + write lock, transactional).
- Revision history panel on the manage page with compare and confirmed restore.
- Help topic: revision history.
- Tests: `tests/php/revision_test.php` and `frontend/src/__tests__/revisions.test.tsx` cover recording, retention, comparison, restoration, and authorization.

### Changed
- Retention keeps the most recent 20 revisions per field; restoring creates a new revision.

### Security
- Revisions return only story text and the editor's display name — no emails, credentials, sessions, SMTP data, or IP addresses. Only owners, editors, and administrators can read or restore them.

## 0.23.0 — 2026-09-18

### Added
- A public story outline at `/adventure/{slug}/map`: every published scene nested under the choice that leads to it, with collapsible branches and title search (`App\StoryMapService`, `GET /api/adventures/{slug}/map`).
- An owner story map on the manage page that also shows draft and hidden scenes, each with a plain label, plus the scenes nothing leads to.
- A story check listing missing destinations, published choices into unpublished scenes, empty scenes, duplicate sibling choices, published non-ending scenes with no choices, unreachable scenes, invalid parent relationships, and excessive depth (`GET /api/adventures/{slug}/moderation/validation`).
- Lazy loading for large stories: the map returns a bounded slice with `root` and `depth`, and a folded branch loads when it is opened.
- Keyboard and screen-reader support for the outline: a real tree with arrow, Home, and End navigation and announced depth.
- Help topics: the story outline and the story check.
- Tests: `tests/php/story_map_test.php` and `frontend/src/__tests__/story-map.test.tsx` cover visibility per audience, lazy loading, search, accessibility, and every integrity rule.

### Security
- The public map returns published scenes only; titles and choice labels of drafts and hidden scenes never appear in the payload.
- The owner map and the story check require a team role on the adventure; strangers and signed-out visitors are refused.

## 0.22.0 — 2026-09-18

### Added
- In-site notifications for submission received, submission approved, submission rejected, changes requested, submission resubmitted, review needed, collaborator invitation, ownership transfer, followed adventure updated, and account security events (`App\NotificationService`, migration `private/migrations/0012_notifications_and_follows.sql`).
- Inbox actions at `/account/inbox`: mark one read, mark all read, delete a routine notification, and clear every read routine notification (`POST|DELETE /api/notifications*`).
- Per-kind email preferences at `/account/notifications` (`GET|PUT /api/notifications/preferences`); a kind with no stored row falls back to its shipped default.
- Follows as a subscription separate from bookmarks: `GET|POST|DELETE /api/adventures/{slug}/follow`, a follow button on the adventure landing page, and a "Adventures you follow" list with unfollow in account settings.
- Aggregated email for routine followed-adventure updates: entries queue in `notification_digests` and `scripts/process-notification-digests.php` sends one bundled message per reader.
- Help topics: notifications, email preferences, and following versus bookmarks.
- Tests: `tests/php/notifications_test.php` and `frontend/src/__tests__/notifications.test.tsx` cover every kind, read and delete rules, per-kind preferences, follow and bookmark separation, and digest aggregation.

### Changed
- Collaboration notices (invitations, role changes, ownership) now route through the shared notification service and honour the recipient's email preferences.

### Security
- Security and recovery email cannot be switched off, and account security notices cannot be deleted from the inbox.
- Notifications are always scoped by recipient id; a user can neither read, mark, nor delete another account's items.
- Follower counts are never returned by any endpoint.

## 0.21.0 — 2026-09-18

### Added
- Server-side content reports on adventures, scenes, choices, and contributions with reasons for spam, harassment, hate or abuse, explicit content, personal information, broken branch, copyright, and other (`App\ReportService`, migration `private/migrations/0011_reports_and_content_warnings.sql`).
- Owner actions on a report: dismiss, hide the content, lock the scene, or escalate to the platform; administrators may mark a report platform-private.
- Content warnings per adventure (violence, strong language, horror, sexual themes, substance use, self-harm, other) returned with public adventure data.

### Security
- Reporting is honeypot-protected, rate-limited per reporter per hour, and folds duplicate reports of the same target within 24 hours.
- Content is never removed automatically by report count; every removal is an explicit human decision.

## 0.20.0 — 2026-09-13

### Added
- Adventure roles owner, editor, and reviewer with a full collaborator roster (`App\CollaborationService`, migration `private/migrations/0010_collaborators_and_ownership.sql`).
- Invitations by email to registered members: tokens are random, hashed at rest, expiring, single-use, and revocable (`/api/adventures/{slug}/collaborators/invitations`).
- Invitations arrive both in the recipient's on-site inbox and as a queued email; accept and decline at `/invitations/{token}`.
- Notification inbox at `/account/inbox` with unread counts and mark-as-read.
- Ownership transfer that requires recent password reauthentication (`POST /api/auth/reauthenticate`) plus explicit confirmation, runs in one transaction, always leaves exactly one owner, and keeps the previous owner as an editor unless they choose to leave.
- Role changes, collaborator removal, and invitation revocation from the Collaborators panel on the manage page.
- Help topics: collaborators and roles, transferring ownership, and your inbox.

### Security
- Invitation tokens are never echoed back to the inviter and only the addressed account can accept or decline.
- Transfers and every roster change are CSRF-protected, permission-checked server-side, and recorded in the adventure activity log with notifications to both parties.

## 0.19.0 — 2026-09-13

### Added
- Moderation and owner controls, backed by `App\ModerationService` (`app/services/ModerationService.php`) and migration `private/migrations/0009_moderation_and_owner_controls.sql`.
- Management sections: overview, story, submissions, reports, collaborators, and settings, served from `/api/adventures/{slug}/moderation/*`.
- Submission queue tabs: pending, changes requested, approved, rejected, and withdrawn.
- Owner and editor decisions: approve, reject with feedback, request changes, and edit-and-approve.
- Reviewer role: private notes and non-binding recommendations to approve or reject.
- Contributor workflow: read feedback, edit a changes-requested submission, resubmit, and withdraw (`PUT /api/account/contributions/{id}`, `POST /api/account/contributions/{id}/withdraw`).
- Story management: edit scenes and choice labels, lock and unlock scenes, hide and restore scenes, and add owner-created branches.
- Owner settings: contribution mode, anonymous contributions, branch limit, contribution passcode, pause submissions, allow branching, and notification preferences.
- Per-user, per-adventure standing: trusted, approval required, or blocked.
- Content reports from readers (`POST /api/adventures/{slug}/reports`) with an owner-side resolve or dismiss queue.

### Changed
- Submission states are now `pending`, `changes_requested`, `approved`, `rejected`, and `withdrawn`; the former `published` and `declined` values map to `approved` and `rejected`.
- New submissions honour contributor standing: trusted bypasses the queue, approval-required always queues, blocked is refused.
- Pausing submissions or disabling branching closes the contribution path without changing the contribution mode.

### Security
- Roles are always derived server-side from the adventure author, the collaborator roster, and the administrator flag; a role in the request body is ignored.
- Every decision runs in one transaction that re-reads and claims the submission by state, so double approval, approval after rejection or withdrawal, and branch-limit bypass are all refused.
- Contributor edits and withdrawals are matched on submission id and user id together.
- Contribution passcodes are only ever stored hashed; edited scene bodies and guidelines pass the same sanitiser as contributed content.

## 0.18.0 — 2026-09-13

### Added
- Branch submissions: `GET /api/adventures/{slug}/scenes/{scene}/branch` (form context) and `POST` (submit), backed by `App\BranchSubmissionService` (`app/services/BranchSubmissionService.php`).
- Submission page at `/adventure/:slug/branch?from=<scene>` (`frontend/src/pages/SubmitBranch.tsx`): choice text, next-scene title and body, story-or-ending, optional private note to the team, public attribution preference, and the contribution passcode when one is configured.
- Browser autosave for branch drafts, kept per adventure and per scene, restored on return and cleared once the branch is accepted.
- Contribution history on `/account/contributions`, showing each submission with its review state.
- Migration `private/migrations/0008_branch_submissions.sql`: `branch_submissions`, `contribution_blocks`, `contribution_attempts`, and `scenes.is_locked`. Default settings `contributions_per_user_per_hour` (10) and `contributions_per_ip_per_hour` (5).

### Changed
- Immediate mode publishes the new scene and choice in one transaction; approval mode records a pending submission and publishes nothing; closed mode refuses.
- Pending submissions occupy a branch slot, so a scene cannot be over-filled while its queue is waiting.
- Help topic "Contributing a branch" rewritten to describe shipped behaviour.

### Security
- Every rule is enforced server-side: adventure availability, contribution mode, published and unlocked source scene, branch limit, contributor block list, passcode, hourly per-user and per-IP rate limits, honeypot, length limits, sanitisation, and duplicate detection on the normalised choice text.
- The contributor identity comes from the session cookie and the request IP; a user id in the request body is ignored. Signed-out visitors may contribute only where the creator allows it, and can only be credited as Anonymous.
- Anonymous public attribution hides the name from readers only. The user id, username, and submitting IP are always recorded for moderators.
- Choice text and scene titles are flattened to plain text; scene bodies pass through `App\HtmlSanitizer` before storage, so a contribution can never control presentation.
- Submissions require a valid double-submit CSRF token and run under the `flock()` write lock, so concurrent submissions cannot exceed a scene's branch limit.
- A tripped honeypot is answered exactly like a success and stores nothing. Refused submissions consume no rate-limit budget.

## 0.17.0 — 2026-08-22

### Added
- Publication workflow endpoints backed by `App\PublicationService` (`app/services/PublicationService.php`): `GET /api/adventures/{slug}/manage`, `GET /api/adventures/{slug}/preview`, `PUT /api/adventures/{slug}/draft`, `POST /api/adventures/{slug}/status`.
- Status actions: publish, unpublish, set in progress, set complete, set on hold, and archive.
- Working manage console at `/manage/:slug` (`frontend/src/pages/ManageAdventure.tsx`) with draft saving, status controls, and the activity log.
- Private preview at `/adventure/:slug/preview` (`frontend/src/pages/Preview.tsx`) showing every scene, unpublished ones included.
- Confirmation dialogs before publish, unpublish, and archive.
- Migration `private/migrations/0007_publication_workflow.sql`: `adventure_collaborators` (owner/editor roster, backfilled for existing adventures) and the append-only `adventure_activity` log.

### Changed
- Publishing requires a valid opening scene (start scene with a title and body text); publishing also publishes that opening scene.
- Archived adventures are read-only — no draft saves and no further status changes.
- Unpublishing returns the adventure to draft and deletes nothing: scenes, choices, warnings, and settings are preserved.

### Security
- Drafts and previews are visible only to the adventure author, its collaborators, and administrators. Roles are derived server-side from the session; a role or owner id in the request body is ignored.
- Preview responses send `X-Robots-Tag: noindex, nofollow`, and the preview page adds a matching robots meta tag, so preview links are never indexed.
- Draft saves and status changes require a valid double-submit CSRF token and run under the `flock()` write lock.
- Every accepted status change writes its activity record inside the same transaction as the state update; rejected changes write nothing.
- Preview scene bodies are re-sanitized through `App\HtmlSanitizer` before they leave the server.

## 0.16.0 — 2026-07-19

### Added
- Adventure creation for signed-in, active accounts. `POST /api/adventures` and `GET /api/adventures/creation-settings`, backed by `App\AdventureService` (`app/services/AdventureService.php`).
- Five-step creation wizard at `/start` (`frontend/src/pages/CreateAdventure.tsx`): Basics, Opening scene, Contributions, Writing guidelines, Review.
- Four templates — `solo`, `open-community`, `moderated-community`, `private-group`. Templates set visibility, contribution mode, anonymous contributions, and the per-scene branch limit; they never write story content and never bypass validation.
- Migration `private/migrations/0006_adventure_creation.sql`: `adventures.anonymous_contributions`, `adventures.max_branches_per_scene`, `adventures.contribution_passcode_hash`, `adventures.writing_guidelines_plain`, `adventures.template_key`, `scenes.body_plain`, and the `adventure_creation_attempts` table. Default settings `max_adventures_per_user` (20) and `adventures_per_user_per_hour` (5).
- Draft/publish choice on the Review step. A draft keeps its opening scene in the `draft` state.

### Changed
- `/start` is now the working wizard; the placeholder `Start` page has been removed.
- Help topic "Creating an adventure" rewritten to describe shipped behaviour.

### Security
- The adventure row, its opening scene, and its content warnings are written inside one transaction held under the `flock()` write lock. Any failure rolls back the whole unit, and the rate-limit attempt row is only recorded on commit.
- The owner is always the authenticated session user; `author_id`/`owner_id` in the request body are ignored.
- Creation requires an `active` user and a valid double-submit CSRF token.
- Per-user adventure caps and the hourly creation limit are enforced server-side before any write.
- Scene text and writing guidelines are re-sanitized through `App\HtmlSanitizer` on the server; plain-text projections (`body_plain`, `writing_guidelines_plain`) are derived for limits and search.
- Contribution passcodes are stored as password hashes only.

### Tests
- `tests/php/adventure_creation_test.php` — creation, ownership, authorization, validation, sanitization, templates, limits, rollback, and rate-limit recording.
- `frontend/src/__tests__/adventure-creation.test.tsx` — wizard steps, template behaviour, validation, limits, unauthenticated state, and submit payload.



## 0.15.0 — 2026-07-19

### Added
- Bundled dependency-free React WYSIWYG editor `RichTextEditor` (`frontend/src/components/RichTextEditor.tsx`). The toolbar exposes exactly twelve actions and nothing else: Bold, Italic, Underline, Paragraph, Heading 2, Heading 3, Bulleted list, Numbered list, Blockquote, Horizontal rule, Undo, Redo.
- Client sanitizer `sanitizeRichTextHtml` / `richTextToPlainText` / `richTextLength` in `frontend/src/lib/richTextSanitizer.ts`. Enforces the same allow-list as the server sanitizer so paste and on-change output are pre-filtered.
- PHP sanitizer `App\HtmlSanitizer` (`app/security/HtmlSanitizer.php`) providing `sanitize()`, `toPlainText()`, and `plainTextLength()`. Allowed tags: `p`, `strong`, `em`, `u`, `h2`, `h3`, `ul`, `ol`, `li`, `blockquote`, `hr`, `br`. No attributes on any element.
- Help topic "Restricted writing tools" covering what the editor can and cannot do, why links and images are removed, and how pasted content is normalised.

### Security
- Every attribute — including `class`, `style`, `id`, `data-*`, `aria-*`, `href`, `src`, `align`, and every `on*` event handler — is stripped from stored HTML by both the client and PHP sanitizers.
- Disallowed subtrees (`script`, `style`, `iframe`, `object`, `embed`, `svg`, `math`, `form`, `img`, `video`, `audio`, `input`, `template`, `link`, `meta`, `base`, and every custom element) are dropped whole, including their text — hostile payloads cannot survive by being nested inside an allowed tag.
- Links are unwrapped (text kept, `href` discarded). Plain-text pastes containing URLs are inserted as literal text; no auto-linking is performed anywhere in the pipeline.
- The editor is a UX aid, not a security boundary. Every server write path re-sanitizes through `App\HtmlSanitizer` before storage, so a hostile client posting arbitrary HTML directly to the API cannot bypass the allow-list.
- File and image drops onto the editing surface are cancelled at the `dragdrop` boundary.

### Changed
- `frontend/src/components/index.ts` now re-exports `RichTextEditor`, `RICH_TEXT_ACTIONS`, `sanitizeRichTextHtml`, `richTextToPlainText`, and `richTextLength`.
- `frontend/src/styles/global.css` gains scoped `.bp-rte*` styles for the toolbar, editing surface, placeholder, character counter, and headings/lists/blockquotes rendered inside the surface.

### Notes
- The plain-text projection returned by `toPlainText()` is the canonical form used for character limits, search indexing, snippets, duplicate detection, and plain-text exports. `plainTextLength()` counts characters (multibyte-safe).



## 0.14.0 — 2026-07-19

### Added
- Migration `private/migrations/0005_account_dashboard.sql` adding `bio`, `public_profile`, `notify_replies`, `notify_moderation`, `notify_updates` columns to `users`, `user_agent` to `sessions`, a `pending_email_changes` table (hashed single-use token, 24h expiry), and a `user_bookmarks` table with a unique `(user_id, adventure_id, scene_id, kind)` constraint. Seeds an `email_change_verify` email template.
- `App\AccountService` orchestrating profile/prefs updates, active-session listing, revoke-others, two-step email change (`requestEmailChange` → `confirmEmailChange`), bookmark/history import from a client-supplied payload, and per-caller adventure listing.
- API routes `GET/PUT /api/account/profile`, `GET /api/account/security`, `PUT /api/account/notifications`, `POST /api/account/email-change`, `POST /api/account/email-change/confirm`, `POST /api/account/sessions/revoke-others`, `GET /api/account/adventures`, `GET /api/account/contributions`, `GET /api/account/bookmarks`, and `POST /api/account/bookmarks/import`. Every route requires a live session cookie; mutating routes additionally require CSRF and run under the write lock.
- React account dashboard: `/account`, `/account/profile`, `/account/security`, `/account/notifications`, `/account/adventures`, `/account/contributions`, `/account/bookmarks` with a shared `AccountLayout` nav.
- Local bookmark and reading-history import: the bookmarks page collects `bp-progress:*` entries from `window.localStorage`, asks for confirmation, and posts them to the account import endpoint.
- Help topic "Account dashboard" covering profile edits, email change, notification prefs, session revocation, and local-progress import.

### Security
- Every `/api/account/*` route requires an active session cookie (401 otherwise). Mutating routes additionally require a fresh CSRF token and execute under the write lock so concurrent updates cannot leave a user row inconsistent.
- Email-change tokens are 32 cryptographically random bytes generated via `random_bytes`, stored only as SHA-256 hashes, expire after 24 hours, and are single-use — a used or expired token fails identically to an unknown one.
- The new email address only lands on `users.email` after the token is consumed by the account holder. A duplicate-address race is caught at confirm time and returns `email_in_use`.
- Revoking other sessions keeps the current caller's session live and revokes every other session; the caller's cookie is unchanged.
- Bookmark/history import validates every scene slug against the target adventure and uses `INSERT OR IGNORE` so repeated imports are idempotent; unknown slugs are dropped rather than raising a duplicate-key error.

### Changed
- `AccountLayout` now renders a sub-navigation covering every account sub-page and highlights the current section.
- `frontend/src/App.tsx` wires the seven account sub-routes; the placeholder `Account` component in `pages/protected.tsx` has been removed.


## 0.13.0 — 2026-07-19

### Added
- Migration `private/migrations/0004_authentication_and_sessions.sql` creating `sessions` (hashed token, user, created/rotated/expiry timestamps, revocation reason, IP/user-agent columns) and `auth_tokens` (hashed token, purpose enum with CHECK `email_verification`/`password_reset`, target user, single-use `consumed_at`, expiry). Extends `users` with `password_changed_at` and `last_login_at`, and seeds a `canonical_url` setting.
- `App\TokenRepository` issuing 32-byte random tokens, storing only their SHA-256 hash, and consuming them under a single-use guard: a used or expired token cannot be replayed.
- `App\SessionRepository` for database-backed sessions with hashed lookup, rotation, per-user revocation (`logout`, `password_reset`, `password_change`, `suspended`), and cookie helpers that set HttpOnly, SameSite=Lax, and Secure (when HTTPS) with a bounded 14-day expiry.
- `App\AuthService` orchestrating login, logout, email verification, resend verification, forgot / reset / change password. On successful login every prior session for the user is revoked before a new cookie is issued.
- `RegistrationService` now emits an `onRegistered` hook, wired in `public/api/index.php` so successful registrations queue `verify_email` immediately.
- `App\CanonicalUrl` helper reading the `canonical_url` setting and refusing anything not `https://` so verification and reset links never point elsewhere.
- `POST /api/auth/login`, `POST /api/auth/logout`, `POST /api/auth/verify-email`, `POST /api/auth/resend-verification`, `POST /api/auth/forgot-password`, `POST /api/auth/reset-password`, `POST /api/auth/change-password`, and `GET /api/auth/session`. Every mutating route requires a fresh CSRF token.
- React pages `/login`, `/forgot-password`, `/reset-password`, `/verify`, and `/change-password` in `frontend/src/pages/AuthPages.tsx` with an enumeration-safe reset flow, a single-use verification consumer, and same-origin redirect guarding for the `?next=` parameter.
- API client helpers `fetchAuthSession`, `submitLogin`, `submitLogout`, `submitVerifyEmail`, `submitResendVerification`, `submitForgotPassword`, `submitResetPassword`, and `submitChangePassword` in `frontend/src/lib/apiClient.ts`, each fetching a fresh CSRF token and mapping server outcomes to a typed `AuthOutcome` union.
- Help topics for verification, sign-in, password recovery, and account security in `frontend/src/data/helpTopics.ts`.

### Security
- Tokens are 32 cryptographically random bytes generated via `random_bytes`, stored only as SHA-256 hashes, expiring (verification 24 hours, reset 60 minutes), and single-use — replay after `consumed_at` fails identically to an unknown token.
- Session cookies store an opaque random token; only its SHA-256 hash lives in `sessions`, so a database dump does not expose a login credential.
- Sessions are rotated on login. Any earlier session for the user is revoked with reason `login_rotation` before a new cookie is issued.
- Sessions are revoked on logout, password reset, password change, and every transition of a user out of `active` status.
- Password reset, resend verification, and forgot password return identical responses regardless of whether the address is on file, protecting account enumeration.
- Pending, suspended, and deleted users receive the same `invalid_credentials`-style outcome as a wrong password.
- Post-login and reset redirects are allow-listed to same-origin paths beginning with a single `/`. Protocol-relative, backslash-escaped, and scheme-embedding paths are rejected on both server and client.
- Verification / reset / password-changed emails are rendered through the existing template registry and enqueued via `EmailQueueRepository`; failure to enqueue never leaks user existence.

### Changed
- `RegistrationService::register` accepts an optional `onRegistered` callback so the HTTP layer can queue the verification mail without coupling the service to `EmailQueueRepository`.
- `public/api/index.php` route map extended with the `/auth/*` group; existing endpoints unchanged.



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
