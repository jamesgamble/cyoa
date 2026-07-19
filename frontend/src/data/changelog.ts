/**
 * Public changelog data source.
 *
 * Newest release first. Entries here must be public-safe: no secrets, no
 * exploit details, no private admin notes, no raw errors, no internal-only
 * infrastructure details.
 */

export type ChangelogSection =
  | "Added"
  | "Changed"
  | "Fixed"
  | "Removed"
  | "Security"
  | "Known Issues";

export interface ChangelogEntry {
  version: string;
  date: string; // ISO date
  sections: Partial<Record<ChangelogSection, string[]>>;
}

export const CHANGELOG: ChangelogEntry[] = [
  {
    version: "0.12.0",
    date: "2026-07-19",
    sections: {
      Added: [
        "Administrator-configurable SMTP settings at /master/settings/email with host, port, encryption, username, password, from address, from name, reply-to, retry limit, batch size, and an enabled toggle.",
        "Encrypted password storage: SMTP credentials are encrypted at rest with an application key kept outside the database and never returned to the browser.",
        "Durable email queue with pending, sending, sent, failed, and cancelled states, exponential retry with jitter capped at one hour, and abandoned-worker recovery.",
        "A queue viewer at /master/email-queue showing counts, per-message status, attempt count, next-attempt time, and a Cancel action for pending rows.",
        "A test-email action on the settings page that queues the operator_test template so operators can confirm delivery without opening a raw send endpoint.",
        "An email worker script (scripts/process-email-queue.php) guarded by a dedicated file lock so overlapping cron ticks never spawn duplicate workers.",
        "An administrator bootstrap script (scripts/bootstrap-admin.php) that creates or promotes the initial admin account under the write lock.",
        "Master sign-in at /master/login with HMAC-signed session cookies (HttpOnly, SameSite=Strict) that re-check the admin role on every request so revocation is instant.",
      ],
      Security: [
        "SMTP passwords never leave the server unredacted; the API always sends a sentinel placeholder in place of the plaintext.",
        "Queue error messages are constrained to an allow-list of short tokens so raw provider replies containing recipient addresses cannot reach an operator's browser.",
        "The test-email path only enqueues the fixed operator_test template — the endpoint cannot be repurposed as an open relay.",
        "Every mutating master endpoint requires both an authenticated session and a fresh double-submit CSRF token.",
        "Tests exclusively use an in-memory mock transport; no test path can accidentally contact a live SMTP server.",
      ],
    },
  },

  {
    version: "0.11.0",
    date: "2026-07-19",
    sections: {
      Added: [
        "Self-service account registration at /register collecting email, username, display name, password, password confirmation, and community-guidelines acceptance.",
        "Server-side settings for registration availability, minimum password length, per-IP hourly ceiling, email-verification requirement, and administrator-approval requirement.",
        "Password hashing with Argon2id when the PHP build supports it, and bcrypt as a portable fallback.",
        "Public help topic covering how registration works, what the response means, and why the site does not tell you whether an address is already taken.",
      ],
      Security: [
        "Registration responses are the same whether the account is new, the email is already taken, the username is already taken, or the honeypot fired — the endpoint cannot be used to enumerate existing accounts.",
        "Every unsafe request is protected by a double-submit CSRF token scoped to the site with SameSite=Strict; requests without a matching token are rejected before any business logic runs.",
        "Per-IP hourly rate limiting is enforced on the server; attempts are counted regardless of outcome so bots cannot avoid the limit by intentionally sending invalid payloads.",
        "Passwords are hashed with Argon2id or bcrypt through PHP's built-in password hashing; raw passwords are never written to disk or logs.",
      ],
    },
  },
  {
    version: "0.10.0",
    date: "2026-07-19",
    sections: {

      Added: [
        "Initial public data model migration creating users, adventures, scenes, choices, and content warnings with schema-level CHECK constraints on every state, visibility, scene-type, and content-rating column.",
        "Discovery and traversal indexes covering adventure state and visibility, genre, content rating, contribution state, updated-at ordering, per-adventure scene lookup, choice edges by source and target, and content-warning ordering.",
        "Read-only public repository enforcing the visibility rules end to end: draft and suspended adventures are never returned, unlisted adventures are excluded from Discover but readable by direct link, hidden and draft scenes are never returned, and choices pointing to unpublished destinations are filtered out of every response.",
        "Public read-only API endpoints for the Discover list, an adventure by slug, the public story outline, and a published scene with its choices.",
        "Development seed data populating the fixture library plus additional suspended and unlisted adventures used to exercise the visibility rules.",
        "Frontend API client with progressive enhancement on the Discover, Adventure, and Reader pages: fixtures render immediately, and the API response replaces them when it arrives.",
        "Focused migration, visibility, endpoint, and integration tests running the built-in PHP server against a seeded database and asserting that hidden and draft scenes are never exposed, directly or through choice targets.",
      ],
      Changed: [
        "Frontend fixtures are now the offline fallback for the public pages; live rendering prefers API data when available.",
      ],
      Security: [
        "Choice destinations are filtered against the set of published scenes so a caller cannot enumerate hidden or draft scene identifiers by inspecting a public scene.",
        "API responses never surface PDO error text, filesystem paths, or stack traces; failures are logged privately and returned as generic 503 or 404 responses.",
      ],
    },
  },
  {
    version: "0.9.0",
    date: "2026-07-19",
    sections: {
      Added: [
        "Plain-PHP application bootstrap with a namespaced autoloader and JSON-only error handlers that log full detail privately and return an opaque payload to clients.",
        "Centralised configuration with environment-variable overrides for APP_ENV, APP_URL, DATABASE_PATH, and WRITE_LOCK_PATH.",
        "SQLite adapter that enforces WAL journaling, foreign-key checks, a 10-second busy timeout, and synchronous=NORMAL on every connection.",
        "Bounded file-based write lock built on flock() with a configurable timeout, guaranteed release, and non-blocking polling so writes cannot hang indefinitely.",
        "Migration runner that tracks applied versions in a schema_migrations table and applies each SQL file inside its own transaction with automatic rollback on failure.",
        "Public API entry point at /api/index.php exposing GET /api/health that returns only api status, database status, schema version, and application version.",
        "CLI scripts for first-time initialisation, applying migrations under the write lock, and running operator diagnostics against the runtime.",
        "Frontend API pathing helper with a Vite dev proxy from /api to the local PHP server and a VITE_API_BASE_URL override for staging.",
        "Help topic covering the health endpoint and what it deliberately does and does not expose.",
        "Focused PHP tests for pragmas, foreign keys, WAL persistence, migration tracking and rollback, lock acquire/release, bounded contention timeout, and the health payload shape.",
      ],
      Security: [
        "Health endpoint whitelists response keys so filesystem paths, PDO error strings, and stack frames cannot be enumerated over HTTP.",
        "PHP display_errors is disabled at runtime; uncaught errors are logged privately and returned to clients as a generic JSON error.",
      ],
    },
  },
  {
    version: "0.8.0",
    date: "2026-07-19",
    sections: {
      Added: [
        "Help center at /help with a keyword search across every topic, an empty-result state, and a topic index listing the eleven canonical topics: Getting started, Discovering, Reading, Creating, Contributing, Accounts, Managing adventures, Moderation, Privacy and security, Changelog, and Common errors.",
        "Individual help topic pages at /help/<slug> with breadcrumbs, a one-sentence summary, full body, and a Related topics section that links to sibling topics.",
        "Persistent floating Help button rendered on every page by the global HelpProvider.",
        "Contextual help drawer that suggests the four most relevant topics for the current route, sign-in state, role, adventure state, and section, with a link out to the full help center.",
        "useHelpContext hook that lets pages push section, adventure-status, or setting overrides onto the contextual selection stack.",
        "Keyboard support in the drawer: initial focus on Close, Tab/Shift+Tab focus trap, Escape to close, and focus return to the element that opened it.",
        "Help URL sanitisation that strips query strings and hash fragments before any pathname appears in help copy or links so credentials and tokens can never leak through help.",
        "Inline-help component now used alongside the new drawer to explain individual settings and fields where appropriate.",
      ],
      Changed: [
        "Help topics rewritten to describe only implemented behaviour; planned features are labelled as such.",
        "Home, Discover, Adventure, and Reader pages register their section (and adventure status where known) with the contextual help selector.",
      ],
    },
  },
  {
    version: "0.7.0",
    date: "2026-07-19",
    sections: {
      Added: [
        "Public story reader at /adventure/:slug/read/:sceneId rendered from typed scene fixtures.",
        "Scene view shows adventure title, optional chapter label, scene title, story body, scene number, and either numbered choices or an ending panel.",
        "Reader actions: Back one scene, Restart, Story map, Bookmark, Add a branch (when contributions are open), Report, and Help.",
        "Local reading history per adventure powers the Back button and the resume marker.",
        "Local bookmarks and discovered-ending counts stored per adventure in the browser.",
        "Explore another path action at endings that jumps back to the most recent branching scene in the reader's trail.",
        "Clear local progress control that erases reading history, bookmarks, and discovered endings for the adventure.",
        "Invalid-scene state when the URL references a scene that does not exist in the adventure, with a link back to the start.",
        "Empty-reader state when an adventure has no readable scenes yet.",
        "Help topic covering the reader and its local-only storage model.",
        "Story fixtures for The Lantern Road (seven scenes, three endings) and The Inn at the Crossing (two scenes, one ending).",
      ],
      Changed: [
        "Adventure landing page Read and Resume links now target /adventure/:slug/read and /adventure/:slug/read/:sceneId respectively.",
        "Local progress record extended with history, bookmarks, and discoveredEndings while remaining backwards-compatible with the v0.6.0 shape.",
      ],
    },
  },
  {
    version: "0.6.0",
    date: "2026-07-19",
    sections: {
      Added: [
        "Public adventure landing page at /adventure/:slug rendered from typed fixtures.",
        "Title, creator, description, genre, content rating, content warnings, scene and ending counts, and last-updated line on the landing page.",
        "Adventure status field with four states: In progress, Complete, On hold, and Archived.",
        "Contribution status field with three states: Immediate publishing, Approval required, and Closed.",
        "Author-authored writing guidelines panel.",
        "Read from beginning, View story map, and Add a branch actions; Add a branch is hidden when contributions are closed.",
        "Resume reading action shown only when the visitor's browser has a local progress marker for the adventure.",
        "Follow placeholder visible to signed-in visitors, wired to a client-only toggle until the account system ships.",
        "Help topic covering the adventure page and the meaning of each status.",
        "Not-found state for /adventure/:slug when the slug does not resolve.",
      ],
      Changed: [
        "AdventureSummary gained optional description, storyStatus, contributionState, contentWarnings, and writingGuidelines fields; existing consumers continue to work.",
        "Discover fixtures enriched with the new fields so the same records back both Discover and the adventure landing page.",
      ],
    },
  },
  {
    version: "0.5.0",
    date: "2026-07-19",
    sections: {
      Added: [
        "Public Discover page listing the current library of adventures from typed fixtures.",
        "Keyword search across title, author, and synopsis.",
        "Filters for genre, content rating, adventure status, and contribution status.",
        "Sorting by recently updated, newest, title, scene count, and ending count.",
        "Clear-filters control that resets the view to defaults.",
        "Mobile filter panel rendered in the shared dialog on narrow screens.",
        "Empty-result state with a clear-filters shortcut.",
        "URL-backed filter state so a filtered view can be bookmarked or shared.",
        "Contribution-availability tag and ending-count metadata on adventure cards.",
        "Help topic explaining how to browse the library.",
      ],
      Changed: [
        "AdventureSummary gained optional genre, content rating, ending count, contribution flag, and ISO timestamp fields; existing cards continue to render without them.",
      ],
    },
  },
  {
    version: "0.4.0",
    date: "2026-07-19",
    sections: {
      Added: [
        "Public homepage with three primary reader actions: read, create, or continue someone else's story.",
        "Featured adventure and recently-updated adventure fixtures on the homepage.",
        "Three-step explanation of how Branching Paths works.",
        "Notice that reading published adventures does not require an account.",
        "Registration call to action alongside an existing-account shortcut.",
        "Summary of the community guidelines with a link to the full help topic.",
        "Directory linking to Discover, Create, Help, and Changelog.",
        "Expanded community-guidelines help topic to match the homepage summary.",
      ],
      Changed: [
        "Homepage stylesheet extends existing design tokens; no new colors or typefaces were introduced.",
      ],
    },
  },
  {
    version: "0.3.0",
    date: "2026-07-19",
    sections: {
      Added: [
        "Shared component library covering navigation, story, discovery, forms, management, and state.",
        "Literary masthead with an inline text wordmark and a fine editorial rule.",
        "Mobile navigation drawer that closes on Escape and on link activation.",
        "Text-based wordmark component.",
        "Colophon-style footer with attribution line and a directory of secondary links.",
        "Story components: story page container, scene title, story body, numbered choice, choice list, and ending panel.",
        "Adventure card and featured adventure card for the discovery experience.",
        "Form components: search field, select, checkbox, radio group, toggle, text area with counter, password field with reveal, form section, step indicator, validation message, and inline help.",
        "Management components: management navigation rail, queue item, activity item, warning panel, danger zone, administrative table, and search-and-filter bar.",
        "Empty state and error state components with optional actions.",
        "Every documented component is exhibited on the internal design system preview.",
      ],
      Changed: [
        "Legacy Footer and PrimaryNav names now transparently render the new Colophon and Masthead components.",
        "Empty state and error state accept an optional action node.",
      ],
      Security: [
        "User-authored story content flows exclusively through the sanitizing story components. It cannot control font, color, width, alignment, background, border, or position; every string is escaped and rendered inside fixed presentational elements owned by the design system.",
      ],
    },
  },
  {
    version: "0.2.0",
    date: "2026-07-19",
    sections: {
      Added: [
        "Complete design system with tokens for colors, typography, spacing, borders, shadows, reading widths, statuses, buttons, forms, story pages, management panels, and administrative tables.",
        "Three layout modes: public reading, creation and management, master administration.",
        "Internal design system preview at /design-system showing typography, colors, buttons, inputs, checkboxes, radios, toggles, status badges, story page, numbered choices, cards, alerts, dialogs, help drawer, management panels, and administrative tables.",
        "Reusable primitives: Button, Badge, Alert, Dialog, HelpDrawer, Panel.",
        "Subtle local CSS paper texture, editorial ornaments, and numbered choice styling.",
        "Design system documentation.",
      ],
      Changed: [
        "Public, account, adventure management, and master layouts now apply their layout mode, adjusting container width and chrome.",
        "Footer, header, and navigation restyled to the editorial visual language.",
        "Interactive elements show a visible keyboard focus ring, honor reduced motion and high contrast preferences, support an increased text size setting, and meet a 44px minimum tap target on primary controls.",
      ],
    },
  },
  {
    version: "0.1.0",
    date: "2026-07-19",
    sections: {
      Added: [
        "Initial project scaffolding for the Branching Paths application.",
        "Directory layout for the React frontend, PHP backend, private runtime data, scripts, and documentation.",
        "React + TypeScript + Vite + React Router application shell.",
        "Public routes: Home, Discover, Start, Help (index and topic), Changelog (index and version), Login, Register.",
        "Placeholder protected routes for account, adventure management, and master administration.",
        "Shared layouts for public pages, account, adventure management, and master administration.",
        "Reusable state components: Loading, Empty, Error, Unauthorized, Not found, Service unavailable.",
        "Primary navigation with Home, Discover, Create, Help, Changelog, and Sign In.",
        "Footer showing version, Changelog, Help, Community Guidelines, Privacy, and About.",
        "Reusable version component sourced from the VERSION file.",
        "Typed changelog data source and public changelog pages at /changelog and /changelog/:version.",
        "Initial documentation set covering architecture, database, security, hosting, help, QA, and roadmap.",
        "Focused tests for routing, navigation, changelog links, version display, not-found, responsive navigation, and keyboard navigation.",
      ],
    },
  },
];

export function findChangelogEntry(version: string): ChangelogEntry | undefined {
  return CHANGELOG.find((e) => e.version === version);
}
