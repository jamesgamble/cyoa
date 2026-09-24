# Branching Paths

A self-contained collaborative choose-your-own-adventure application.

- Production URL: https://cyoa.gamblelan.com
- Frontend: React + TypeScript + Vite + React Router (built to static assets)
- Backend: Plain PHP 8.2+ with PDO SQLite
- Hosting: Apache or Nginx
- Email: SMTP2GO

## Repository layout

```
frontend/         React + Vite source (built to static assets)
public/           Web root served by Apache/Nginx
public/api/       PHP API entry points
app/              PHP application code (config, db, repositories, services, security)
private/          Runtime data outside the web root
  data/           SQLite database file
  locks/          Write lock file(s)
  logs/           Application logs
  backups/        Database backups
scripts/          Maintenance / deployment scripts
docs/             Project documentation
VERSION           Current semantic version
CHANGELOG.md      Full change history
```

## Development

```
cd frontend
npm install
npm run dev       # local dev server
npm run build     # production build to frontend/dist
npm run test      # run tests
npm run typecheck # TypeScript check
```

## Production build

```
bash scripts/build.sh    # build frontend into public/
bash scripts/deploy.sh   # build + initialize + migrate + system check
```

See `docs/HOSTING.md` for server setup.

## Version

Current version: see `VERSION`. Public changelog is available at `/changelog` in the app.
