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
