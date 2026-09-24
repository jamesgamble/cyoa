/**
 * Public homepage fixtures.
 *
 * Fixed sample data used to compose the homepage before the backend ships.
 * These entries are intentionally literary in tone and match the shape of
 * `AdventureSummary` so cards render identically once real data arrives.
 */
import type { AdventureSummary } from "../components/AdventureCard";
import { FIXTURES_ENABLED } from "./fixtureGate";

export interface FeaturedAdventure extends AdventureSummary {
  synopsis: string;
}

const FEATURED_FIXTURE: FeaturedAdventure = {
  slug: "the-lantern-road",
  title: "The Lantern Road",
  author: "Marisol Vega",
  synopsis:
    "A cartographer's apprentice inherits a lantern that only lights paths that have not yet been walked. The choices begin at the first fork past the harbour.",
  status: "published",
  sceneCount: 42,
  updatedAt: "three days ago",
};

const RECENT_FIXTURES: AdventureSummary[] = [
  {
    slug: "the-clockmakers-daughter",
    title: "The Clockmaker's Daughter",
    author: "Rowan Ash",
    synopsis:
      "A quiet village keeps time by a single tower. When its hands stop, only one apprentice has a key to the workings.",
    status: "published",
    sceneCount: 24,
    updatedAt: "yesterday",
  },
  {
    slug: "salt-and-signal",
    title: "Salt and Signal",
    author: "Fen Okafor",
    synopsis:
      "A lighthouse keeper on a rocky coast receives a letter that could not have been posted. Every reply changes the shore.",
    status: "published",
    sceneCount: 18,
    updatedAt: "two days ago",
  },
  {
    slug: "the-orchard-below",
    title: "The Orchard Below",
    author: "Ines Marchetti",
    synopsis:
      "The town's founders planted an orchard the wrong way up. Its fruit has begun to ripen, and someone must go down to pick it.",
    status: "published",
    sceneCount: 31,
    updatedAt: "four days ago",
  },
  {
    slug: "a-quiet-cartography",
    title: "A Quiet Cartography",
    author: "Jonas Bell",
    synopsis:
      "A retired surveyor is asked to map a house whose rooms do not stay in place. The commission pays in memories.",
    status: "published",
    sceneCount: 27,
    updatedAt: "a week ago",
  },
];

/** Sample homepage content — absent from production builds. */
export const FEATURED_ADVENTURE: FeaturedAdventure | null = FIXTURES_ENABLED ? FEATURED_FIXTURE : null;
export const RECENTLY_UPDATED: AdventureSummary[] = FIXTURES_ENABLED ? RECENT_FIXTURES : [];
