# Changelog

All notable, public-safe changes to Branching Paths. Newest release first.

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
