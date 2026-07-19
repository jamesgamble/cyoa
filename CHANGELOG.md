# Changelog

All notable changes to Branching Paths are documented in this file.
This project uses [Semantic Versioning](https://semver.org/).

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
