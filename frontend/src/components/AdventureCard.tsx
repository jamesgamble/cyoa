import { Link } from "react-router-dom";
import { Badge } from "./Badge";

export type AdventureStatus = "draft" | "published" | "review" | "archived";
export type ContentRating = "everyone" | "teen" | "mature";
export type Genre =
  | "fantasy"
  | "science-fiction"
  | "mystery"
  | "horror"
  | "romance"
  | "historical"
  | "contemporary"
  | "folklore";

export interface AdventureSummary {
  slug: string;
  title: string;
  author: string;
  synopsis?: string;
  status?: AdventureStatus;
  sceneCount?: number;
  endingCount?: number;
  genre?: Genre;
  contentRating?: ContentRating;
  /** True when the adventure accepts new branch contributions. */
  contributionsOpen?: boolean;
  /** Editorial phrase, e.g. "yesterday". */
  updatedAt?: string;
  /** ISO-8601 timestamp used for sorting; presentation uses updatedAt. */
  updatedAtIso?: string;
}

const GENRE_LABELS: Record<Genre, string> = {
  fantasy: "Fantasy",
  "science-fiction": "Science fiction",
  mystery: "Mystery",
  horror: "Horror",
  romance: "Romance",
  historical: "Historical",
  contemporary: "Contemporary",
  folklore: "Folklore",
};

const RATING_LABELS: Record<ContentRating, string> = {
  everyone: "Everyone",
  teen: "Teen",
  mature: "Mature",
};

export const GENRE_OPTIONS: ReadonlyArray<{ value: Genre; label: string }> =
  Object.entries(GENRE_LABELS).map(([value, label]) => ({
    value: value as Genre,
    label,
  }));

export const RATING_OPTIONS: ReadonlyArray<{
  value: ContentRating;
  label: string;
}> = Object.entries(RATING_LABELS).map(([value, label]) => ({
  value: value as ContentRating,
  label,
}));

export function genreLabel(g: Genre): string {
  return GENRE_LABELS[g];
}
export function ratingLabel(r: ContentRating): string {
  return RATING_LABELS[r];
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
  const {
    title,
    author,
    synopsis,
    status,
    sceneCount,
    endingCount,
    genre,
    contentRating,
    contributionsOpen,
    updatedAt,
  } = adventure;
  return (
    <article
      className="bp-adv-card"
      data-testid="adventure-card"
      data-slug={adventure.slug}
    >
      <div className="bp-adv-card__head">
        <h3 className="bp-adv-card__title">
          <Link to={href}>{title}</Link>
        </h3>
        {status && <Badge tone={status}>{status}</Badge>}
      </div>
      <p className="bp-adv-card__byline">by {author}</p>
      {synopsis && <p className="bp-adv-card__synopsis">{synopsis}</p>}
      {(genre || contentRating || contributionsOpen !== undefined) && (
        <p className="bp-adv-card__tags" data-testid="adventure-card-tags">
          {genre && (
            <span className="bp-tag" data-testid="tag-genre">
              {genreLabel(genre)}
            </span>
          )}
          {contentRating && (
            <span className="bp-tag" data-testid="tag-rating">
              {ratingLabel(contentRating)}
            </span>
          )}
          {contributionsOpen !== undefined && (
            <span
              className={
                "bp-tag" +
                (contributionsOpen ? " bp-tag--accent" : " bp-tag--muted")
              }
              data-testid="tag-contributions"
            >
              {contributionsOpen ? "Contributions open" : "Contributions closed"}
            </span>
          )}
        </p>
      )}
      {(sceneCount !== undefined ||
        endingCount !== undefined ||
        updatedAt) && (
        <p className="bp-adv-card__meta">
          {sceneCount !== undefined && (
            <span data-testid="meta-scenes">{sceneCount} scenes</span>
          )}
          {sceneCount !== undefined && endingCount !== undefined && (
            <span aria-hidden="true"> · </span>
          )}
          {endingCount !== undefined && (
            <span data-testid="meta-endings">{endingCount} endings</span>
          )}
          {(sceneCount !== undefined || endingCount !== undefined) &&
            updatedAt && <span aria-hidden="true"> · </span>}
          {updatedAt && (
            <span data-testid="meta-updated">Updated {updatedAt}</span>
          )}
        </p>
      )}
    </article>
  );
}
