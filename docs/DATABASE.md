# Database

Branching Paths uses a single SQLite database file stored under `private/data/`.

## Pragmas (applied on every connection in later versions)

- `PRAGMA journal_mode = WAL`
- `PRAGMA foreign_keys = ON`
- `PRAGMA busy_timeout = 10000`
- `PRAGMA synchronous = NORMAL`

## Write handling

All database-changing operations go through a single `WriteLock` service backed by `flock()`. Each write path:

1. Validates input before acquiring the lock.
2. Acquires the write lock.
3. Begins a short transaction when multiple rows are involved.
4. Rechecks critical state inside the transaction.
5. Performs the write.
6. Commits.
7. Releases the lock in `finally` logic.

Reads never acquire the write lock.

## Schema

The schema is introduced in later versions of the roadmap, starting with `0.9.0`. No tables exist yet in `0.1.0`.
