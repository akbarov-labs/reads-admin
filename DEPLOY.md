# Deploying to Contabo

Every push to `main` builds a Docker image on GitHub, pushes it to GitHub
Container Registry, then SSHs into your Contabo server to pull it and
restart — the same box already running kitoblarim and my-blog behind Caddy.

**Footprint:** one container (nginx + PHP-FPM together, ~150–250MB RAM),
SQLite instead of a separate MySQL server, joins the Caddy network you
already have — no new reverse proxy, no second database process.

## One-time server setup

Do this once, by hand, over SSH.

**1. Find Caddy's Docker network**

Already done — kitoblarim's Caddy container (`kitoblarim-caddy-1`) is on the
`kitoblarim_default` network, and `docker-compose.prod.yml` already targets
it. If kitoblarim's stack is ever rebuilt under a different Compose project
name, its network name changes too — re-check with:

```bash
docker inspect kitoblarim-caddy-1 --format '{{json .NetworkSettings.Networks}}'
```

and update the `name:` value under `networks: caddy_net:` in
`docker-compose.prod.yml` in the repo to match.

**2. Create the deploy directory and the real `.env`**

```bash
mkdir -p /opt/apps/reads-admin
cd /opt/apps/reads-admin
```

Generate an app key:

```bash
docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Create `.env` here (copy `.env.production.example` from the repo as a
starting point) and paste that key into `APP_KEY=`. This file stays on the
server permanently — deploys never touch it.

**3. Add the Caddy site block**

Copy the block from `deploy/Caddyfile.snippet` in the repo into your
existing Caddyfile, then reload Caddy (see the comment in that file for the
exact command). Caddy will fetch the Let's Encrypt certificate for
`dashboard.kitoblarim.uz` automatically the first time it's requested.

**4. Log the server in to GHCR (one time, for manual operations)**

The deploy workflow authenticates its own pull using the `GITHUB_TOKEN`
GitHub generates per run, so **automated deploys need nothing from you
here**. But that token dies with the run, which means a *manual*
`docker compose pull` / `up` on the server later will fail with
`error from registry: denied`.

To be able to intervene by hand, log the server in once with a long-lived
credential: on GitHub go to Settings → Developer settings → Personal access
tokens → Tokens (classic) → generate one with only the `read:packages`
scope, then on the server:

```bash
echo '<the token>' | docker login ghcr.io -u <your-github-username> --password-stdin
```

This persists in `~/.docker/config.json` and survives reboots.

**5. Create the `production` environment and add its secrets**

The `deploy` job in `deploy.yml` targets a GitHub **environment** named
`production` (this also gives you a deployment record + a clickable link to
`https://dashboard.kitoblarim.uz` in the Actions run, and lets you require a
manual approval before every deploy if you want one).

Repo → Settings → Environments → New environment → name it exactly
`production` (case-sensitive, must match `deploy.yml`) → then add these as
**environment secrets** (not repo-level secrets):

| Secret | Value |
|---|---|
| `CONTABO_HOST` | Server IP or hostname |
| `CONTABO_USER` | SSH username |
| `CONTABO_PORT` | SSH port (usually `22`) |
| `CONTABO_SSH_KEY` | Private key (PEM) for a key whose public half is in that user's `~/.ssh/authorized_keys` |
| `CONTABO_DEPLOY_PATH` | `/opt/apps/reads-admin` (or wherever you created it in step 2) |

`GITHUB_TOKEN` (used to push to GHCR in `build-and-push`, and to pull on the
server in `deploy`) needs nothing from you — GitHub generates and injects it
automatically on every run. The token from step 4 is only for your own manual
`docker` commands on the server; it is not a repository secret.

Don't reuse a personal key you need elsewhere — generate a dedicated
deploy keypair (`ssh-keygen -t ed25519 -f ~/.ssh/deploy_key_reads_admin -N ""`),
add the `.pub` half to the server's `authorized_keys`, and put the contents
of the private half into `CONTABO_SSH_KEY`.

Optional but recommended since this server also runs kitoblarim and
my-blog: on the `production` environment's settings page, turn on "Required
reviewers" and add yourself. That makes every deploy pause for a manual
click in the Actions tab after the image builds, before it ever touches the
server.

**6. First deploy**

Push to `main` (or run the workflow manually from the Actions tab). Watch
it in the Actions tab. Once it's green:

```bash
docker ps            # reads-admin-app should be Up
docker compose -f docker-compose.prod.yml logs -f app
```

Visit `https://dashboard.kitoblarim.uz`.

## Everyday use

Just push to `main`. That's the whole pipeline — build, push to GHCR, pull
on the server, restart, run any new migrations automatically.

## Changing `.env` on the server

After editing `.env`, you must **recreate** the container, not restart it:

```bash
docker compose -f docker-compose.prod.yml up -d --force-recreate
```

`docker compose restart` reuses the existing container, and a container's
environment is captured once when it is *created*. Restarting re-runs
`entrypoint.sh` (so config/route/view caches are rebuilt) but the process
still sees the old values, so `.env` edits appear to have no effect.

## Rollback

Every image is also tagged with its commit SHA. To roll back:

```bash
cd /opt/apps/reads-admin
IMAGE_TAG=<previous-commit-sha> docker compose -f docker-compose.prod.yml up -d
```

Note that `IMAGE_TAG` defaults to `latest`, which the server never pulls
during normal deploys (the workflow pulls by commit SHA). If a bare
`docker compose up` fails with `error from registry: denied`, either log in
per step 4 above, or point it at an image already on the box:

```bash
docker images ghcr.io/akbarov-labs/reads-admin   # see what's local
IMAGE_TAG=<one-of-those-tags> docker compose -f docker-compose.prod.yml up -d
```

## Troubleshooting

**Every request 500s, log shows "Unsupported cipher or incorrect key
length"** — `APP_KEY` is malformed. Almost always quoting: see the warning
at the top of `.env.production.example`. `APP_KEY="base64:..."` is wrong,
`APP_KEY=base64:...` is right. A correct value is exactly 51 characters:
`base64:` plus 44 characters. Check with:

```bash
grep '^APP_KEY=' .env | awk -F= '{print length($2)}'
```

**Log shows "Cannot modify header information - headers already sent"** —
this is a *symptom*, not the cause. Something failed earlier in the request
and Laravel's error page rendering hit a second failure on the way out. Look
for the other error logged at the same timestamp:

```bash
docker exec reads-admin-app grep 'production.ERROR' storage/logs/laravel.log | sort -u
```

**Container restart-loops, `docker exec` says "is restarting"** —
`entrypoint.sh` runs under `set -e`, so any failed step (missing `APP_KEY`,
a failed migration) aborts before `supervisord` starts, meaning nothing
listens on port 80 and Caddy returns 502. `docker logs reads-admin-app`
works on a restarting container and shows exactly which step died.

## Notes

- Migrations run automatically on container start (`entrypoint.sh`) — safe
  to leave as-is; Laravel only applies new ones.
- `storage/` is the one persistent volume — your SQLite database, uploaded
  avatars/covers, logs, and cached views survive every deploy even though
  the image is replaced wholesale each time.
- `public/storage` (the symlink to `storage/app/public`, used for
  translator avatars/covers) is recreated on every boot by
  `entrypoint.sh` — it lives in the image, not the volume, so it wouldn't
  otherwise survive a redeploy.
- Reads (the public Next.js site, `tarjima.kitoblarim.uz`) calls this API.
  `config/cors.php` already allows any origin for `api/*` with no
  credentials, so the visitor's browser can POST inquiries cross-origin
  without further changes here.
- If you'd rather use MySQL later, that's a small change (swap
  `DB_CONNECTION`, add a `db` service, mount a volume for it) — say the
  word and it's a quick follow-up.
