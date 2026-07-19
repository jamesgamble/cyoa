/**
 * Story fixtures — scenes, choices, and endings.
 *
 * v0.7.0: content is stored in typed fixtures so the public reader has
 * something to render before the SQLite-backed authoring system ships.
 * All strings are treated as user-provided data by the reader and are
 * rendered as plain text through the StoryBody / SceneTitle / EndingPanel
 * primitives; the containment guarantees from v0.3.0 apply.
 */

export interface EndingContent {
  /** Ending title — plain text. */
  title: string;
  /** Editorial kind label ("An ending", "A quiet ending", …). Plain text. */
  kind?: string;
  /** Ending prose. Split on blank lines. */
  body: string;
}

export interface StoryChoice {
  /** Displayed choice label — plain text. */
  label: string;
  /** Target scene id within the same adventure. */
  target: string;
}

export interface Scene {
  /** URL-safe identifier, unique within an adventure. */
  id: string;
  /** Adventure slug that owns this scene. */
  adventureSlug: string;
  /** 1-indexed ordinal used for the "Scene N" display. */
  sceneNumber: number;
  /** Optional chapter label ("Chapter I", "Part Two"). Plain text. */
  chapter?: string;
  /** Scene title — plain text. */
  title: string;
  /** Story prose. Split on blank lines. */
  body: string;
  /** Ordered choices leading to other scenes. */
  choices?: StoryChoice[];
  /**
   * Present when the scene ends a branch. A scene may have an ending
   * with no further choices, or an ending plus an "Explore another path"
   * hint (still no choices).
   */
  ending?: EndingContent;
  /** True when this scene is the adventure's canonical opener. */
  isStart?: boolean;
}

// ---------------------------------------------------------------------------
// The Lantern Road — a small but complete branching tree.
//
//   start ──► tin-lantern ──► quiet-shore  (ending: the-lighthouse)
//        │                └─► market ──► canal-bridge  (see below)
//        │                            └─► ending: the-quiet-return
//        └─► canal-bridge ──► market
//                          └─► ending: cold-water
// ---------------------------------------------------------------------------

const LANTERN_ROAD: Scene[] = [
  {
    id: "start",
    adventureSlug: "the-lantern-road",
    sceneNumber: 1,
    chapter: "Chapter I — Setting Out",
    title: "The tin lantern on the sill",
    body:
      "You wake before the harbour bells. A tin lantern sits on the kitchen sill, its flame low and steady, exactly where you did not leave it.\n\nOutside, the lantern-road runs east into fog and west toward the canal bridge. Someone has been walking it in the night.",
    choices: [
      { label: "Take the lantern and follow the east road", target: "tin-lantern" },
      { label: "Walk west to the canal bridge instead", target: "canal-bridge" },
    ],
    isStart: true,
  },
  {
    id: "tin-lantern",
    adventureSlug: "the-lantern-road",
    sceneNumber: 2,
    chapter: "Chapter I — Setting Out",
    title: "East, where the fog thins",
    body:
      "The lantern throws a small circle of warm light across the wet stones. Half a mile in, the road forks: a narrow track drops toward the shore, and a broader lane climbs toward the market square, already awake.",
    choices: [
      { label: "Follow the narrow track down to the quiet shore", target: "quiet-shore" },
      { label: "Climb the lane to the market square", target: "market" },
    ],
  },
  {
    id: "canal-bridge",
    adventureSlug: "the-lantern-road",
    sceneNumber: 3,
    chapter: "Chapter I — Setting Out",
    title: "The canal bridge before dawn",
    body:
      "The bridge is older than the town's own charter. Water moves under it in slow black lines. Someone has left a paper boat on the parapet, ink still wet.\n\nA second lane cuts up from the towpath toward the market square. You could also lean, just a little, over the parapet.",
    choices: [
      { label: "Climb up to the market square", target: "market" },
      { label: "Lean over the parapet and read the paper boat", target: "cold-water-ending" },
    ],
  },
  {
    id: "market",
    adventureSlug: "the-lantern-road",
    sceneNumber: 4,
    chapter: "Chapter II — What the Town Kept",
    title: "The market square, half awake",
    body:
      "Stallholders are stacking crates. A woman is selling tea from a copper urn. She looks at your lantern for a long moment before she pours a cup and refuses your coin.\n\n\"Take the road back the way you did not come,\" she says. \"Or drink, and go home.\"",
    choices: [
      { label: "Walk back down toward the canal bridge", target: "canal-bridge" },
      { label: "Drink the tea and turn for home", target: "quiet-return-ending" },
    ],
  },
  {
    id: "quiet-shore",
    adventureSlug: "the-lantern-road",
    sceneNumber: 5,
    chapter: "Chapter II — What the Town Kept",
    title: "The lighthouse, unlit",
    body:
      "The shore path ends at a lighthouse that has not been lit in a generation. Its door stands open. Inside, a keeper you do not recognise is trimming a wick that is already trimmed.\n\n\"You brought a light,\" she says, without turning. \"Set it in the lamp.\"",
    ending: {
      kind: "A quiet ending",
      title: "The lighthouse, relit",
      body:
        "You place the tin lantern in the great lamp and step back. Its small flame climbs the mirrors and, impossibly, is enough.\n\nThe town below will not know, tonight, that its lighthouse has been lit. But the ships already at sea will, and so will you.",
    },
  },
  {
    id: "cold-water-ending",
    adventureSlug: "the-lantern-road",
    sceneNumber: 6,
    chapter: "Chapter II — What the Town Kept",
    title: "The paper boat",
    body:
      "You lean over the parapet. The ink on the paper boat runs, and rearranges, and spells your own name.\n\nThe lantern slips from your hand into the canal. It floats, briefly, still lit, and then does not.",
    ending: {
      kind: "A cold ending",
      title: "Cold water, small flame",
      body:
        "Some doors close because you leaned too far to read them. The town keeps walking to the bells; the road keeps its shape; only you, and the paper boat, are elsewhere now.",
    },
  },
  {
    id: "quiet-return-ending",
    adventureSlug: "the-lantern-road",
    sceneNumber: 7,
    chapter: "Chapter II — What the Town Kept",
    title: "The tea and the road home",
    body:
      "The tea tastes of nothing you can name and of every kitchen you have ever known. You set the empty cup back on the copper urn.\n\nThe lantern-road, walked in reverse, is a different road. You reach your door before the harbour bells.",
    ending: {
      kind: "A gentle ending",
      title: "The quiet return",
      body:
        "Not every walk has to end at the lighthouse. Some end at your own kitchen sill, where a tin lantern is once again sitting exactly where you did not leave it.",
    },
  },
];

// ---------------------------------------------------------------------------
// The Inn at the Crossing — a very small tree, used to test rendering of
// adventures with only one branch and one ending.
// ---------------------------------------------------------------------------

const INN_AT_CROSSING: Scene[] = [
  {
    id: "start",
    adventureSlug: "the-inn-at-the-crossing",
    sceneNumber: 1,
    title: "The signboard, freshly painted",
    body:
      "You arrive at the crossing after dark. The inn's signboard has been repainted since your last visit, though nobody in the taproom will admit to painting it.",
    choices: [
      { label: "Ask the innkeeper about the sign", target: "innkeeper-ending" },
    ],
    isStart: true,
  },
  {
    id: "innkeeper-ending",
    adventureSlug: "the-inn-at-the-crossing",
    sceneNumber: 2,
    title: "The innkeeper's answer",
    body:
      "The innkeeper wipes her hands on her apron and does not meet your eye. \"Some signs paint themselves,\" she says, \"when the road forgets which way it goes.\"",
    ending: {
      kind: "A short ending",
      title: "Signs that paint themselves",
      body:
        "You take a room for the night. In the morning the signboard reads a different name, and the crossing runs in a direction it did not run yesterday.",
    },
  },
];

/** All scenes across every adventure with fixture content. */
export const SCENES: readonly Scene[] = [...LANTERN_ROAD, ...INN_AT_CROSSING];

/** Scenes that have any fixture content — used by the reader to decide
 *  whether an adventure is currently readable. */
export function hasScenes(adventureSlug: string): boolean {
  return SCENES.some((s) => s.adventureSlug === adventureSlug);
}

/** Return every scene for an adventure, in fixture order. */
export function scenesFor(adventureSlug: string): Scene[] {
  return SCENES.filter((s) => s.adventureSlug === adventureSlug);
}

/** Look up a specific scene. Returns undefined for unknown ids. */
export function findScene(
  adventureSlug: string,
  sceneId: string,
): Scene | undefined {
  return SCENES.find(
    (s) => s.adventureSlug === adventureSlug && s.id === sceneId,
  );
}

/** The canonical opening scene for an adventure, or undefined if none. */
export function startScene(adventureSlug: string): Scene | undefined {
  const list = scenesFor(adventureSlug);
  return list.find((s) => s.isStart) ?? list[0];
}
