# QA

Each version ships with focused tests for the change it introduces. Tests live in `frontend/src/__tests__/` and are run with `npm run test` (Vitest).

## 0.1.0 checks

- Routing: every declared public and placeholder route renders without crashing.
- Primary navigation: renders Home, Discover, Create, Help, Changelog, Sign In.
- Changelog links: `/changelog` and `/changelog/0.1.0` resolve to the changelog UI.
- Version display: the footer shows the current `VERSION`.
- Not-found: unknown routes render the not-found state.
- Responsive navigation: mobile menu toggle exposes the same nav items.
- Keyboard navigation: nav links and the mobile menu toggle are reachable via keyboard.
