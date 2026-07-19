import { Link, useParams } from "react-router-dom";
import { CHANGELOG, findChangelogEntry, type ChangelogEntry } from "../data/changelog";

function EntryBlock({ entry }: { entry: ChangelogEntry }) {
  return (
    <article className="bp-card" aria-labelledby={`v-${entry.version}`}>
      <h2 id={`v-${entry.version}`}>
        <Link to={`/changelog/${entry.version}`}>Version {entry.version}</Link>{" "}
        <small style={{ color: "var(--bp-muted)" }}>— {entry.date}</small>
      </h2>
      {Object.entries(entry.sections).map(([section, items]) => (
        <div key={section}>
          <h3>{section}</h3>
          <ul>
            {items!.map((item, i) => (
              <li key={i}>{item}</li>
            ))}
          </ul>
        </div>
      ))}
    </article>
  );
}

export function ChangelogIndex() {
  return (
    <section>
      <h1>Changelog</h1>
      <p>Newest releases first.</p>
      {CHANGELOG.map((e) => (
        <EntryBlock key={e.version} entry={e} />
      ))}
    </section>
  );
}

export function ChangelogVersion() {
  const { version } = useParams();
  const entry = version ? findChangelogEntry(version) : undefined;
  if (!entry) {
    return (
      <section>
        <h1>Version not found</h1>
        <p>No changelog entry exists for that version.</p>
        <p><Link to="/changelog">Back to changelog</Link></p>
      </section>
    );
  }
  return (
    <section>
      <p><Link to="/changelog">← All releases</Link></p>
      <EntryBlock entry={entry} />
    </section>
  );
}
