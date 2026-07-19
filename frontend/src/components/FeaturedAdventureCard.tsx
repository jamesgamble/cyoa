import { Link } from "react-router-dom";
import type { AdventureSummary } from "./AdventureCard";

interface Props {
  adventure: AdventureSummary;
  to?: string;
}

/**
 * Featured adventure card — a wider editorial display for the front
 * page. Larger title, ornament rule, and a call to enter. Presentation
 * is fixed; user content is text-only.
 */
export function FeaturedAdventureCard({ adventure, to }: Props) {
  const href = to ?? `/adventure/${adventure.slug}`;
  return (
    <article className="bp-adv-card bp-adv-card--featured" data-testid="featured-adventure-card">
      <p className="bp-adv-card__eyebrow">Featured adventure</p>
      <h2 className="bp-adv-card__title bp-adv-card__title--display">
        <Link to={href}>{adventure.title}</Link>
      </h2>
      <p className="bp-adv-card__byline">by {adventure.author}</p>
      {adventure.synopsis && (
        <p className="bp-adv-card__synopsis">{adventure.synopsis}</p>
      )}
      <span className="bp-ornament" aria-hidden="true" />
      <p>
        <Link to={href} className="bp-btn bp-btn--primary">Enter the story</Link>
      </p>
    </article>
  );
}
