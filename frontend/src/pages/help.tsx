import { Link, useParams } from "react-router-dom";

const TOPICS: Record<string, { title: string; body: string[] }> = {
  "getting-started": {
    title: "Getting started",
    body: [
      "Branching Paths is a collaborative choose-your-own-adventure library.",
      "You can read any published adventure without an account. Sign up when you would like to write your own or contribute a branch to someone else's story.",
      "This help topic will expand as features ship.",
    ],
  },
  "community-guidelines": {
    title: "Community guidelines",
    body: [
      "Branching Paths is a shared library. These guidelines summarise what we ask of everyone who contributes.",
      "Write in good faith. Contribute scenes and endings you would be glad to read yourself.",
      "Respect other authors. Branches extend a story; they do not overwrite established scenes.",
      "Keep the library readable. No harassment, no illegal content, no impersonation, no spam.",
      "Attribute clearly. Only submit writing that is your own or that you have explicit permission to share.",
      "The full guidelines will grow alongside the contribution system in later releases.",
    ],
  },
  discover: {
    title: "Discovering adventures",
    body: [
      "The Discover page lists every published adventure in the library. Reading does not require an account.",
      "Use the search field to look for a title, author, or a phrase from a synopsis. Combine it with filters for genre, content rating, adventure status, and whether the adventure currently accepts new branch contributions.",
      "Sort the list by recently updated, newest, title, scene count, or ending count. Every choice you make is stored in the page URL, so you can bookmark or share a specific view of the library.",
      "On narrow screens, tap Filter & sort to open the filter panel. Use Clear filters to return to the default view.",
      "Branching Paths intentionally does not display popularity scores, public ratings, likes, comments, or follower counts. Discovery is by content, not by numbers.",
    ],
  },
  "adventure-page": {
    title: "The adventure page",
    body: [
      "Every adventure has a landing page at /adventure/<slug>. It gathers the title, creator, description, genre, content rating, content warnings, and any writing guidelines the creator has published.",
      "Adventure status describes the story itself: In progress, Complete, On hold, or Archived. Contribution status describes how new branches are accepted: Immediate publishing means approved contributors publish directly; Approval required means every contribution is queued for the creator's review; Closed means the story is not accepting new branches.",
      "The page offers Read from beginning, View story map, and — when your browser remembers a scene — Resume reading. Reading works without an account; the resume marker is stored only in your browser. Signed-in visitors see a Follow placeholder that will connect to notifications when the account system ships.",
    ],
  },
  reading: {
    title: "Reading an adventure",
    body: [
      "The reader lives at /adventure/<slug>/read/<scene>. Each scene shows the adventure title, an optional chapter label, the scene title, the story body, a scene number, and either a numbered set of choices or an ending panel.",
      "Choose a numbered option to move to the next scene. Use Back one scene to step back through your reading trail, or Restart to return to the first scene. The Story map action opens the branching diagram for the adventure.",
      "Bookmark a scene to flag it for later; the bookmark is stored only in your browser. Add a branch appears when the adventure is accepting contributions; Report and Help are always available.",
      "Reading progress, bookmarks, and discovered endings are per-adventure and per-browser. Clearing local progress from the reader erases every trace of your reading for that adventure and cannot be undone.",
      "When you reach an ending, the reader tells you how many endings you have discovered so far, offers Explore another path back to your most recent branching scene, and lets you restart from the beginning.",
    ],
  },
  privacy: {
    title: "Privacy",
    body: [
      "The full privacy notice will be published alongside the account system.",
    ],
  },
  about: {
    title: "About Branching Paths",
    body: [
      "Branching Paths is a self-contained application for hosting collaborative adventures.",
    ],
  },
};

export function Help() {
  return (
    <section>
      <h1>Help</h1>
      <p>Choose a topic below.</p>
      <ul>
        {Object.entries(TOPICS).map(([slug, t]) => (
          <li key={slug}>
            <Link to={`/help/${slug}`}>{t.title}</Link>
          </li>
        ))}
      </ul>
    </section>
  );
}

export function HelpTopic() {
  const { topic } = useParams();
  const entry = topic ? TOPICS[topic] : undefined;
  if (!entry) {
    return (
      <section>
        <h1>Help topic not found</h1>
        <p>That topic is not available.</p>
        <p><Link to="/help">Back to help</Link></p>
      </section>
    );
  }
  return (
    <section>
      <h1>{entry.title}</h1>
      {entry.body.map((p, i) => (
        <p key={i}>{p}</p>
      ))}
      <p><Link to="/help">Back to help</Link></p>
    </section>
  );
}
