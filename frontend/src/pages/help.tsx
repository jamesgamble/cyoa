import { Link, useParams } from "react-router-dom";

const TOPICS: Record<string, { title: string; body: string }> = {
  "getting-started": {
    title: "Getting started",
    body: "Branching Paths is a collaborative choose-your-own-adventure library. This help topic will expand as features ship.",
  },
  "community-guidelines": {
    title: "Community guidelines",
    body: "Community guidelines will be documented as contribution features are introduced.",
  },
  privacy: {
    title: "Privacy",
    body: "The full privacy notice will be published alongside the account system.",
  },
  about: {
    title: "About Branching Paths",
    body: "Branching Paths is a self-contained application for hosting collaborative adventures.",
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
      <p>{entry.body}</p>
      <p><Link to="/help">Back to help</Link></p>
    </section>
  );
}
