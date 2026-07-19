import { useState } from "react";
import { Button } from "../components/Button";
import { Badge } from "../components/Badge";
import { Alert } from "../components/Alert";
import { Dialog } from "../components/Dialog";
import { HelpDrawer } from "../components/HelpDrawer";
import { Panel } from "../components/Panel";

const swatches: Array<{ token: string; label: string }> = [
  { token: "--bp-cream", label: "Cream (surface)" },
  { token: "--bp-parchment", label: "Parchment" },
  { token: "--bp-panel", label: "Panel" },
  { token: "--bp-ink", label: "Ink" },
  { token: "--bp-ink-2", label: "Ink body" },
  { token: "--bp-muted", label: "Muted" },
  { token: "--bp-red", label: "Deep red (accent)" },
  { token: "--bp-gold", label: "Antique gold" },
  { token: "--bp-forest", label: "Forest" },
  { token: "--bp-navy", label: "Navy" },
  { token: "--bp-orange", label: "Burnt orange" },
  { token: "--bp-rule", label: "Rule" },
];

export function DesignSystem() {
  const [dialogOpen, setDialogOpen] = useState(false);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [checked, setChecked] = useState(true);
  const [toggle, setToggle] = useState(true);

  return (
    <section aria-labelledby="ds-title" data-testid="design-system">
      <h1 id="ds-title">Design system</h1>
      <p style={{ color: "var(--bp-muted)" }}>
        Internal preview of tokens and primitives. Not linked from primary navigation.
      </p>

      <div className="bp-ds-section" aria-labelledby="ds-typography">
        <h2 id="ds-typography">Typography</h2>
        <div className="bp-type-sample">
          <p className="bp-type-sample__label">Display / H1</p>
          <h1 style={{ marginBottom: "var(--bp-s-4)" }}>Into the Whispering Wood</h1>
          <p className="bp-type-sample__label">Display / H2</p>
          <h2>Chapter the second</h2>
          <p className="bp-type-sample__label">Display / H3</p>
          <h3>A door of iron</h3>
          <p className="bp-type-sample__label">Story body</p>
          <p style={{
            fontFamily: "var(--bp-font-story)",
            fontSize: "var(--bp-fs-md)",
            lineHeight: "var(--bp-lh-reading)",
          }}>
            The lantern trembled. You have three choices, and only one leads home before the tide.
            The rest wait patiently, as such choices tend to.
          </p>
          <p className="bp-type-sample__label">UI text</p>
          <p style={{ fontFamily: "var(--bp-font-ui)", fontSize: "var(--bp-fs-sm)" }}>
            Controls, forms, and administrative surfaces use a clean sans-serif for legibility.
          </p>
        </div>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-colors">
        <h2 id="ds-colors">Colors</h2>
        <div className="bp-ds-grid" role="list">
          {swatches.map((s) => (
            <div className="bp-swatch" role="listitem" key={s.token}>
              <div className="bp-swatch__chip" style={{ background: `var(${s.token})` }} />
              <div className="bp-swatch__meta">
                <div>{s.label}</div>
                <code style={{ color: "var(--bp-muted)" }}>{s.token}</code>
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-buttons">
        <h2 id="ds-buttons">Buttons</h2>
        <div style={{ display: "flex", flexWrap: "wrap", gap: "var(--bp-s-3)" }}>
          <Button variant="primary">Primary action</Button>
          <Button variant="secondary">Secondary</Button>
          <Button variant="ghost">Ghost</Button>
          <Button variant="danger">Danger</Button>
          <Button variant="primary" disabled>Disabled</Button>
          <Button variant="secondary" size="sm">Small</Button>
        </div>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-inputs">
        <h2 id="ds-inputs">Inputs</h2>
        <div className="bp-field">
          <label className="bp-label" htmlFor="ds-input">Adventure title</label>
          <input id="ds-input" className="bp-input" placeholder="e.g. The Lantern Path" />
          <span className="bp-help">Titles appear on the discover page.</span>
        </div>
        <div className="bp-field">
          <label className="bp-label" htmlFor="ds-textarea">Opening passage</label>
          <textarea id="ds-textarea" className="bp-textarea" placeholder="Begin your story…" />
        </div>
        <div className="bp-field">
          <label className="bp-label" htmlFor="ds-select">Visibility</label>
          <select id="ds-select" className="bp-select">
            <option>Draft</option>
            <option>Published</option>
          </select>
        </div>
        <div className="bp-field">
          <label className="bp-label" htmlFor="ds-invalid">Invalid example</label>
          <input id="ds-invalid" className="bp-input" aria-invalid="true" defaultValue="not@ok" />
          <span className="bp-error">Enter a valid email address.</span>
        </div>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-choice-controls">
        <h2 id="ds-choice-controls">Checkboxes, radios, toggles</h2>
        <label className="bp-check">
          <input
            type="checkbox"
            checked={checked}
            onChange={(e) => setChecked(e.target.checked)}
          />
          Allow reader submissions
        </label>
        <div style={{ height: "var(--bp-s-3)" }} />
        <div role="radiogroup" aria-labelledby="ds-radio-label">
          <span id="ds-radio-label" className="bp-label">Moderation</span>
          <div style={{ display: "flex", gap: "var(--bp-s-4)", marginTop: "var(--bp-s-1)" }}>
            <label className="bp-radio"><input type="radio" name="mod" defaultChecked /> Review before publish</label>
            <label className="bp-radio"><input type="radio" name="mod" /> Publish immediately</label>
          </div>
        </div>
        <div style={{ height: "var(--bp-s-3)" }} />
        <label className="bp-toggle">
          <input
            type="checkbox"
            checked={toggle}
            onChange={(e) => setToggle(e.target.checked)}
            aria-label="Send email notifications"
          />
          <span className="bp-toggle-track"><span className="bp-toggle-thumb" /></span>
          Send email notifications
        </label>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-badges">
        <h2 id="ds-badges">Status badges</h2>
        <div style={{ display: "flex", flexWrap: "wrap", gap: "var(--bp-s-2)" }}>
          <Badge tone="draft">Draft</Badge>
          <Badge tone="published">Published</Badge>
          <Badge tone="review">In review</Badge>
          <Badge tone="warning">Attention</Badge>
          <Badge tone="danger">Reported</Badge>
        </div>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-story">
        <h2 id="ds-story">Story page</h2>
        <article className="bp-story bp-card" style={{ background: "var(--bp-cream)" }}>
          <p className="bp-story__meta">Chapter 3 · The crossing</p>
          <h1>The lantern trembles</h1>
          <div className="bp-story__body">
            <p>
              A cold wind moves through the reeds and the lantern trembles in your hand.
              You can hear water, though the river should be an hour still ahead.
              Somewhere beyond the trees, someone is humming a tune your grandmother sang.
            </p>
            <p>
              The path forks here. There is no signpost, only a low stone worn smooth by weather.
            </p>
          </div>
          <span className="bp-ornament" aria-hidden="true" />
          <p className="bp-story__meta">Choose your path</p>
          <ol className="bp-choices" data-testid="numbered-choices">
            <li><a href="#" className="bp-choice">Follow the humming through the trees.</a></li>
            <li><a href="#" className="bp-choice">Keep to the path and press on toward the river.</a></li>
            <li><a href="#" className="bp-choice">Set the lantern down and listen a while longer.</a></li>
          </ol>
        </article>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-cards-alerts">
        <h2 id="ds-cards-alerts">Cards and alerts</h2>
        <div className="bp-card">
          <h3 className="bp-card__title">A modest card</h3>
          <p>Cards hold discrete pieces of content. Fine borders, no gloss.</p>
        </div>
        <Alert tone="info" title="Note">Autosave is on. Your last save was a moment ago.</Alert>
        <Alert tone="success" title="Published">Your adventure is live at its public address.</Alert>
        <Alert tone="warning" title="Contribution paused">New submissions are disabled while you moderate the queue.</Alert>
        <Alert tone="danger" title="Cannot publish">Fix the highlighted validation errors before publishing.</Alert>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-dialogs">
        <h2 id="ds-dialogs">Dialogs and help drawer</h2>
        <div style={{ display: "flex", gap: "var(--bp-s-2)", flexWrap: "wrap" }}>
          <Button variant="primary" onClick={() => setDialogOpen(true)}>Open dialog</Button>
          <Button variant="secondary" onClick={() => setDrawerOpen(true)}>Open help drawer</Button>
        </div>
        <Dialog
          open={dialogOpen}
          onClose={() => setDialogOpen(false)}
          title="Delete this scene?"
          actions={
            <>
              <Button variant="ghost" onClick={() => setDialogOpen(false)}>Cancel</Button>
              <Button variant="danger" onClick={() => setDialogOpen(false)}>Delete scene</Button>
            </>
          }
        >
          <p>This will remove the scene and any choices leading to it. This action cannot be undone.</p>
        </Dialog>
        <HelpDrawer
          open={drawerOpen}
          onClose={() => setDrawerOpen(false)}
          title="Help"
        >
          <p>The help drawer surfaces contextual guidance based on the current page and action.</p>
          <p style={{ color: "var(--bp-muted)", fontSize: "var(--bp-fs-sm)" }}>
            Press Escape to close.
          </p>
        </HelpDrawer>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-panels">
        <h2 id="ds-panels">Management panels</h2>
        <Panel title="Contribution rules" actions={<Badge tone="draft">Draft</Badge>}>
          <div className="bp-field">
            <label className="bp-label" htmlFor="ds-rules">Guidance for contributors</label>
            <textarea id="ds-rules" className="bp-textarea" defaultValue="Keep passages under 500 words." />
          </div>
          <Button variant="primary">Save rules</Button>
        </Panel>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-tables">
        <h2 id="ds-tables">Administrative tables</h2>
        <table className="bp-table" data-testid="admin-table">
          <caption>Recent users</caption>
          <thead>
            <tr>
              <th scope="col">Handle</th>
              <th scope="col">Joined</th>
              <th scope="col">Role</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>rowan</td><td>2026-05-01</td><td>Author</td><td><Badge tone="published">Active</Badge></td>
            </tr>
            <tr>
              <td>marisol</td><td>2026-05-08</td><td>Reader</td><td><Badge tone="review">Pending</Badge></td>
            </tr>
            <tr>
              <td>fen</td><td>2026-06-14</td><td>Author</td><td><Badge tone="danger">Suspended</Badge></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  );
}
