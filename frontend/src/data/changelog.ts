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
