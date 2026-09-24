/**
 * Help topics for Branching Paths.
 *
 * Each topic describes ONLY behaviour that is actually implemented at the
 * current version. Topics that will only become truthful once a later
 * release ships (payments, notifications, real accounts, etc.) explicitly
 * label the affected paragraphs as "planned" rather than describing them
 * as if they already worked.
 *
 * The `match` block lets the contextual drawer pick which topics to
 * surface first based on route, sign-in state, role, adventure status, or
 * the current section a page opts into via `useHelpContext`.
 */
import type { StoryStatus } from "../components/AdventureCard";

export type HelpRole = "reader" | "author" | "master";

export interface HelpMatch {
  /** URL path patterns that mark this topic as relevant. */
  routes?: RegExp[];
  /** Roles that should see this topic promoted. */
  roles?: HelpRole[];
  /** True/false to require or forbid a signed-in visitor. */
  signedIn?: boolean;
  /** Adventure statuses this topic is written for. */
  adventureStatus?: StoryStatus[];
  /** Section labels a page can push via `useHelpContext`. */
  sections?: string[];
}

export interface HelpTopic {
  slug: string;
  title: string;
  /** One-sentence summary shown in the drawer and the topic index. */
  summary: string;
  /** Full body — every entry becomes a paragraph on the topic page. */
  body: string[];
  /** Slugs of related topics linked at the foot of the topic page. */
  related: string[];
  /** Contextual matcher used by `pickContextualTopics`. */
  match: HelpMatch;
}

export const HELP_TOPICS: HelpTopic[] = [
  {
    slug: "getting-started",
    title: "Getting started",
    summary:
      "What Branching Paths is, what you can do without an account, and where to go next.",
    body: [
      "Branching Paths is a collaborative choose-your-own-adventure library. Readers follow numbered choices through branching stories; writers publish adventures and, when the creator allows it, contribute new branches to other people's stories.",
      "Reading does not require an account. Open Discover, pick an adventure, and start reading. Your reading position, bookmarks, and discovered endings are stored only in your browser.",
      "Creating an adventure and contributing branches will require an account. Account creation, sign-in, and the writing tools are planned for later releases; today the Create and Contribute pages describe what is coming without collecting any personal information.",
      "The three primary actions on the home page are: Read an adventure, Create an adventure, and Continue someone else's story. Each links to the surface where that flow lives.",
    ],
    related: ["discovering", "reading", "accounts"],
    match: { routes: [/^\/$/, /^\/start$/], sections: ["home"] },
  },
  {
    slug: "collaborators",
    title: "Collaborators and roles",
    summary:
      "How owners invite editors and reviewers, change roles, and remove people from an adventure.",
    body: [
      "Every adventure has exactly one owner. The owner can invite other registered members as editors or reviewers, using the email address on their account.",
      "Editors can edit the adventure's details, scenes, and choices, and decide on branch submissions. Reviewers can leave private notes and recommend approval or rejection, but cannot publish or change the story themselves.",
      "Invitations arrive in the recipient's inbox on the site and by email. Each invitation link works once, is tied to the invited account, and expires on its own. Owners can revoke a pending invitation at any time before it is used.",
      "Owners can change an editor into a reviewer (or the other way round) and remove anyone except themselves. Removing someone takes away their access immediately; the work they contributed stays in the story.",
    ],
    related: ["ownership-transfer", "inbox", "moderation"],
    match: {
      routes: [/^\/manage\//, /^\/invitations\//],
      sections: ["collaboration", "manage"],
    },
  },
  {
    slug: "ownership-transfer",
    title: "Transferring ownership",
    summary:
      "What happens when an adventure changes hands, and the safeguards around it.",
    body: [
      "Ownership can only pass to someone who is already an editor or reviewer on the adventure, so nobody can be handed a story unexpectedly.",
      "Because the change cannot be undone by you afterwards, the transfer asks for your password again and for an explicit confirmation. Re-entering your password unlocks the action for a few minutes only.",
      "The transfer happens in a single step: the new owner takes over, and you stay on as an editor unless you choose to leave the team. There is always exactly one owner.",
      "Both people are notified in their inbox and by email, and the change is recorded in the adventure's activity log.",
    ],
    related: ["collaborators", "inbox", "moderation"],
    match: { routes: [/^\/manage\//], sections: ["collaboration", "manage"] },
  },
  {
    slug: "inbox",
    title: "Your inbox",
    summary: "Where invitations and adventure notifications arrive on the site.",
    body: [
      "The inbox in your account collects invitations to collaborate, the outcome of invitations you sent, and ownership changes, so a missed email never loses an invitation.",
      "Unread items are marked as new. You can mark a single notification as read, or clear them all at once.",
      "Notifications never contain the invitation link itself in a form anyone else can reuse: each link works once and only for the invited account.",
    ],
    related: ["collaborators", "accounts"],
    match: { routes: [/^\/account\/inbox/], sections: ["collaboration", "account"] },
  },
  {
    slug: "discovering",
    title: "Discovering adventures",
    summary:
      "How to search, filter, sort, and share a specific view of the library.",
    body: [
      "The Discover page lists every published adventure. Use the search field to look for a title, author, or a phrase from a synopsis, and combine it with filters for genre, content rating, adventure status, and contribution status.",
      "Sort by recently updated, newest, title, scene count, or ending count. Every choice you make is stored in the page URL, so you can bookmark or share a specific view of the library.",
      "On narrow screens, tap Filter & sort to open the filter panel. Use Clear filters to return to the default view. An empty-result state appears when no adventure matches, with a shortcut to clear filters.",
      "Branching Paths intentionally does not display popularity scores, public ratings, likes, comments, or follower counts. Discovery is by content, not by numbers.",
    ],
    related: ["reading", "getting-started"],
    match: { routes: [/^\/discover/], sections: ["discover"] },
  },
  {
    slug: "reading",
    title: "Reading an adventure",
    summary:
      "How scenes, choices, endings, and local progress work in the reader.",
    body: [
      "The reader lives at /adventure/<slug>/read/<scene>. Each scene shows the adventure title, an optional chapter label, the scene title, the story body, a scene number, and either a numbered set of choices or an ending panel.",
      "Choose a numbered option to move to the next scene. Use Back one scene to step back through your reading trail, or Restart to return to the first scene. The Story map action opens the branching diagram for the adventure.",
      "Bookmark a scene to flag it for later; the bookmark is stored only in your browser. Add a branch appears when the adventure is accepting contributions; Report and Help are always available.",
      "Reading progress, bookmarks, and discovered endings are per-adventure and per-browser. Clearing local progress from the reader erases every trace of your reading for that adventure and cannot be undone.",
      "When you reach an ending, the reader tells you how many endings you have discovered so far, offers Explore another path back to your most recent branching scene, and lets you restart from the beginning.",
    ],
    related: ["discovering", "contributing", "common-errors"],
    match: {
      routes: [/^\/adventure\/[^/]+\/read/, /^\/adventure\/[^/]+$/],
      sections: ["reader", "adventure"],
    },
  },
  {
    slug: "creating",
    title: "Creating an adventure",
    summary:
      "How the five-step creation wizard works, what the templates change, and the limits that apply.",
    body: [
      "Creating an adventure requires a signed-in, active account. Open Create from the main navigation. Reading never requires an account — only writing does.",
      "The wizard has five steps: Basics (title, description, genre, content rating, content warnings), Opening scene (scene title and scene text), Contributions (template, who may add branches, visibility, branches allowed per scene, optional passcode), Writing guidelines (optional), and Review.",
      "Nothing is saved until you finish the last step. The adventure and its opening scene are written together in a single transaction: either both exist afterwards or neither does, so you can never end up with an adventure that has no first scene.",
      "Templates only configure settings — they never write story text for you. Solo story closes contributions. Open community story publishes contributions immediately and allows contributions without a display name. Moderated community story holds contributions for your approval. Private group story is unlisted and expects a contribution passcode. You can change any individual setting after picking a template, and the template never skips validation.",
      "On the Review step you choose Save as a draft or Publish now. A draft is visible only to you and its opening scene stays unpublished; publishing makes the opening scene readable straight away. Unlisted adventures never appear on Discover but remain reachable by link.",
      "The scene text and the writing guidelines use the restricted editor. Links, images, styles, tables, and custom HTML are removed as you type and again on the server before anything is stored.",
      "Two limits apply. There is a maximum number of adventures one account may own, and a maximum number you may start per hour. The wizard shows both, along with how many you have used, and refuses to submit once either ceiling is reached. Hitting a limit never discards what you have typed.",
      "If your account is not active — for example the email address is still unverified — creation is refused with an explanation rather than a silent failure.",
    ],
    related: ["contributing", "managing-adventures", "restricted-writing-tools", "accounts"],
    match: {
      routes: [/^\/start/, /^\/account/],
      sections: ["create", "authoring"],
      roles: ["author"],
    },
  },
  {
    slug: "contributing",
    title: "Contributing a branch",
    summary:
      "How to add a branch to another writer's adventure, how attribution works, and what can stop a submission.",
    body: [
      "Contributing a branch means writing a new scene that attaches to a published scene in someone else's adventure, reached through a new numbered choice. Open a scene in the reader and choose Add a branch.",
      "Every adventure declares a contribution status. Immediate publishing writes your branch straight into the story. Approval required queues it for the adventure's team to review before readers see it. Closed means the adventure accepts no new branches, and the form is not offered.",
      "A branch form asks for the choice text readers will click, a title and body for the next scene, whether that scene continues the story or ends it, how you would like to be credited, and an optional private note to the team. If the creator configured a contribution passcode, you must supply it.",
      "Public attribution is a display preference: your username, your display name, or Anonymous. Choosing Anonymous hides your name from readers only \u2014 the adventure's moderators always see who submitted the branch, so anonymity is never a way to contribute without accountability.",
      "Several rules can stop a submission: the adventure must be accepting contributions, the scene you are branching from must be published and unlocked, the scene must have a free branch slot, you must not be blocked from that adventure, the passcode must match, and there are hourly limits on how many branches one account or one connection may submit. A choice that repeats one already leaving that scene is refused as a duplicate.",
      "Your draft autosaves in this browser as you type, so closing the tab or a refused submission does not lose your writing. The draft is cleared once the branch is accepted.",
      "Scene text uses the restricted editor. Links, images, styles, and custom HTML are removed as you type and again on the server, so a contribution can never change how a story looks.",
      "Contributions are not overwrites. A branch extends a story from an existing scene; the scene you branched from is not modified, and the original path stays intact. Every submission you make appears under Contributions in your account.",
    ],
    related: ["reading", "creating", "moderation"],
    match: {
      routes: [/^\/adventure\/[^/]+\/branch/, /^\/adventure\/[^/]+\/read/],
      sections: ["contribute"],
    },
  },
  {
    slug: "accounts",
    title: "Accounts",
    summary:
      "How to register an account, what it currently unlocks, and what is still planned.",
    body: [
      "You can register an account today at /register. Registration collects an email address, a username, a display name, a password, a confirmation of that password, and your acceptance of the community guidelines. Reading, searching, and bookmarking continue to work without an account.",
      "Email and username are matched case-insensitively. \"Alice\" and \"alice\" are the same account; so are \"Alice@Example.com\" and \"alice@example.com\". The username must be between three and thirty-two characters and may contain letters, digits, hyphens, and underscores. Passwords must meet the minimum length set by the operator (twelve characters by default).",
      "After you submit the form the site always shows the same acknowledgement whether the email or username was new, was already taken, or triggered the spam-protection honeypot. This is deliberate: the endpoint is designed so that it cannot be used to check whether a specific email or username exists.",
      "The operator can require email verification, administrator approval, or both before an account becomes active. Email delivery and sign-in are planned for later releases, so today the account is stored in a pending state and Follow, Manage, and Master areas remain placeholders.",
      "If registration is temporarily closed, the /register page will say so. If too many attempts come from your network in a short time, the site will pause new registrations from that network for an hour.",
    ],
    related: ["privacy-and-security", "managing-adventures", "getting-started"],
    match: {
      routes: [/^\/account/, /^\/login/, /^\/register/],
      sections: ["account", "register"],
      roles: ["author"],
    },
  },

  {
    slug: "managing-adventures",
    title: "Managing adventures",
    summary:
      "Saving drafts, previewing, publishing, and changing an adventure's status.",
    body: [
      "The Manage area at /manage/<slug> is where you shepherd one of your own adventures. Owners, editors you have added, and site administrators can open it; nobody else can.",
      "Save draft keeps working on a story without showing it to readers. A draft adventure is hidden from Discover and cannot be opened by its public address — only collaborators and administrators can see it.",
      "Preview shows the reader experience exactly as it stands, including scenes that are not published yet. Preview requires the same authorization as managing, and the page is marked so search engines do not index it.",
      "Publish makes the adventure readable. It requires a valid opening scene — one with a title and some text — and publishing that scene alongside the adventure. Unpublish returns the adventure to draft; it changes status only and never deletes scenes, choices, warnings, or settings.",
      "Set in progress, Set complete, and Set on hold tell readers where the story stands without hiding it. Archive preserves the adventure and makes it read-only: no more edits, no more status changes.",
      "Publishing, unpublishing, and archiving ask for confirmation first, because each one changes what readers see.",
      "Every accepted status change is recorded in the adventure's activity list with who made it, what changed, and when.",
      "A contribution queue for pending branches is planned for a later release.",
    ],
    related: ["creating", "moderation", "accounts"],
    match: {
      routes: [/^\/manage/, /^\/adventure\/[^/]+\/preview$/],
      sections: ["manage", "publishing"],
      roles: ["author", "master"],
    },
  },
  {
    slug: "moderation",
    title: "Moderation",
    summary:
      "How master administrators will keep the library healthy, and how reports work.",
    body: [
      "Moderation is handled by master administrators from the Master area at /master. Today the area is a placeholder that describes the coming queues and actions.",
      "Reports made from the reader will land in a moderation queue with the scene, the adventure, and the reason. Master administrators will be able to review the report, contact the creator, hide or remove the scene, and record the outcome.",
      "The community guidelines describe what is allowed: good-faith contributions, respect for other authors, no harassment, no illegal content, no impersonation, no spam, and clear attribution. Reports that describe violations of these guidelines are prioritised.",
      "Master credentials will never appear in a URL, in help content, or in any diagnostic surface. The Master login page is the only place they are entered.",
    ],
    related: ["managing-adventures", "privacy-and-security", "common-errors"],
    match: { routes: [/^\/master/], sections: ["master"], roles: ["master"] },
  },
  {
    slug: "privacy-and-security",
    title: "Privacy and security",
    summary:
      "What data Branching Paths stores locally today, and how future account data will be handled.",
    body: [
      "Reading today does not require an account. Reading position, bookmarks, and discovered endings are stored only in your browser under the key bp-progress:<adventure>. Clearing local progress from the reader erases every trace for that adventure and cannot be undone.",
      "No analytics, tracking pixels, or third-party scripts are loaded by the reading surfaces. The design system uses only same-origin CSS.",
      "When accounts ship, the full privacy notice will be published alongside them. It will describe what fields are collected, how they are used, how long they are kept, and how to delete them.",
      "Credentials are never included in any URL. If you land on a help URL that contains a password or token in its query string or fragment, treat it as a bug and report it — Branching Paths does not construct such URLs.",
    ],
    related: ["accounts", "moderation", "common-errors"],
    match: {
      routes: [/^\/account/, /^\/login/, /^\/register/, /^\/master/],
      sections: ["privacy"],
    },
  },
  {
    slug: "changelog",
    title: "Changelog",
    summary:
      "Where to find what changed in each release, and how versions are labelled.",
    body: [
      "The Changelog page at /changelog lists every published version of Branching Paths, newest first. Each entry links to a per-version page with the full list of added, changed, and removed items.",
      "Versions follow semantic versioning: 0.MINOR.PATCH during pre-release, and 1.0.0 marks the first production-ready cut. The current version is displayed in the footer on every page and links to its own changelog entry.",
      "New behaviour is described in the changelog only after the code that implements it ships. If a topic in help mentions something as planned, look for its release in the changelog to confirm the behaviour is live.",
    ],
    related: ["getting-started", "common-errors"],
    match: { routes: [/^\/changelog/], sections: ["changelog"] },
  },
  {
    slug: "common-errors",
    title: "Common errors",
    summary:
      "What the states you might run into mean, and how to recover from them.",
    body: [
      "Adventure not found: the URL points to a slug the library does not know. This usually means the adventure was renamed or removed. Return to Discover and search again.",
      "Scene not found: the reader URL references a scene that is not part of the current adventure. This can happen after a scene is renamed. Use Start from the beginning on the not-found panel to reset your position.",
      "This adventure has no readable scenes yet: the creator has not published any scenes. Check back after the next update; the adventure page shows when it was last updated.",
      "Help topic not found: the topic slug in the URL is unknown. Use the Help index or the search field on the Help page to locate the topic you want.",
      "If a page keeps failing, clear the local progress for that adventure from the reader and reload. Reading data lives only in your browser and is safe to reset.",
    ],
    related: ["reading", "discovering", "privacy-and-security"],
    match: { sections: ["error"] },
  },
  {
    slug: "hosting-and-health",
    title: "Hosting and health",
    summary:
      "How Branching Paths runs on plain PHP and SQLite, and what the health endpoint exposes.",
    body: [
      "Branching Paths runs on plain PHP 8.2 or newer with a single SQLite database. There is no external cache, queue, or third-party service required to serve the site.",
      "The SQLite database is opened in WAL mode with foreign keys enforced, a ten-second busy timeout, and synchronous journaling in NORMAL mode. Writers acquire a bounded file-based lock so a stalled writer cannot block the site indefinitely.",
      "The /api/health endpoint reports only four things: whether the API is responsive, whether the database is reachable, the current schema version, and the deployed application version. It never exposes filesystem paths, secret values, stack traces, or raw SQL error text.",
      "Operators can run scripts/system-check.php on the server for a fuller pre-flight report covering PHP extensions, filesystem permissions, and lock behaviour. That script is intentionally CLI-only and is never reachable over HTTP.",
    ],
    related: ["privacy-and-security", "common-errors", "changelog"],
    match: { sections: ["hosting", "health"] },
  },
  {
    slug: "public-data-and-visibility",
    title: "Public data and visibility",
    summary:
      "What Branching Paths shows publicly, what stays hidden, and how the reader falls back to fixtures when the API is unavailable.",
    body: [
      "Only adventures whose state is published, on hold, complete, or archived are readable publicly. Drafts and suspended adventures are never returned by the public API and cannot be discovered by browsing.",
      "The Discover list additionally excludes unlisted adventures, which remain readable at their direct link. Hidden and draft scenes are never returned, and choices whose destination is not a published scene are removed from the response so unpublished scene identifiers cannot be inferred.",
      "The public pages render immediately from typed fixtures and then upgrade to live API data when it arrives. If the backend is unavailable, the fixtures remain visible so reading is not blocked.",
    ],
    related: ["reading", "discovering", "hosting-and-health"],
    match: { sections: ["discover", "adventure", "reader"] },
  },
  {
    slug: "master-sign-in",
    title: "Administrator sign in",
    summary:
      "How administrators reach the master console and what a failed sign-in means.",
    body: [
      "The master console lives at /master. Only accounts that hold the admin role can sign in — everyone else sees an incorrect-credentials response, whether the email exists or not, so the sign-in page cannot be used to probe for admin accounts.",
      "The initial administrator is created from the shell with scripts/bootstrap-admin.php. That script also promotes an existing account when you pass the email and username of a user who already registered.",
      "Sessions are stored in an HMAC-signed HttpOnly cookie scoped to the site origin. Signing out clears the cookie on the current browser only; other browsers stay signed in until their session expires or the admin role is revoked.",
    ],
    related: ["email-settings", "email-queue", "accounts"],
    match: { routes: [/^\/master\/login$/, /^\/master$/], sections: ["master"] },
  },
  {
    slug: "email-settings",
    title: "Email settings",
    summary:
      "How SMTP credentials are stored, how to update them, and how to send a test message.",
    body: [
      "The email settings page at /master/settings/email captures the host, port, encryption mode (STARTTLS, TLS, or none), username, password, from address, from name, reply-to address, retry limit, and batch size the email worker uses.",
      "Passwords are encrypted at rest with an application key kept outside the database. The form never shows the stored password — it displays a placeholder when a value is on file. Type a new password to rotate it, or use Clear stored password to remove it.",
      "The Send test action queues the operator_test template for the recipient you enter. The email is delivered by the same worker that processes the rest of the queue, so a test message is also a live check that the worker is running.",
      "Turning off the Enabled toggle stops the worker from sending. Pending messages stay in the queue and resume when you re-enable it.",
    ],
    related: ["email-queue", "master-sign-in", "common-errors"],
    match: { routes: [/^\/master\/settings\/email$/], sections: ["master"] },
  },
  {
    slug: "email-queue",
    title: "Email queue",
    summary:
      "What each queue status means, how retries work, and when to cancel a pending message.",
    body: [
      "Every outbound email is written to a durable queue with one of five statuses: pending (waiting for the next worker run), sending (a worker has just claimed it), sent (delivered to the SMTP server), failed (retry limit reached or a permanent error), or cancelled (removed before it left).",
      "The worker retries transient failures with an exponential backoff plus jitter, capped at one hour, and gives up after the configured retry limit. Errors shown in the Last error column are short redacted tokens (for example smtp_552 or transport_error) — the raw provider reply is deliberately not stored so recipient addresses and credentials cannot leak into this page.",
      "Only pending messages can be cancelled. Sending, sent, failed, and cancelled rows are historical and cannot be re-sent from this page — enqueue a new message from the feature that produced it instead.",
      "If a worker crashes mid-send, its row is stuck in sending until the next worker run notices the stale claim (five minutes by default) and returns it to pending. You do not need to intervene manually.",
    ],
    related: ["email-settings", "master-sign-in", "common-errors"],
    match: { routes: [/^\/master\/email-queue$/], sections: ["master"] },
  },
  {
    slug: "verifying-your-email",
    title: "Verifying your email",
    summary:
      "Why we send a verification link and what to do if it expired or is missing.",
    body: [
      "After you register we send a single-use link to the address you gave us. Opening the link marks your account active. Until then, sign-in is refused with a clear pending-verification message rather than an incorrect-password error.",
      "Verification links expire twenty-four hours after they are issued and can only be opened once. If the link has expired, has already been used, or never arrived, request a new one from the verify-email page — the response is the same whether or not an unverified account exists at that address, so nothing about your account is leaked to a third party.",
    ],
    related: ["signing-in", "account-security", "common-errors"],
    match: { routes: [/^\/verify$/], sections: ["auth"] },
  },
  {
    slug: "signing-in",
    title: "Signing in and out",
    summary: "What sign-in requires, how sessions are protected, and how to sign out safely.",
    body: [
      "Sign in with the email address and password you registered with. Pending, suspended, and deleted accounts cannot sign in even with the correct password, and the failure message deliberately does not distinguish those cases from a wrong password.",
      "A successful sign-in issues a session cookie that is HttpOnly, SameSite=Lax, marked Secure on HTTPS, and expires after fourteen days. Any earlier session for your account is revoked before the new cookie is issued, so a device that was left signed-in elsewhere will be signed out.",
      "Signing out revokes the session on the server and clears the cookie in your browser. If you are worried a session is still active on another device, change your password — that revokes every session and forces every device to sign in again.",
    ],
    related: ["account-recovery", "account-security", "verifying-your-email"],
    match: { routes: [/^\/login$/, /^\/change-password$/], sections: ["auth"] },
  },
  {
    slug: "account-recovery",
    title: "Password recovery",
    summary:
      "How the forgot-password and reset-password flow works and why the messages look the same either way.",
    body: [
      "Enter your email on the forgot-password page and we email a reset link. The response you see is identical whether or not an active account exists at that address — this prevents a stranger from probing which addresses are registered.",
      "Reset links are single-use and expire sixty minutes after they are issued. Opening the link takes you to a page that sets a new password (minimum twelve characters, matching confirmation) and then revokes every session for your account, so any device still signed in is dropped.",
      "If a reset link has already been used or has expired, request a new one — the old link cannot be replayed.",
    ],
    related: ["signing-in", "account-security", "common-errors"],
    match: { routes: [/^\/forgot-password$/, /^\/reset-password$/], sections: ["auth"] },
  },
  {
    slug: "account-security",
    title: "Account security",
    summary: "Password hygiene, session revocation, and how change-password protects you.",
    body: [
      "Passwords must be at least twelve characters. Choose a phrase you use nowhere else; a password manager makes this easy. Passwords are stored only as Argon2id hashes — the plaintext is never written to the database.",
      "The change-password page requires your current password before it will accept a new one. On success, every session other than the one making the request is revoked, and the current cookie is rotated to a fresh value. This makes change-password the fastest way to lock out a device you no longer control.",
      "We email you whenever your password is changed via the reset flow or the change-password page. If you ever see that notification without having initiated it, use forgot-password immediately to lock the account.",
    ],
    related: ["account-recovery", "signing-in", "accounts"],
    match: { routes: [/^\/change-password$/], sections: ["auth"] },
  },
  {
    slug: "account-dashboard",
    title: "Account dashboard",
    summary: "Manage your profile, security, notifications, adventures, and bookmarks.",
    body: [
      "The account dashboard at /account groups everything you can do as a signed-in reader. Every section requires a live session cookie; if you were signed out in the background you will see a prompt to sign in again instead of the page content.",
      "Profile edits change your display name, short bio, and whether other readers can see your profile. Nothing you enter here can control the layout of another reader's screen — story containment rules still apply.",
      "Change email requests a single-use, expiring link to the new address. Your current email stays on file until you open that link. If you never confirm, the request quietly expires after 24 hours.",
      "Notifications toggles what we email you about. Everything except password-change alerts is optional.",
      "Security lists your active sessions and lets you sign out every session except the current one with a single action. Use it as soon as you suspect a device is compromised.",
      "My adventures, contributions, and bookmarks read from the server so the same data appears on every device you sign in from. Local bookmarks and history collected before you signed up can be imported after an explicit confirmation.",
    ],
    related: ["account-security", "signing-in", "privacy-and-security"],
    match: { routes: [/^\/account(\/.*)?$/], sections: ["account"], signedIn: true },
  },
  {
    slug: "restricted-writing-tools",
    title: "Restricted writing tools",
    summary:
      "What the story editor allows, what it removes, and how pasted content is normalised.",
    body: [
      "The story editor is deliberately narrow. The toolbar exposes paragraphs, bold, italic, underline, Heading 2, Heading 3, bulleted lists, numbered lists, blockquotes, horizontal rules, and undo/redo — nothing else. There is no link tool, image tool, colour picker, font selector, alignment control, table, embed, or source-code view.",
      "This is not an oversight. Story reading works best when every reader sees the same typography and layout. A story that could set its own fonts, colours, widths, or alignment would break the container the reader relies on and would let a bad actor build convincing look-alike UI.",
      "When you paste from another source we keep the text and the allow-listed formatting and discard the rest. Links are unwrapped so their text stays but their destination is removed. Pasted images, embedded media, scripts, styles, tables, and custom HTML are dropped. URLs pasted as plain text are inserted as literal text — the editor never turns them into hyperlinks automatically.",
      "The same rules run again on the server before your content is stored. That means the editor is a helpful writing aid, not a security fence — a hostile client cannot bypass the toolbar to smuggle in scripts, iframes, or arbitrary HTML.",
      "The character counter and every search, snippet, and export uses the plain-text projection of your writing. Formatting characters don't count toward the limit; they just shape how the reader sees the words.",
    ],
    related: ["creating", "contributing", "privacy-and-security"],
    match: {
      routes: [/^\/(create|adventures|contribute)(\/.*)?$/],
      sections: ["editor", "authoring"],
      roles: ["author", "master"],
    },
  },
  {
    slug: "story-map",
    title: "The story outline",
    summary:
      "How to read the outline of an adventure, open branches, and search for a scene.",
    body: [
      "Every adventure has an outline: each scene listed under the choice that leads to it, so you can see the shape of the story without reading it all.",
      "Branches fold and unfold. A folded branch says how many choices lead out of it; unfolding it shows them. Very large stories are loaded a branch at a time, so opening one may take a moment.",
      "The search box finds scenes by title and shows the path back to the opening scene, so you can tell where a match sits in the story.",
      "Readers see published scenes only. A choice that leads to an unfinished or hidden scene is simply not listed, and nothing on the page hints that it exists.",
      "The outline works with a keyboard: arrow keys move between scenes, right and left open and close a branch, and Home and End jump to the first and last scene shown. Assistive software reads it as a nested list with its depth announced.",
    ],
    related: ["story-check", "reading", "discovering"],
    match: {
      routes: [/^\/adventure\/[^/]+\/map$/],
      sections: ["story-map"],
    },
  },
  {
    slug: "story-check",
    title: "The story check",
    summary:
      "The problems the owner map reports, and what each one means.",
    body: [
      "On the manage page, the story map shows the whole story — including drafts and hidden scenes, each with a label saying so — and a story check lists anything that looks broken.",
      "The check looks for: a choice pointing at a scene that is gone; a published choice leading into a scene readers cannot see; a scene with no text; two choices on the same scene that read the same; a published scene that is not an ending yet offers no way onward; a scene nothing leads to; a broken branch shape; and a story that runs unusually deep.",
      "Branching Paths keeps stories as trees: every scene except the opening one has exactly one scene leading to it, and choices never loop back or join two branches together. Anything breaking that rule is reported as a broken branch shape rather than drawn on the map.",
      "The check never changes or removes anything. It tells you what to look at; every fix is yours to make.",
    ],
    related: ["story-map", "managing-adventures", "moderation"],
    match: {
      routes: [/^\/manage\//],
      sections: ["publishing", "story-map"],
      roles: ["author", "master"],
    },
  },
  {
    slug: "master-administration",
    title: "Master administration",
    summary: "What moderators and administrators can do in the platform console.",
    body: [
      "The master console at /master has sections for users, adventures, submissions, reports, the email queue, settings, and activity. You only see the sections your role can use.",
      "Moderators can review reports, hide or restore reported scenes, suspend or restore adventures, and escalate account problems to administrators.",
      "Administrators can also change platform roles, suspend or restore accounts, configure registration, anonymous use, global limits, email sending and maintenance mode, manage the email queue, send password-reset emails, transfer adventure ownership, and review security-related activity.",
      "Role changes, ownership transfers, email sign-in changes, maintenance mode, and destructive moderation (hiding content or suspending) ask for your password again first.",
      "The console never shows passwords, sign-in tokens, reset or verification links, the email server password, encryption keys, or where data is stored. The last administrator cannot be removed or suspended.",
    ],
    related: ["moderation", "email-preferences", "maintenance-and-backups"],
    match: { routes: [/^\/master/], roles: ["master"] },
  },
  {
    slug: "maintenance-and-backups",
    title: "Maintenance, backups, and hosting",
    summary: "Maintenance switches, read-only mode, and how backups and upgrades work.",
    body: [
      "In the master console under Settings, Maintenance, administrators can turn on read-only mode, show a custom notice to visitors, turn off new registrations, stop new adventures being created, and pause contributions everywhere.",
      "Read-only mode keeps public reading working, and administrators can still sign in and use the maintenance tools. Every other change waits until it is turned off.",
      "Backups are made by the server on a schedule using the database's own safe copy method, so they are never taken mid-change. Each backup is checked before it is kept, and older backups are thinned out automatically.",
      "Before an upgrade, turn on read-only mode and take a backup. If an upgrade has to be undone, the previous version is put back together with that backup; the database at that moment is saved first, so nothing is lost.",
    ],
    related: ["master-administration"],
    match: { routes: [/^\/master\/settings/], roles: ["master"] },
  },
  {
    slug: "revision-history",
    title: "Revision history",
    summary:
      "How earlier versions of published text are kept, compared, and restored.",
    body: [
      "Once an adventure or scene is published, Branching Paths saves the old wording automatically before anyone changes the adventure description, the writing guidelines, a scene title, a scene's text, or a choice's text.",
      "The latest 20 versions of each piece of text are kept; older ones are removed as new ones arrive.",
      "On the manage page, owners, editors, and administrators can see when each version was saved and who made the change, compare it with the current text, and restore it.",
      "Restoring never throws anything away: the text being replaced is saved as a new revision first, so you can always switch back. Archived adventures can be viewed but not restored.",
    ],
    related: ["managing-adventures", "story-check"],
    match: {
      routes: [/^\/manage\//],
      sections: ["publishing"],
      roles: ["author", "master"],
    },
  },
  {
    slug: "exports",
    title: "Exporting an adventure",
    summary: "Download your adventure as data, plain text, a printable page, or an offline playable file.",
    body: [
      "Owners, editors, and administrators can export an adventure from the manage page in four formats: JSON data, plain text, a printable page, and a standalone playable page.",
      "Exports contain only published scenes, the choices between them, endings, the adventure's details, content warnings, writing guidelines, and credits. Drafts and hidden scenes are left out.",
      "Credits use display names only. Anonymous contributions are never credited, and exports never include email addresses, sign-in details, reports, private notes, or activity records.",
      "The playable page works offline in any modern browser: it loads nothing from the internet, contains no tracking, and has no editing or moderation tools.",
    ],
    related: ["managing-adventures", "revision-history"],
    match: {
      routes: [/^\/manage\//],
      sections: ["publishing"],
      roles: ["author", "master"],
    },
  },
  {
    slug: "notifications",
    title: "Notifications",
    summary:
      "What lands in your inbox, how to mark items read, and which notices you cannot delete.",
    body: [
      "Your inbox at Account → Inbox collects everything the site needs to tell you: a branch was submitted to your adventure, your branch was published or declined, changes were requested, a revised submission is ready, a review is needed, a collaborator invitation arrived, ownership of an adventure changed, an adventure you follow was updated, and account security events such as a new sign-in.",
      "You can mark one notification read, mark everything read at once, and delete routine notifications you no longer need. 'Clear read' removes every routine item you have already read.",
      "Account security notices cannot be deleted. They are your own record of what happened to your account, so they stay in the inbox even after you have read them.",
      "Notifications always appear in the inbox regardless of your email settings. Turning email off changes where a message reaches you, never whether it is recorded.",
    ],
    related: ["email-preferences", "following-and-bookmarks", "collaborators"],
    match: {
      routes: [/^\/account\/inbox$/],
      sections: ["notifications"],
      signedIn: true,
    },
  },
  {
    slug: "email-preferences",
    title: "Email preferences",
    summary:
      "Choose which notifications also reach you by email, and what stays on permanently.",
    body: [
      "Account → Notifications lists every kind of notification with a single switch for email. The switch controls email only; the inbox always receives the notification.",
      "Security and recovery email cannot be switched off. Sign-in alerts, password changes, email-address confirmations, and account recovery are how an account is protected and regained, so those messages are always sent.",
      "Updates from adventures you follow are bundled. Instead of one email per new branch, a single message gathers everything that happened since the last one was sent, so following an active adventure never floods your inbox.",
      "Changing a preference takes effect immediately for messages sent after you save it. Anything already queued for delivery still goes out.",
    ],
    related: ["notifications", "following-and-bookmarks", "accounts"],
    match: {
      routes: [/^\/account\/notifications$/],
      sections: ["notifications", "account"],
      signedIn: true,
    },
  },
  {
    slug: "following-and-bookmarks",
    title: "Following and bookmarks",
    summary:
      "A follow subscribes you to updates; a bookmark remembers where you stopped reading.",
    body: [
      "These are two separate things and neither one implies the other. Following an adventure subscribes you to its updates: when a new branch is published, you hear about it. Bookmarking records a reading position so you can pick the story back up where you left it.",
      "You can follow an adventure from its landing page while signed in, and unfollow it there or from Account → Notifications, which lists everything you currently follow.",
      "Unfollowing stops the updates and leaves your reading position untouched. Clearing a bookmark changes nothing about your subscriptions.",
      "Follower counts are not shown anywhere on the site, to you or to the adventure's team. Following is a private choice about what you want to hear about.",
    ],
    related: ["notifications", "reading", "email-preferences"],
    match: {
      routes: [/^\/adventure\/[^/]+$/, /^\/account\/notifications$/],
      sections: ["notifications", "adventure"],
    },
  },
];



/** Topic slugs surfaced first when no more specific context matches. */
const FALLBACK_ORDER = [
  "getting-started",
  "discovering",
  "reading",
  "changelog",
  "common-errors",
];

export interface HelpContext {
  pathname: string;
  signedIn: boolean;
  role: HelpRole;
  section?: string;
  adventureStatus?: StoryStatus;
  setting?: string;
}

/**
 * Score topics against the current context and return them best-first.
 * The scoring is intentionally simple and deterministic so the tests can
 * assert the exact order that a given context produces.
 */
export function pickContextualTopics(
  context: HelpContext,
  limit = 4,
): HelpTopic[] {
  const scored = HELP_TOPICS.map((topic) => {
    let score = 0;
    const m = topic.match;
    if (m.routes?.some((re) => re.test(context.pathname))) score += 8;
    if (context.section && m.sections?.includes(context.section)) score += 6;
    if (m.roles?.includes(context.role)) score += 3;
    if (typeof m.signedIn === "boolean" && m.signedIn === context.signedIn) {
      score += 1;
    }
    if (
      context.adventureStatus &&
      m.adventureStatus?.includes(context.adventureStatus)
    ) {
      score += 2;
    }
    // Fallback ordering nudges the general topics up when nothing else matches.
    const fallbackIndex = FALLBACK_ORDER.indexOf(topic.slug);
    if (fallbackIndex >= 0) score += 0.1 * (FALLBACK_ORDER.length - fallbackIndex);
    return { topic, score };
  });
  scored.sort((a, b) => b.score - a.score);
  return scored.slice(0, limit).map((s) => s.topic);
}

export function findTopic(slug: string | undefined): HelpTopic | undefined {
  if (!slug) return undefined;
  return HELP_TOPICS.find((t) => t.slug === slug);
}

/**
 * Strip anything that could carry credentials or session state from a
 * pathname before it is used to build a help URL. Query strings and
 * hash fragments are removed; the pathname is returned as-is.
 */
export function sanitizeHelpPath(pathname: string): string {
  const noHash = pathname.split("#")[0] ?? "";
  const noQuery = noHash.split("?")[0] ?? "";
  return noQuery;
}

/**
 * Simple keyword search over titles, summaries, and bodies. Case- and
 * diacritics-tolerant; every whitespace-separated term must appear in
 * at least one of the searched fields.
 */
export function searchHelpTopics(query: string): HelpTopic[] {
  const q = query.trim().toLowerCase();
  if (!q) return HELP_TOPICS;
  const terms = q.split(/\s+/).filter(Boolean);
  return HELP_TOPICS.filter((topic) => {
    const haystack = [
      topic.title,
      topic.summary,
      topic.body.join(" "),
      topic.slug,
    ]
      .join(" ")
      .toLowerCase();
    return terms.every((t) => haystack.includes(t));
  });
}
