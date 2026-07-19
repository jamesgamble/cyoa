# Architecture

Branching Paths is a self-contained application. It has three layers:

1. **Static frontend** built from `frontend/` with Vite. The build output is served from the web root along with PHP entry points.
2. **PHP backend** in `app/` and `public/api/` running under Apache or Nginx with PHP 8.2+. All persistence uses PDO SQLite.
3. **Private runtime data** under `private/` (SQLite file, write lock, logs, backups). This directory is outside the web root and must never be publicly served.

## Frontend

- React + TypeScript
- Vite build
- React Router
- No cloud services, no serverless functions
- Reads its version from the shared `VERSION` file (baked in at build time)

### Shared components

The `frontend/src/components/` directory holds the shared component library
used across every surface of the app. Everything is exported from a single
barrel (`frontend/src/components/index.ts`) and previewed at the internal
`/design-system` route.

- **Chrome:** `Wordmark`, `Masthead` (literary desktop nav), `MobileNav`
  (mobile drawer), `Colophon` (editorial footer). `PrimaryNav` and `Footer`
  are compatibility re-exports.
- **Story surface (`components/story/`):** `StoryPage`, `SceneTitle`,
  `StoryBody`, `Choice`, `ChoiceList`, `ChoicesHeading`, `EndingPanel`.
  User-provided story text is rendered exclusively as plain text — never
  `dangerouslySetInnerHTML` — and CSS applies `!important` overrides on
  font, color, width, alignment, background, border, and position within
  `.bp-story__body` so pasted content cannot control presentation.
- **Discovery:** `AdventureCard`, `FeaturedAdventureCard`.
- **Forms:** `SearchField`, `Select`, `Checkbox`, `RadioGroup`, `Toggle`,
  `TextArea`, `PasswordField`, `FormSection`, `StepIndicator`,
  `ValidationMessage`, `InlineHelp`.
- **Overlays:** `Dialog`, `HelpDrawer`.
- **Management:** `ManageNav`, `QueueItem`, `ActivityItem`, `WarningPanel`,
  `DangerZone`, `AdminTable`, `SearchFilterBar`.
- **Status:** `Badge`, `Alert`, `Panel`, `EmptyState`, `ErrorState`.

Every interactive control ships with a visible label or explicit
`aria-label`, uses semantic HTML (`<button>`, `<a>`, `<ol>`, `<fieldset>`,
etc.), and honors the design system's focus-visible ring and reduced-motion
overrides. Tests in `frontend/src/__tests__/shared-components.test.tsx`
cover rendering, keyboard interaction, ARIA state, and story-content
containment.

## Backend (planned in later versions)

- Plain PHP 8.2 or newer
- PDO SQLite with WAL, foreign keys on, busy timeout 10000, synchronous NORMAL
- Single `WriteLock` service using `flock()`; every write path acquires the lock, performs a short transaction if needed, and releases in a `finally` block
- Reads never acquire the write lock

## Deployment

- Apache or Nginx serves `public/` as the web root
- `public/api/` contains PHP entry points
- `private/` sits above the web root and is never served
- No Docker, no Node.js, no external database, no external storage
