import { Panel } from "./Panel";
import { apiUrl } from "../config/api";

export const EXPORT_FORMATS = [
  { format: "json", label: "JSON data", hint: "Structured data for backups or other tools." },
  { format: "text", label: "Plain text", hint: "Every published scene as numbered text." },
  { format: "print", label: "Printable page", hint: "A page laid out for printing." },
  { format: "play", label: "Playable page", hint: "A single file you can play offline." },
] as const;

export function exportUrl(slug: string, format: string): string {
  return apiUrl(`/adventures/${encodeURIComponent(slug)}/moderation/export?format=${format}`);
}

/** Export downloads for owners, editors, and administrators (v0.26.0). */
export function ExportPanel({ slug }: { slug: string }) {
  return (
    <Panel title="Export">
      <p>Exports include only published scenes and safe credits. Drafts, hidden scenes, and private details are left out.</p>
      <ul className="bp-export-list" data-testid="export-panel">
        {EXPORT_FORMATS.map((f) => (
          <li key={f.format}>
            <a href={exportUrl(slug, f.format)} download>{f.label}</a> — {f.hint}
          </li>
        ))}
      </ul>
    </Panel>
  );
}
