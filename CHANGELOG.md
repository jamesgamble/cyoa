# Changelog

All notable changes to Branching Paths are documented in this file.
This project uses [Semantic Versioning](https://semver.org/).

## 0.2.0 — 2026-07-19

### Added
- Complete design system: tokens for colors, typography, spacing, borders, shadows, reading widths, statuses, buttons, forms, story pages, management panels, and administrative tables.
- Three layout modes: public reading, creation and management, master administration.
- Internal design system preview at `/design-system` displaying typography, colors, buttons, inputs, checkboxes, radios, toggles, status badges, story page with numbered choices, cards, alerts, dialogs, help drawer, management panels, and administrative tables.
- Reusable primitives: Button, Badge, Alert, Dialog, HelpDrawer, Panel.
- Subtle local paper texture rendered in CSS (no imagery, no parchment novelty).
- Editorial ornament between story sections and a decorative drop capital for opening passages.
- Numbered choice styling with a numbered-list counter and hover affordance.
- Design system documentation at `docs/DESIGN-SYSTEM.md`.

### Changed
- Public, account, adventure management, and master layouts now apply their layout mode automatically, adjusting container width and chrome.
- Footer, header, and navigation restyled to the editorial visual language.

### Accessibility
- Visible focus rings on all interactive elements.
- Respects `prefers-reduced-motion` and `prefers-contrast: more`.
- Explicit high-contrast opt-in via `data-contrast="high"` and increased text size via `data-text-size="large" | "x-large"`.
- Minimum 44px tap targets on primary controls; keyboard-reachable dialog and help drawer.


## 0.1.0 — 2026-07-19

### Added
- Initial project scaffolding for the Branching Paths application.
- Directory layout for the React frontend, PHP backend, private runtime data, scripts, and documentation.
- React + TypeScript + Vite + React Router application shell.
- Public routes: Home, Discover, Start, Help (index and topic), Changelog (index and version), Login, Register.
- Placeholder protected routes: Account, Adventure management, Master login, Master administration.
- Shared layouts for public pages, account pages, adventure management, and master administration.
- Reusable state components: Loading, Empty, Error, Unauthorized, Not found, Service unavailable.
- Primary navigation with Home, Discover, Create, Help, Changelog, and Sign In.
- Footer showing version, Changelog, Help, Community Guidelines, Privacy, and About.
- Reusable version component sourced from the `VERSION` file.
- Typed changelog data source and public changelog pages.
- Initial documentation set: architecture, database, security, hosting, help, QA, and roadmap outlines.
- Focused tests for routing, primary navigation, changelog links, version display, not-found page, responsive navigation, and keyboard navigation.
