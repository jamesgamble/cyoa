/**
 * Help topics for Branching Paths.
 *
 * Each topic describes ONLY behaviour that is actually implemented at the
 * current version. Topics that will only become truthful once a later
 * release ships (payments, notifications, real accounts, etc.) explicitly
 * label the affected paragraphs as "planned" rather than describing them
 * as if they already worked.
 *
 * The `match` block lets the contextual drawer pick which topics to
 * surface first based on route, sign-in state, role, adventure status, or
 * the current section a page opts into via `useHelpContext`.
 */
import type { StoryStatus } from "../components/AdventureCard";

export type HelpRole = "reader" | "author" | "master";

export interface HelpMatch {
  /** URL path patterns that mark this topic as relevant. */
  routes?: RegExp[];
  /** Roles that should see this topic promoted. */
  roles?: HelpRole[];
  /** True/false to require or forbid a signed-in visitor. */
  signedIn?: boolean;
  /** Adventure statuses this topic is written for. */
  adventureStatus?: StoryStatus[];
  /** Section labels a page can push via `useHelpContext`. */
  sections?: string[];
}

export interface HelpTopic {
  slug: string;
  title: string;
  /** One-sentence summary shown in the drawer and the topic index. */
  summary: string;
  /** Full body — every entry becomes a paragraph on the topic page. */
  body: string[];
  /** Slugs of related topics linked at the foot of the topic page. */
  related: string[];
  /** Contextual matcher used by `pickContextualTopics`. */
  match: HelpMatch;
}

export const HELP_TOPICS: HelpTopic[] = [
  {
    slug: "getting-started",
    title: "Getting started",
    summary:
      "What Branching Paths is, what you can do without an account, and where to go next.",
    body: [
      "Branching Paths is a collaborative choose-your-own-adventure library. Readers follow numbered choices through branching stories; writers publish adventures and, when the creator allows it, contribute new branches to other people's stories.",
      "Reading does not require an account. Open Discover, pick an adventure, and start reading. Your reading position, bookmarks, and discovered endings are stored only in your browser.",
      "Creating an adventure and contributing branches will require an account. Account creation, sign-in, and the writing tools are planned for later releases; today the Create and Contribute pages describe what is coming without collecting any personal information.",
      "The three primary actions on the home page are: Read an adventure, Create an adventure, and Continue someone else's story. Each links to the surface where that flow lives.",
    ],
    related: ["discovering", "reading", "accounts"],
    match: { routes: [/^\/$/, /^\/start$/], sections: ["home"] },
  },
  {
    slug: "discovering",
    title: "Discovering adventures",
    summary:
      "How to search, filter, sort, and share a specific view of the library.",
    body: [
      "The Discover page lists every published adventure. Use the search field to look for a title, author, or a phrase from a synopsis, and combine it with filters for genre, content rating, adventure status, and contribution status.",
      "Sort by recently updated, newest, title, scene count, or ending count. Every choice you make is stored in the page URL, so you can bookmark or share a specific view of the library.",
      "On narrow screens, tap Filter & sort to open the filter panel. Use Clear filters to return to the default view. An empty-result state appears when no adventure matches, with a shortcut to clear filters.",
      "Branching Paths intentionally does not display popularity scores, public ratings, likes, comments, or follower counts. Discovery is by content, not by numbers.",
    ],
    related: ["reading", "getting-started"],
    match: { routes: [/^\/discover/], sections: ["discover"] },
  },
  {
    slug: "reading",
    title: "Reading an adventure",
    summary:
      "How scenes, choices, endings, and local progress work in the reader.",
    body: [
      "The reader lives at /adventure/<slug>/read/<scene>. Each scene shows the adventure title, an optional chapter label, the scene title, the story body, a scene number, and either a numbered set of choices or an ending panel.",
      "Choose a numbered option to move to the next scene. Use Back one scene to step back through your reading trail, or Restart to return to the first scene. The Story map action opens the branching diagram for the adventure.",
      "Bookmark a scene to flag it for later; the bookmark is stored only in your browser. Add a branch appears when the adventure is accepting contributions; Report and Help are always available.",
      "Reading progress, bookmarks, and discovered endings are per-adventure and per-browser. Clearing local progress from the reader erases every trace of your reading for that adventure and cannot be undone.",
      "When you reach an ending, the reader tells you how many endings you have discovered so far, offers Explore another path back to your most recent branching scene, and lets you restart from the beginning.",
    ],
    related: ["discovering", "contributing", "common-errors"],
    match: {
      routes: [/^\/adventure\/[^/]+\/read/, /^\/adventure\/[^/]+$/],
      sections: ["reader", "adventure"],
    },
  },
  {
    slug: "creating",
    title: "Creating an adventure",
    summary:
      "What creating an adventure will involve once the writing tools ship.",
    body: [
      "Creating an adventure is planned for a later release. This topic exists so the shape of that flow is clear before the writing tools land.",
      "An adventure will have a title, a description, a genre, a content rating, optional content warnings, and writing guidelines describing the tone and rules the creator expects contributors to follow.",
      "Adventure status describes the story itself: In progress, Complete, On hold, or Archived. Contribution status describes how new branches are accepted: Immediate publishing, Approval required, or Closed.",
      "Until the writing tools ship, the Create page describes what is coming and does not collect any content. No account is required to view it.",
    ],
    related: ["contributing", "managing-adventures", "accounts"],
    match: {
      routes: [/^\/start/, /^\/account/],
      sections: ["create"],
      roles: ["author"],
    },
  },
  {
    slug: "contributing",
    title: "Contributing a branch",
    summary:
      "How new branches will attach to existing stories, and what the three contribution states mean.",
    body: [
      "Contributing a branch means writing a new scene (and, if you like, further scenes and an ending) that attaches to an existing scene in someone else's adventure. The contribution flow is planned for a later release.",
      "Every adventure declares a contribution status: Immediate publishing means approved contributors publish directly; Approval required means every contribution is queued for the creator's review; Closed means the adventure is not accepting new branches at all.",
      "The Add a branch action appears in the reader when the adventure's contribution status is not Closed. Following the link today opens a placeholder page that describes the shape of the coming flow.",
      "Contributions are not overwrites. A branch extends a story from an existing scene; the scene you branched from is not modified, and the original path stays intact.",
    ],
    related: ["reading", "creating", "moderation"],
    match: {
      routes: [/^\/adventure\/[^/]+\/branch/, /^\/adventure\/[^/]+\/read/],
      sections: ["contribute"],
    },
  },
  {
    slug: "accounts",
    title: "Accounts",
    summary:
      "How to register an account, what it currently unlocks, and what is still planned.",
    body: [
      "You can register an account today at /register. Registration collects an email address, a username, a display name, a password, a confirmation of that password, and your acceptance of the community guidelines. Reading, searching, and bookmarking continue to work without an account.",
      "Email and username are matched case-insensitively. \"Alice\" and \"alice\" are the same account; so are \"Alice@Example.com\" and \"alice@example.com\". The username must be between three and thirty-two characters and may contain letters, digits, hyphens, and underscores. Passwords must meet the minimum length set by the operator (twelve characters by default).",
      "After you submit the form the site always shows the same acknowledgement whether the email or username was new, was already taken, or triggered the spam-protection honeypot. This is deliberate: the endpoint is designed so that it cannot be used to check whether a specific email or username exists.",
      "The operator can require email verification, administrator approval, or both before an account becomes active. Email delivery and sign-in are planned for later releases, so today the account is stored in a pending state and Follow, Manage, and Master areas remain placeholders.",
      "If registration is temporarily closed, the /register page will say so. If too many attempts come from your network in a short time, the site will pause new registrations from that network for an hour.",
    ],
    related: ["privacy-and-security", "managing-adventures", "getting-started"],
    match: {
      routes: [/^\/account/, /^\/login/, /^\/register/],
      sections: ["account", "register"],
      roles: ["author"],
    },
  },

  {
    slug: "managing-adventures",
    title: "Managing adventures",
    summary:
      "What creators will do in the Manage area to shepherd their stories.",
    body: [
      "The Manage area at /manage/<slug> is where a creator will shape one of their own adventures. Today it is a placeholder that describes the intended surfaces.",
      "Managing an adventure will cover editing the description, changing the adventure and contribution statuses, reviewing pending contributions when Approval required is enabled, and archiving the adventure when it is no longer accepting reads.",
      "A contribution queue will list pending branches with the contributor, the scene they attach to, and the full text. Creators will be able to accept, request changes, or decline each one.",
      "A danger zone will house destructive actions such as archiving or deleting an adventure. These actions will require an explicit confirmation.",
    ],
    related: ["creating", "moderation", "accounts"],
    match: {
      routes: [/^\/manage/],
      sections: ["manage"],
      roles: ["author", "master"],
    },
  },
  {
    slug: "moderation",
    title: "Moderation",
    summary:
      "How master administrators will keep the library healthy, and how reports work.",
    body: [
      "Moderation is handled by master administrators from the Master area at /master. Today the area is a placeholder that describes the coming queues and actions.",
      "Reports made from the reader will land in a moderation queue with the scene, the adventure, and the reason. Master administrators will be able to review the report, contact the creator, hide or remove the scene, and record the outcome.",
      "The community guidelines describe what is allowed: good-faith contributions, respect for other authors, no harassment, no illegal content, no impersonation, no spam, and clear attribution. Reports that describe violations of these guidelines are prioritised.",
      "Master credentials will never appear in a URL, in help content, or in any diagnostic surface. The Master login page is the only place they are entered.",
    ],
    related: ["managing-adventures", "privacy-and-security", "common-errors"],
    match: { routes: [/^\/master/], sections: ["master"], roles: ["master"] },
  },
  {
    slug: "privacy-and-security",
    title: "Privacy and security",
    summary:
      "What data Branching Paths stores locally today, and how future account data will be handled.",
    body: [
      "Reading today does not require an account. Reading position, bookmarks, and discovered endings are stored only in your browser under the key bp-progress:<adventure>. Clearing local progress from the reader erases every trace for that adventure and cannot be undone.",
      "No analytics, tracking pixels, or third-party scripts are loaded by the reading surfaces. The design system uses only same-origin CSS.",
      "When accounts ship, the full privacy notice will be published alongside them. It will describe what fields are collected, how they are used, how long they are kept, and how to delete them.",
      "Credentials are never included in any URL. If you land on a help URL that contains a password or token in its query string or fragment, treat it as a bug and report it — Branching Paths does not construct such URLs.",
    ],
    related: ["accounts", "moderation", "common-errors"],
    match: {
      routes: [/^\/account/, /^\/login/, /^\/register/, /^\/master/],
      sections: ["privacy"],
    },
  },
  {
    slug: "changelog",
    title: "Changelog",
    summary:
      "Where to find what changed in each release, and how versions are labelled.",
    body: [
      "The Changelog page at /changelog lists every published version of Branching Paths, newest first. Each entry links to a per-version page with the full list of added, changed, and removed items.",
      "Versions follow semantic versioning: 0.MINOR.PATCH during pre-release, and 1.0.0 marks the first production-ready cut. The current version is displayed in the footer on every page and links to its own changelog entry.",
      "New behaviour is described in the changelog only after the code that implements it ships. If a topic in help mentions something as planned, look for its release in the changelog to confirm the behaviour is live.",
    ],
    related: ["getting-started", "common-errors"],
    match: { routes: [/^\/changelog/], sections: ["changelog"] },
  },
  {
    slug: "common-errors",
    title: "Common errors",
    summary:
      "What the states you might run into mean, and how to recover from them.",
    body: [
      "Adventure not found: the URL points to a slug the library does not know. This usually means the adventure was renamed or removed. Return to Discover and search again.",
      "Scene not found: the reader URL references a scene that is not part of the current adventure. This can happen after a scene is renamed. Use Start from the beginning on the not-found panel to reset your position.",
      "This adventure has no readable scenes yet: the creator has not published any scenes. Check back after the next update; the adventure page shows when it was last updated.",
      "Help topic not found: the topic slug in the URL is unknown. Use the Help index or the search field on the Help page to locate the topic you want.",
      "If a page keeps failing, clear the local progress for that adventure from the reader and reload. Reading data lives only in your browser and is safe to reset.",
    ],
    related: ["reading", "discovering", "privacy-and-security"],
    match: { sections: ["error"] },
  },
  {
    slug: "hosting-and-health",
    title: "Hosting and health",
    summary:
      "How Branching Paths runs on plain PHP and SQLite, and what the health endpoint exposes.",
    body: [
      "Branching Paths runs on plain PHP 8.2 or newer with a single SQLite database. There is no external cache, queue, or third-party service required to serve the site.",
      "The SQLite database is opened in WAL mode with foreign keys enforced, a ten-second busy timeout, and synchronous journaling in NORMAL mode. Writers acquire a bounded file-based lock so a stalled writer cannot block the site indefinitely.",
      "The /api/health endpoint reports only four things: whether the API is responsive, whether the database is reachable, the current schema version, and the deployed application version. It never exposes filesystem paths, secret values, stack traces, or raw SQL error text.",
      "Operators can run scripts/system-check.php on the server for a fuller pre-flight report covering PHP extensions, filesystem permissions, and lock behaviour. That script is intentionally CLI-only and is never reachable over HTTP.",
    ],
    related: ["privacy-and-security", "common-errors", "changelog"],
    match: { sections: ["hosting", "health"] },
  },
  {
    slug: "public-data-and-visibility",
    title: "Public data and visibility",
    summary:
      "What Branching Paths shows publicly, what stays hidden, and how the reader falls back to fixtures when the API is unavailable.",
    body: [
      "Only adventures whose state is published, on hold, complete, or archived are readable publicly. Drafts and suspended adventures are never returned by the public API and cannot be discovered by browsing.",
      "The Discover list additionally excludes unlisted adventures, which remain readable at their direct link. Hidden and draft scenes are never returned, and choices whose destination is not a published scene are removed from the response so unpublished scene identifiers cannot be inferred.",
      "The public pages render immediately from typed fixtures and then upgrade to live API data when it arrives. If the backend is unavailable, the fixtures remain visible so reading is not blocked.",
    ],
    related: ["reading", "discovering", "hosting-and-health"],
    match: { sections: ["discover", "adventure", "reader"] },
  },
];

/** Topic slugs surfaced first when no more specific context matches. */
const FALLBACK_ORDER = [
  "getting-started",
  "discovering",
  "reading",
  "changelog",
  "common-errors",
];

export interface HelpContext {
  pathname: string;
  signedIn: boolean;
  role: HelpRole;
  section?: string;
  adventureStatus?: StoryStatus;
  setting?: string;
}

/**
 * Score topics against the current context and return them best-first.
 * The scoring is intentionally simple and deterministic so the tests can
 * assert the exact order that a given context produces.
 */
export function pickContextualTopics(
  context: HelpContext,
  limit = 4,
): HelpTopic[] {
  const scored = HELP_TOPICS.map((topic) => {
    let score = 0;
    const m = topic.match;
    if (m.routes?.some((re) => re.test(context.pathname))) score += 8;
    if (context.section && m.sections?.includes(context.section)) score += 6;
    if (m.roles?.includes(context.role)) score += 3;
    if (typeof m.signedIn === "boolean" && m.signedIn === context.signedIn) {
      score += 1;
    }
    if (
      context.adventureStatus &&
      m.adventureStatus?.includes(context.adventureStatus)
    ) {
      score += 2;
    }
    // Fallback ordering nudges the general topics up when nothing else matches.
    const fallbackIndex = FALLBACK_ORDER.indexOf(topic.slug);
    if (fallbackIndex >= 0) score += 0.1 * (FALLBACK_ORDER.length - fallbackIndex);
    return { topic, score };
  });
  scored.sort((a, b) => b.score - a.score);
  return scored.slice(0, limit).map((s) => s.topic);
}

export function findTopic(slug: string | undefined): HelpTopic | undefined {
  if (!slug) return undefined;
  return HELP_TOPICS.find((t) => t.slug === slug);
}

/**
 * Strip anything that could carry credentials or session state from a
 * pathname before it is used to build a help URL. Query strings and
 * hash fragments are removed; the pathname is returned as-is.
 */
export function sanitizeHelpPath(pathname: string): string {
  const noHash = pathname.split("#")[0] ?? "";
  const noQuery = noHash.split("?")[0] ?? "";
  return noQuery;
}

/**
 * Simple keyword search over titles, summaries, and bodies. Case- and
 * diacritics-tolerant; every whitespace-separated term must appear in
 * at least one of the searched fields.
 */
export function searchHelpTopics(query: string): HelpTopic[] {
  const q = query.trim().toLowerCase();
  if (!q) return HELP_TOPICS;
  const terms = q.split(/\s+/).filter(Boolean);
  return HELP_TOPICS.filter((topic) => {
    const haystack = [
      topic.title,
      topic.summary,
      topic.body.join(" "),
      topic.slug,
    ]
      .join(" ")
      .toLowerCase();
    return terms.every((t) => haystack.includes(t));
  });
}
