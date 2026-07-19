import { Link } from "react-router-dom";
import { Badge } from "./Badge";

export interface AdventureSummary {
  slug: string;
  title: string;
  author: string;
  synopsis?: string;
  status?: "draft" | "published" | "review";
  sceneCount?: number;
  updatedAt?: string;
}

interface Props {
  adventure: AdventureSummary;
  /** Optional href override. Defaults to /adventure/:slug. */
  to?: string;
}

/**
 * Adventure card — a compact catalogue entry. Title, author, and a
 * short synopsis. Fixed layout; user content shown as plain text.
 */
export function AdventureCard({ adventure, to }: Props) {
  const href = to ?? `/adventure/${adventure.slug}`;
  return (
    <article className="bp-adv-card" data-testid="adventure-card">
      <div className="bp-adv-card__head">
        <h3 className="bp-adv-card__title">
          <Link to={href}>{adventure.title}</Link>
        </h3>
        {adventure.status && (
          <Badge tone={adventure.status}>{adventure.status}</Badge>
        )}
      </div>
      <p className="bp-adv-card__byline">by {adventure.author}</p>
      {adventure.synopsis && (
        <p className="bp-adv-card__synopsis">{adventure.synopsis}</p>
      )}
      {(adventure.sceneCount !== undefined || adventure.updatedAt) && (
        <p className="bp-adv-card__meta">
          {adventure.sceneCount !== undefined && (
            <span>{adventure.sceneCount} scenes</span>
          )}
          {adventure.sceneCount !== undefined && adventure.updatedAt && (
            <span aria-hidden="true"> · </span>
          )}
          {adventure.updatedAt && <span>Updated {adventure.updatedAt}</span>}
        </p>
      )}
    </article>
  );
}
