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
