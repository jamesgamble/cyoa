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
