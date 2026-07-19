# Changelog

All notable, public-safe changes to Branching Paths. Newest release first.

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
