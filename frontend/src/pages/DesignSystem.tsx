import { useState } from "react";
import {
  Button,
  Badge,
  Alert,
  Dialog,
  HelpDrawer,
  Panel,
  Wordmark,
  Colophon,
  StoryPage,
  SceneTitle,
  StoryBody,
  ChoiceList,
  ChoicesHeading,
  EndingPanel,
  AdventureCard,
  FeaturedAdventureCard,
  SearchField,
  Select,
  Checkbox,
  RadioGroup,
  Toggle,
  TextArea,
  PasswordField,
  FormSection,
  StepIndicator,
  ValidationMessage,
  InlineHelp,
  ManageNav,
  QueueItem,
  ActivityItem,
  WarningPanel,
  DangerZone,
  AdminTable,
  SearchFilterBar,
  EmptyState,
  ErrorState,
  RichTextEditor,
} from "../components";
import type { AdminColumn, AdventureSummary } from "../components";

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

const sampleAdventure: AdventureSummary = {
  slug: "the-lantern-path",
  title: "The Lantern Path",
  author: "Rowan Hale",
  synopsis: "A traveller finds a low stone at a fork in the reeds, and a humming she has heard before.",
  status: "published",
  sceneCount: 42,
  updatedAt: "yesterday",
};

interface UserRow {
  handle: string;
  joined: string;
  role: string;
  status: "draft" | "published" | "review" | "warning" | "danger";
  statusLabel: string;
}
const userRows: UserRow[] = [
  { handle: "rowan", joined: "2026-05-01", role: "Author", status: "published", statusLabel: "Active" },
  { handle: "marisol", joined: "2026-05-08", role: "Reader", status: "review", statusLabel: "Pending" },
  { handle: "fen", joined: "2026-06-14", role: "Author", status: "danger", statusLabel: "Suspended" },
];
const userColumns: AdminColumn<UserRow>[] = [
  { key: "handle", header: "Handle", cell: (r) => r.handle },
  { key: "joined", header: "Joined", cell: (r) => r.joined },
  { key: "role", header: "Role", cell: (r) => r.role },
  { key: "status", header: "Status", cell: (r) => <Badge tone={r.status}>{r.statusLabel}</Badge> },
];

export function DesignSystem() {
  const [dialogOpen, setDialogOpen] = useState(false);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [checked, setChecked] = useState(true);
  const [toggle, setToggle] = useState(true);
  const [radio, setRadio] = useState("review");
  const [searchValue, setSearchValue] = useState("");

  return (
    <section aria-labelledby="ds-title" data-testid="design-system">
      <h1 id="ds-title">Design system</h1>
      <p style={{ color: "var(--bp-muted)" }}>
        Internal preview of tokens, primitives, and shared components. Not linked from primary navigation.
      </p>

      {/* -------------------------- Foundations -------------------------- */}
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

      {/* -------------------------- Navigation --------------------------- */}
      <div className="bp-ds-section" aria-labelledby="ds-wordmark">
        <h2 id="ds-wordmark">Wordmark</h2>
        <div style={{ display: "flex", gap: "var(--bp-s-5)", alignItems: "center" }}>
          <Wordmark as="inline" />
          <Wordmark as="block" />
        </div>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-colophon">
        <h2 id="ds-colophon">Colophon (footer preview)</h2>
        <Colophon />
      </div>

      {/* -------------------------- Controls ----------------------------- */}
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
        <TextArea
          label="Opening passage"
          placeholder="Begin your story…"
          maxLength={280}
          showCount
          defaultValue="A cold wind moves through the reeds…"
        />
        <Select label="Visibility" help="Draft adventures are only visible to you.">
          <option>Draft</option>
          <option>Published</option>
        </Select>
        <SearchField label="Search adventures" placeholder="Search titles and authors…" />
        <PasswordField
          label="Password"
          help="At least 12 characters."
          autoComplete="new-password"
          defaultValue="secret-example"
        />
        <div className="bp-field">
          <label className="bp-label" htmlFor="ds-invalid">Invalid example</label>
          <input id="ds-invalid" className="bp-input" aria-invalid="true" defaultValue="not@ok" />
          <span className="bp-error">Enter a valid email address.</span>
        </div>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-choice-controls">
        <h2 id="ds-choice-controls">Checkboxes, radios, toggles</h2>
        <Checkbox
          label="Allow reader submissions"
          checked={checked}
          onChange={(e) => setChecked(e.target.checked)}
        />
        <RadioGroup
          legend="Moderation"
          name="ds-mod"
          value={radio}
          onChange={setRadio}
          options={[
            { value: "review", label: "Review before publish", description: "You approve every submission." },
            { value: "auto", label: "Publish immediately", description: "Trusted contributors only." },
          ]}
          orientation="stacked"
        />
        <Toggle
          label="Send email notifications"
          description="Digest sent once daily."
          checked={toggle}
          onChange={(e) => setToggle(e.target.checked)}
        />
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-form-composition">
        <h2 id="ds-form-composition">Form section &amp; step indicator</h2>
        <StepIndicator
          steps={[{ label: "Details" }, { label: "Opening scene" }, { label: "Publish" }]}
          current={2}
        />
        <FormSection
          title="Adventure details"
          description="How your adventure appears to readers."
          actions={<Button variant="secondary" size="sm">Preview</Button>}
        >
          <div className="bp-field">
            <label className="bp-label" htmlFor="ds-form-title">Title</label>
            <input id="ds-form-title" className="bp-input" defaultValue="The Lantern Path" />
          </div>
          <ValidationMessage tone="error">A title of one to eighty characters is required.</ValidationMessage>
          <ValidationMessage tone="warning">Titles longer than sixty characters may be truncated.</ValidationMessage>
          <ValidationMessage tone="info">You can rename your adventure at any time.</ValidationMessage>
          <InlineHelp>
            Titles are shown to readers on the discover page and in search results.
          </InlineHelp>
        </FormSection>
      </div>

      {/* -------------------------- Story -------------------------------- */}
      <div className="bp-ds-section" aria-labelledby="ds-story">
        <h2 id="ds-story">Story page</h2>
        <StoryPage meta="Chapter 3 · The crossing">
          <SceneTitle>The lantern trembles</SceneTitle>
          <StoryBody
            text={
              "A cold wind moves through the reeds and the lantern trembles in your hand. " +
              "You can hear water, though the river should be an hour still ahead. Somewhere " +
              "beyond the trees, someone is humming a tune your grandmother sang." +
              "\n\n" +
              "The path forks here. There is no signpost, only a low stone worn smooth by weather."
            }
          />
          <span className="bp-ornament" aria-hidden="true" />
          <ChoicesHeading>Choose your path</ChoicesHeading>
          <ChoiceList
            choices={[
              { label: "Follow the humming through the trees.", href: "#" },
              { label: "Keep to the path and press on toward the river.", href: "#" },
              { label: "Set the lantern down and listen a while longer.", href: "#" },
            ]}
          />
        </StoryPage>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-ending">
        <h2 id="ds-ending">Ending panel</h2>
        <EndingPanel
          kind="An ending"
          title="Home before the tide"
          body={
            "You reach the shingle beach as the last of the light thins into rose. Your grandmother " +
            "is at the door, humming the same tune, as if she has been humming it for years." +
            "\n\n" +
            "You never do go back to look at the stone."
          }
        />
      </div>

      {/* -------------------------- Discovery ---------------------------- */}
      <div className="bp-ds-section" aria-labelledby="ds-cards">
        <h2 id="ds-cards">Adventure cards</h2>
        <FeaturedAdventureCard adventure={sampleAdventure} />
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(260px, 1fr))", gap: "var(--bp-s-4)" }}>
          <AdventureCard adventure={sampleAdventure} />
          <AdventureCard adventure={{ ...sampleAdventure, slug: "iron-door", title: "A Door of Iron", status: "draft", sceneCount: 4 }} />
          <AdventureCard adventure={{ ...sampleAdventure, slug: "reed-boat", title: "The Reed Boat", status: "review", sceneCount: 12 }} />
        </div>
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

      {/* -------------------------- Dialogs & help ----------------------- */}
      <div className="bp-ds-section" aria-labelledby="ds-cards-alerts">
        <h2 id="ds-cards-alerts">Cards and alerts</h2>
        <div className="bp-card">
          <h3 className="bp-card__title">A modest card</h3>
          <p>Cards hold discrete pieces of content. Fine borders, no gloss.</p>
        </div>
        <Alert tone="info" title="Note">Autosave is on.</Alert>
        <Alert tone="success" title="Published">Your adventure is live.</Alert>
        <Alert tone="warning" title="Contribution paused">Submissions are disabled while you moderate the queue.</Alert>
        <Alert tone="danger" title="Cannot publish">Fix validation errors before publishing.</Alert>
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
          <p>This will remove the scene and any choices leading to it.</p>
        </Dialog>
        <HelpDrawer open={drawerOpen} onClose={() => setDrawerOpen(false)} title="Help">
          <p>The help drawer surfaces contextual guidance for the current page.</p>
        </HelpDrawer>
      </div>

      {/* -------------------------- Management --------------------------- */}
      <div className="bp-ds-section" aria-labelledby="ds-manage-nav">
        <h2 id="ds-manage-nav">Management navigation</h2>
        <div style={{ maxWidth: 260 }}>
          <ManageNav
            items={[
              { to: "/manage/lantern-path", label: "Overview", end: true },
              { to: "/manage/lantern-path/scenes", label: "Scenes" },
              { to: "/manage/lantern-path/submissions", label: "Submissions", badge: "3" },
              { to: "/manage/lantern-path/settings", label: "Settings" },
              { to: "/manage/lantern-path/danger", label: "Danger zone" },
            ]}
          />
        </div>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-panels">
        <h2 id="ds-panels">Management panels</h2>
        <Panel title="Contribution rules" actions={<Badge tone="draft">Draft</Badge>}>
          <TextArea label="Guidance for contributors" defaultValue="Keep passages under 500 words." />
          <Button variant="primary">Save rules</Button>
        </Panel>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-queue">
        <h2 id="ds-queue">Queue &amp; activity</h2>
        <QueueItem
          title="Submission: The stone at the fork"
          submitter="marisol"
          submittedAt="2026-07-14"
          excerpt="The stone was warm, though the day had been cold since dawn…"
          status="review"
          actions={
            <>
              <Button variant="primary" size="sm">Approve</Button>
              <Button variant="secondary" size="sm">Return with notes</Button>
              <Button variant="danger" size="sm">Reject</Button>
            </>
          }
        />
        <ul style={{ listStyle: "none", padding: 0, margin: 0 }}>
          <ActivityItem actor="rowan" action="published" target="Chapter 3 · The crossing" at="an hour ago" />
          <ActivityItem actor="fen" action="submitted" target="A door of iron" at="yesterday" />
          <ActivityItem actor="marisol" action="left a note on" target="The reed boat" at="2 days ago" />
        </ul>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-warning-danger">
        <h2 id="ds-warning-danger">Warning &amp; danger</h2>
        <WarningPanel
          title="Contribution paused"
          actions={<Button variant="secondary" size="sm">Resume contributions</Button>}
        >
          <p>New submissions are disabled while you catch up on the moderation queue.</p>
        </WarningPanel>
        <DangerZone
          title="Danger zone"
          description="These actions cannot be undone. Please read carefully."
        >
          <div style={{ display: "flex", flexDirection: "column", gap: "var(--bp-s-3)" }}>
            <div style={{ display: "flex", justifyContent: "space-between", gap: "var(--bp-s-3)" }}>
              <div>
                <strong>Unpublish adventure</strong>
                <p style={{ margin: 0, color: "var(--bp-muted)", fontSize: "var(--bp-fs-sm)" }}>
                  Hide from readers. Your scenes are retained.
                </p>
              </div>
              <Button variant="danger" size="sm">Unpublish</Button>
            </div>
            <div style={{ display: "flex", justifyContent: "space-between", gap: "var(--bp-s-3)" }}>
              <div>
                <strong>Delete adventure</strong>
                <p style={{ margin: 0, color: "var(--bp-muted)", fontSize: "var(--bp-fs-sm)" }}>
                  Permanently removes all scenes, submissions, and history.
                </p>
              </div>
              <Button variant="danger" size="sm">Delete forever</Button>
            </div>
          </div>
        </DangerZone>
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-search-filter">
        <h2 id="ds-search-filter">Search &amp; filter bar</h2>
        <SearchFilterBar
          searchLabel="Search users"
          searchPlaceholder="Search by handle or email…"
          searchValue={searchValue}
          onSearchChange={setSearchValue}
          filters={
            <Select label="Role">
              <option>Any role</option>
              <option>Author</option>
              <option>Reader</option>
            </Select>
          }
          actions={<Button variant="secondary" size="sm">Export</Button>}
        />
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-tables">
        <h2 id="ds-tables">Administrative tables</h2>
        <AdminTable
          caption="Recent users"
          columns={userColumns}
          rows={userRows}
          rowKey={(r) => r.handle}
        />
      </div>

      {/* -------------------------- States ------------------------------- */}
      <div className="bp-ds-section" aria-labelledby="ds-states">
        <h2 id="ds-states">Empty &amp; error states</h2>
        <EmptyState
          title="No adventures yet"
          message="Start your first adventure and it will appear here."
          action={<Button variant="primary">Create adventure</Button>}
        />
        <ErrorState
          title="This page could not load"
          message="Please try again in a moment."
          action={<Button variant="secondary">Try again</Button>}
        />
      </div>

      <div className="bp-ds-section" aria-labelledby="ds-rte">
        <h2 id="ds-rte">Restricted WYSIWYG editor</h2>
        <p>
          The story editor exposes only paragraphs, bold, italic, underline, H2, H3, lists,
          blockquote, horizontal rule, and undo/redo. Pasting from another source strips
          links, images, embeds, and styles.
        </p>
        <RichTextEditorDemo />
      </div>
    </section>
  );
}

function RichTextEditorDemo() {
  const [html, setHtml] = useState<string>(
    "<h2>Chapter one</h2><p>The lantern <strong>flickered</strong> and the corridor grew colder.</p>",
  );
  return (
    <>
      <label id="rte-demo-label" className="bp-ds-label">
        Story body
      </label>
      <RichTextEditor
        value={html}
        onChange={setHtml}
        ariaLabelledBy="rte-demo-label"
        maxPlainTextLength={500}
        data-testid="ds-rte"
      />
      <details style={{ marginTop: "0.75rem" }}>
        <summary>Sanitized HTML</summary>
        <pre style={{ whiteSpace: "pre-wrap", fontSize: "0.8rem" }}>{html}</pre>
      </details>
    </>
  );
}
