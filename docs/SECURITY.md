# Security

## Principles

- Treat all inbound data as untrusted (form input, HTML from the WYSIWYG editor, uploaded content).
- Sanitize rich text in PHP using a strict allowlist before storage and before display.
- Keep secrets out of the repository. Use `.env` on the server; never commit it.
- Keep runtime data (`private/`) outside the web root.

## Rich text allowlist

Allowed: paragraphs, bold, italic, underline, H2, H3, bulleted lists, numbered lists, blockquotes, horizontal rules, undo, redo.

Never allowed: images, links, automatic links, inline code, code blocks, tables, video, audio, embeds, attachments, iframes, forms, scripts, SVG, MathML, custom HTML, HTML source mode, font selection, font sizes, text colors, background colors, alignment controls.

## Public changelog

Public changelog entries must exclude secrets, exploit details, private administrator notes, raw errors, and internal-only infrastructure details.

## Drafts, preview, and publishing

- Draft adventures and unpublished scenes are served only through the manage and preview endpoints, which require a session and an owner/editor/administrator role.
- Roles are always derived server-side; never trust a role, owner id, or adventure id supplied by the client.
- Preview responses are marked `noindex, nofollow` at the HTTP and document level.
- Archived adventures are read-only. Unpublishing changes status only and never deletes content.
