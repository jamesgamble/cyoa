/**
 * Discover-page fixtures.
 *
 * Fixed sample library used by the Discover page. Once the backend ships,
 * the same shape (`AdventureSummary`) is returned by the API.
 */
import type { AdventureSummary } from "../components/AdventureCard";

export const DISCOVER_ADVENTURES: ReadonlyArray<AdventureSummary> = [
  {
    slug: "the-lantern-road",
    title: "The Lantern Road",
    author: "Marisol Vega",
    synopsis:
      "A cartographer's apprentice inherits a lantern that only lights paths that have not yet been walked.",
    description:
      "Elin has walked the same trade road for six years. When her mentor dies, she inherits a brass lantern that will only light a path no living cartographer has drawn. This is a story about the maps we keep, the ones we lose, and the ones we should never have made.",
    status: "published",
    storyStatus: "in-progress",
    genre: "fantasy",
    contentRating: "everyone",
    sceneCount: 42,
    endingCount: 6,
    contributionsOpen: true,
    contributionState: "approval",
    contentWarnings: ["Grief", "Non-graphic peril"],
    writingGuidelines:
      "Keep the geography internally consistent. New paths should lead somewhere; dead ends are for endings, not for scenes. No graphic violence.",
    updatedAt: "three days ago",
    updatedAtIso: "2026-07-16T09:00:00Z",
  },
  {
    slug: "the-clockmakers-daughter",
    title: "The Clockmaker's Daughter",
    author: "Rowan Ash",
    synopsis:
      "A village keeps time by a single tower. When its hands stop, only one apprentice has a key to the workings.",
    description:
      "The village of Hesse has kept its hours by the tower clock for four hundred years. When the hands stop between one afternoon and the next, only Ivo, the clockmaker's apprentice, can climb the shaft — and only he knows the shape of what waits at the top.",
    status: "published",
    storyStatus: "in-progress",
    genre: "mystery",
    contentRating: "everyone",
    sceneCount: 24,
    endingCount: 4,
    contributionsOpen: true,
    contributionState: "immediate",
    contentWarnings: ["Mild claustrophobia"],
    writingGuidelines:
      "Every scene should turn on a small mechanical detail. Prefer craft to spectacle. Contributions are published immediately; please self-edit for typography.",
    updatedAt: "yesterday",
    updatedAtIso: "2026-07-18T14:00:00Z",
  },
  {
    slug: "salt-and-signal",
    title: "Salt and Signal",
    author: "Fen Okafor",
    synopsis:
      "A lighthouse keeper receives a letter that could not have been posted. Every reply changes the shore.",
    description:
      "A lighthouse keeper on the south coast receives a letter dated four years before her posting. Each reply she drafts alters the shoreline outside her window. This story is complete; the branches published here are the finished library.",
    status: "published",
    storyStatus: "complete",
    genre: "mystery",
    contentRating: "teen",
    sceneCount: 18,
    endingCount: 3,
    contributionsOpen: false,
    contributionState: "closed",
    contentWarnings: ["Grief", "Implied bereavement"],
    updatedAt: "two days ago",
    updatedAtIso: "2026-07-17T11:00:00Z",
  },
  {
    slug: "the-orchard-below",
    title: "The Orchard Below",
    author: "Ines Marchetti",
    synopsis:
      "The town's founders planted an orchard the wrong way up. Its fruit has begun to ripen.",
    description:
      "Beneath the founders' hill, the orchard grows the wrong way up. The fruit is ready. The town has a hundred small customs for a season that has never happened in living memory.",
    status: "published",
    storyStatus: "in-progress",
    genre: "folklore",
    contentRating: "teen",
    sceneCount: 31,
    endingCount: 5,
    contributionsOpen: true,
    contributionState: "approval",
    contentWarnings: ["Body horror (mild)", "Ritual imagery"],
    writingGuidelines:
      "Contributions should preserve the folkloric register — plain, unhurried sentences. Approval usually within a week.",
    updatedAt: "four days ago",
    updatedAtIso: "2026-07-15T08:00:00Z",
  },
  {
    slug: "a-quiet-cartography",
    title: "A Quiet Cartography",
    author: "Jonas Bell",
    synopsis:
      "A retired surveyor is asked to map a house whose rooms do not stay in place. The commission pays in memories.",
    description:
      "Sten has retired to a shore town to forget his last commission. A letter offers a new one: map a house whose rooms rearrange between visits. Payment is in memories he has not yet had.",
    status: "published",
    storyStatus: "on-hold",
    genre: "fantasy",
    contentRating: "everyone",
    sceneCount: 27,
    endingCount: 4,
    contributionsOpen: false,
    contributionState: "closed",
    contentWarnings: ["Memory loss"],
    updatedAt: "a week ago",
    updatedAtIso: "2026-07-12T10:00:00Z",
  },
  {
    slug: "the-second-shore",
    title: "The Second Shore",
    author: "Ada Meunier",
    synopsis:
      "A generation ship approaches a planet already claimed by another version of its own crew.",
    description:
      "The Meridian has been in transit for two hundred and forty years. Its target world is populated — by another version of its own crew, who arrived first on a ship that left later. Both crews now share a horizon.",
    status: "published",
    storyStatus: "in-progress",
    genre: "science-fiction",
    contentRating: "teen",
    sceneCount: 55,
    endingCount: 8,
    contributionsOpen: true,
    contributionState: "immediate",
    contentWarnings: ["Existential themes"],
    writingGuidelines:
      "New branches may add crew members and rooms aboard either ship. Do not rewrite established scene text; extend, never overwrite.",
    updatedAt: "five days ago",
    updatedAtIso: "2026-07-14T12:00:00Z",
  },
  {
    slug: "the-hollow-suite",
    title: "The Hollow Suite",
    author: "Priya Ndlovu",
    synopsis:
      "The concierge of an old hotel holds the room key for a suite that no floor plan admits.",
    description:
      "The Grand Meridien has forty-two rooms across four floors, and one suite that no floor plan admits. The concierge holds its key. This adventure is in editorial review.",
    status: "review",
    storyStatus: "in-progress",
    genre: "horror",
    contentRating: "mature",
    sceneCount: 12,
    endingCount: 2,
    contributionsOpen: false,
    contributionState: "closed",
    contentWarnings: [
      "Psychological horror",
      "Isolation",
      "Non-graphic violence",
    ],
    updatedAt: "six days ago",
    updatedAtIso: "2026-07-13T18:00:00Z",
  },
  {
    slug: "letters-from-the-canal",
    title: "Letters from the Canal",
    author: "Otto Lindqvist",
    synopsis:
      "A canal boat carries a correspondence between two houses that never write directly.",
    description:
      "The Ulla and the Vederstad have never written directly. For six years, a canal boat has carried their letters through six intermediaries. This is an epistolary adventure told through the boat's ledger.",
    status: "published",
    storyStatus: "in-progress",
    genre: "historical",
    contentRating: "everyone",
    sceneCount: 21,
    endingCount: 3,
    contributionsOpen: true,
    contributionState: "approval",
    contentWarnings: [],
    writingGuidelines:
      "Contributions should read as period letters. Dates should stay within 1893–1899. Approval within two weeks.",
    updatedAt: "ten days ago",
    updatedAtIso: "2026-07-09T09:00:00Z",
  },
  {
    slug: "a-fair-hand",
    title: "A Fair Hand",
    author: "Camille Doré",
    synopsis:
      "Two apprentices share a single letter of introduction and only one interview.",
    description:
      "Iren and Célie share a letter of introduction, a set of practice pieces, and a single interview at the Marchand studio. Only one seat is on offer.",
    status: "published",
    storyStatus: "complete",
    genre: "romance",
    contentRating: "teen",
    sceneCount: 16,
    endingCount: 4,
    contributionsOpen: true,
    contributionState: "immediate",
    contentWarnings: [],
    writingGuidelines:
      "Branches should keep both apprentices' voices distinct. No explicit content.",
    updatedAt: "two weeks ago",
    updatedAtIso: "2026-07-05T09:00:00Z",
  },
  {
    slug: "the-market-of-quiet-things",
    title: "The Market of Quiet Things",
    author: "Bo Nakamura",
    synopsis:
      "A weekly market sells objects that once belonged to lives never lived.",
    description:
      "Every seventh evening the Quiet Market opens under the river bridge. Its stalls sell objects that belonged to lives no one has lived. This story is complete.",
    status: "published",
    storyStatus: "complete",
    genre: "contemporary",
    contentRating: "everyone",
    sceneCount: 33,
    endingCount: 6,
    contributionsOpen: false,
    contributionState: "closed",
    contentWarnings: ["Melancholy themes"],
    updatedAt: "eleven days ago",
    updatedAtIso: "2026-07-08T09:00:00Z",
  },
  {
    slug: "beneath-the-observatory",
    title: "Beneath the Observatory",
    author: "Hana Petrescu",
    synopsis:
      "Under an old observatory sits a second telescope pointed at nothing anyone can name.",
    description:
      "The Krajen observatory has one telescope on its roof and another, older, sealed in its cellar. The second telescope is pointed at nothing anyone can name. This adventure is a draft.",
    status: "draft",
    storyStatus: "in-progress",
    genre: "science-fiction",
    contentRating: "everyone",
    sceneCount: 8,
    endingCount: 1,
    contributionsOpen: true,
    contributionState: "approval",
    contentWarnings: [],
    writingGuidelines:
      "Draft adventure — expect editorial changes. Contribute at your own risk.",
    updatedAt: "yesterday",
    updatedAtIso: "2026-07-18T20:00:00Z",
  },
  {
    slug: "the-inn-at-the-crossing",
    title: "The Inn at the Crossing",
    author: "Sasha Belov",
    synopsis:
      "Three roads meet at an inn whose ledger records travellers before they arrive.",
    description:
      "The Three Kings stands where the old northern roads meet. Its ledger records travellers a day before they arrive. This adventure has been archived and remains readable.",
    status: "archived",
    storyStatus: "archived",
    genre: "fantasy",
    contentRating: "everyone",
    sceneCount: 44,
    endingCount: 7,
    contributionsOpen: false,
    contributionState: "closed",
    contentWarnings: [],
    updatedAt: "a month ago",
    updatedAtIso: "2026-06-18T09:00:00Z",
  },
];

/** Look up an adventure by slug from the discover fixtures. */
export function findAdventureBySlug(
  slug: string,
): AdventureSummary | undefined {
  return DISCOVER_ADVENTURES.find((a) => a.slug === slug);
}
