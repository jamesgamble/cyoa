# Help system

The help system lives inside the application and only describes behavior that is actually implemented.

## Surfaces

- `/help` — help index
- `/help/:topic` — full help topic pages
- Persistent Help button on every page
- Contextual help drawer (introduced in `0.8.0`)
- Inline help controls near individual settings and actions

## Contextual selection inputs

Help selection considers:

- Current route
- Authentication state
- User role
- Adventure state
- Current management section
- Current setting or action

Help content is local to this project and must not describe features that do not yet exist.
