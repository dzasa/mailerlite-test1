# Subscriber API

`POST /api/v1/subscribers` accepts an email and a status (`subscribed` / `unsubscribed`). The
request only touches Redis — a uniqueness claim and a queue push — and a background worker writes
to MySQL. Plus a small Vue UI. No authentication.

Everything runs in Docker. Nothing is installed on your machine except Docker itself.

## Run it

```bash
./start.sh
```

Installs dependencies, starts all five containers, migrates, runs the tests, and prints the URL.
Safe to re-run. First boot takes a few minutes; later runs take seconds.

Then open **http://localhost:8080**

```bash
./start.sh --browser    # also run Playwright browser tests (~1GB first time)
./start.sh --stress     # also load-test the API and show the DB insert rate
./start.sh --no-tests   # skip tests
./start.sh --fresh      # wipe all data and start over
./vendor/bin/sail down  # stop
```

## What to try

| Where | Action | Expected |
|---|---|---|
| `/` | Subscriber list | Everyone added so far, newest first |
| `/subscribers/create` | Submit a new email | "Got it — we are processing your subscription." |
| | Submit the **same** email again | "The email has already been taken." |
| | Submit `  MiXeD@Example.COM  ` | Stored as `mixed@example.com` |

The row appears on `/` a moment after submitting — persistence is queued, not synchronous.

## Services

| Service | Role | Port |
|---|---|---|
| `laravel.test` | App | 8080 |
| `vite` | Frontend dev server + HMR | 5173 |
| `queue` | Worker that writes to MySQL | — |
| `mysql` | Database | 3306 |
| `redis` | Uniqueness claims + queue | 6379 |

## Tests

```bash
./vendor/bin/sail artisan test                             # all suites
./vendor/bin/sail artisan test --exclude-testsuite=Browser # skip browser tests
vendor/bin/pint                                            # formatting
vendor/bin/phpstan analyse --memory-limit=1G               # types (needs the raised limit)
```

PHPStan reports one pre-existing error in `database/factories/UserFactory.php` from the starter
kit; everything else is clean.

## Load test

```bash
./vendor/bin/sail artisan subscribers:stress --requests=1000 --concurrency=50 --watch
./vendor/bin/sail artisan subscribers:monitor              # just watch rows land
```

Reports throughput, status-code breakdown, latency percentiles, and how fast the worker drains the
queue into MySQL. To measure the endpoint rather than the rate limiter, set
`SUBSCRIBERS_RATE_LIMIT_PER_MINUTE=0` in `.env` first — otherwise anything above 120 requests per
minute returns `429`. These are dev-server figures, not production capacity.

## Troubleshooting

**Port already in use.** Change `APP_PORT` (or `VITE_PORT`, `FORWARD_DB_PORT`) in `.env`, then
`./vendor/bin/sail down && ./start.sh`.

**Blank page or "Vite manifest not found."** The Vite container is still starting; check
`docker compose logs vite` for "VITE ready". If you previously ran `npm run dev` on your host,
delete the stale `public/hot`. Re-running `./start.sh` fixes both.

**Never run `npm` on your host for this project.** `node_modules` is a container volume holding
Linux binaries; installing from macOS or Windows overwrites it and breaks the Vite container. Use
`docker compose exec vite npm ...` instead.

**Changed a job, enum or the registry?** Workers load code once at boot:
`docker compose restart queue`.
