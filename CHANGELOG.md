# Changelog

All notable, public-safe changes to Branching Paths. Newest release first.

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
