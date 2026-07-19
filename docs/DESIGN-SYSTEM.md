# Design system

Branching Paths presents itself as a modern, original interpretation of a classic choose-your-own-adventure paperback. It is literary, restrained, and readable — never a parchment novelty, medieval-fantasy interface, or generic SaaS dashboard.

## Creative direction

- Warm cream and light parchment surfaces.
- Near-black ink for text.
- Deep muted red as the primary accent.
- Muted antique gold used sparingly for editorial ornaments.
- Restrained forest, navy, and burnt orange as secondary accents (status, alerts).
- Distinctive serif display type for headings; highly readable serif for story text.
- Clean sans-serif for controls and administrative surfaces.
- Subtle CSS-only paper texture. No imagery, no torn edges, no stains, no leather.
- Fine borders, small editorial ornaments, comfortable reading widths, numbered choices.

## Tokens

Design tokens live in `frontend/src/styles/global.css` under `:root`, grouped by:

- Colors — surfaces, ink, accents, statuses, rules.
- Typography — display, story, UI, and mono families; type scale; line heights; tracking.
- Spacing — 4 px base scale from `--bp-s-0` through `--bp-s-10`.
- Borders — radii and hairline / strong border shorthands.
- Shadows — three restrained shadow tokens plus a focus ring.
- Reading widths — reading, content, app, admin.
- Statuses — foreground / background pairs for draft, published, review, warning, danger.

Consume tokens with the `.bp-*` primitives — never hardcode hex or px values in components.

## Primitives

- `Button` (`primary`, `secondary`, `ghost`, `danger`; `md` / `sm`).
- `Badge` (draft / published / review / warning / danger).
- `Alert` (info / success / warning / danger).
- `Dialog` (built on native `<dialog>`).
- `HelpDrawer` (Escape-closable off-canvas panel).
- `Panel` (management surface with header + body).
- Story page classes: `.bp-story`, `.bp-choices`, `.bp-choice`, `.bp-ornament`.
- Administrative tables: `.bp-table`.

## Layout modes

Applied on the `<body>` via `useLayoutMode(mode)` on the layout component:

1. `reading` — public site; cream surfaces, comfortable reading width.
2. `manage` — creation and adventure management; wider container, more UI density.
3. `admin` — master administration; widest container, additional chrome contrast.

## Accessibility

- All interactive elements show a visible focus ring via `:focus-visible`.
- Honors `prefers-reduced-motion: reduce` — transitions are neutralized.
- Honors `prefers-contrast: more` — palette tightens automatically.
- Explicit user overrides: `html[data-contrast="high"]` and `html[data-text-size="large" | "x-large"]`.
- Primary controls meet a 44 × 44 px minimum tap target.
- Dialog and help drawer are keyboard-reachable and Escape-closable.

## Preview

Visit `/design-system` in a running frontend to browse all primitives.
