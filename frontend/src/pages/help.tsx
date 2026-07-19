import { useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import {
  HELP_TOPICS,
  findTopic,
  searchHelpTopics,
} from "../data/helpTopics";

/**
 * Help center: full topic index with a keyword search field.
 */
export function Help() {
  const [query, setQuery] = useState("");
  const results = useMemo(() => searchHelpTopics(query), [query]);
  return (
    <section aria-labelledby="help-heading" data-testid="help-center">
      <h1 id="help-heading">Help</h1>
      <p>
        Every topic below describes behaviour that ships in the current
        release. Planned features are labelled as such.
      </p>
      <form
        role="search"
        aria-label="Search help topics"
        onSubmit={(e) => e.preventDefault()}
        className="bp-help-search"
      >
        <label htmlFor="help-search-input" className="bp-visually-hidden">
          Search help topics
        </label>
        <input
          id="help-search-input"
          type="search"
          className="bp-input"
          placeholder="Search help — for example, reading, contribute, privacy"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          data-testid="help-search-input"
          autoComplete="off"
        />
      </form>
      {results.length === 0 ? (
        <p role="status" data-testid="help-search-empty">
          No topics match “{query}”. Try a shorter keyword or{" "}
          <button
            type="button"
            className="bp-linklike"
            onClick={() => setQuery("")}
          >
            clear the search
          </button>
          .
        </p>
      ) : (
        <ul className="bp-help-index" data-testid="help-index">
          {results.map((t) => (
            <li key={t.slug}>
              <h2>
                <Link to={`/help/${t.slug}`}>{t.title}</Link>
              </h2>
              <p>{t.summary}</p>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/**
 * Individual help topic page with related-topic links.
 */
export function HelpTopic() {
  const { topic } = useParams();
  const entry = findTopic(topic);
  if (!entry) {
    return (
      <section aria-labelledby="help-missing" data-testid="help-topic-missing">
        <h1 id="help-missing">Help topic not found</h1>
        <p>
          That topic is not available. Return to the{" "}
          <Link to="/help">help index</Link> or try one of the general
          topics below.
        </p>
        <ul>
          {HELP_TOPICS.slice(0, 4).map((t) => (
            <li key={t.slug}>
              <Link to={`/help/${t.slug}`}>{t.title}</Link>
            </li>
          ))}
        </ul>
      </section>
    );
  }
  const related = entry.related
    .map(findTopic)
    .filter((t): t is NonNullable<typeof t> => !!t);
  return (
    <section aria-labelledby="help-topic-title" data-testid="help-topic">
      <p className="bp-crumbs">
        <Link to="/help">Help</Link> <span aria-hidden="true">›</span>{" "}
        <span>{entry.title}</span>
      </p>
      <h1 id="help-topic-title">{entry.title}</h1>
      <p className="bp-help-summary">{entry.summary}</p>
      {entry.body.map((p, i) => (
        <p key={i}>{p}</p>
      ))}
      {related.length > 0 && (
        <aside
          aria-labelledby="help-related"
          className="bp-help-related"
          data-testid="help-related"
        >
          <h2 id="help-related">Related topics</h2>
          <ul>
            {related.map((r) => (
              <li key={r.slug}>
                <Link to={`/help/${r.slug}`}>{r.title}</Link>
              </li>
            ))}
          </ul>
        </aside>
      )}
      <p>
        <Link to="/help">Back to help</Link>
      </p>
    </section>
  );
}
