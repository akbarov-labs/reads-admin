# Deploying to Contabo

Every push to `main` builds one Docker image on GitHub and pushes it to
GitHub Container Registry. It then goes to **staging**
(`https://reads-admin.staging.sarvarlogs.uz`, password protected)
automatically, and to **production** (`https://dashboard.kitoblarim.uz`)
only when you press **Actions → Promote to production → Run workflow**.
(The repository is private, and GitHub's free plan has no "Required
reviewers" for private repositories, so that button is the approval step.)
Production always runs an image that already ran on staging — new
migrations included, since the container runs them on start.

## How a deploy reaches the server

CI logs in as `deployer`, a user that can only run
`/usr/local/sbin/deploy-app reads-admin <tag>` (or `reads-admin-staging`
with the staging key) and has no shell. Everything that decides what runs —
the compose file, `.env`, the image name — lives on the server, owned by
root, so a leaked CI key can only redeploy an existing image of this app.

| | Production | Staging |
|---|---|---|
| Folder | `/opt/apps/reads-admin` | `/opt/apps/reads-admin-staging` |
| Containers | `reads-admin-app`, `reads-admin-backup` | `reads-admin-staging-app` (no backups) |
| Storage volume | `reads-admin_app_storage` | `reads-admin-staging_app_storage` |
| Public site it feeds | `reads-app` | `reads-staging-app` |
| Bot catalogue sync | on | off |

The folders and their `.env` are root's: prefix the `docker compose`
commands on this page with `sudo`.

The server-wide setup and the tools are in the `akbarov-labs/reads` repo:
`docs/server-hardening.md`, and `docs/add-app.md` for moving a project
(oʻzbekcha: `docs/add-app.uz.md`). Moving this app is described below in
"Moving to deployer and staging".

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

GitHub's side — the `staging` and `production` environments, each with its
own `CONTABO_SSH_KEY`, plus the four `CONTABO_*` repository variables — is
step 4 of `docs/add-app.md` in the Reads repo. `GITHUB_TOKEN` (used to push
to GHCR and to pull on the server) needs nothing from you. The token from
step 4 above is only for your own manual `docker` commands on the server.

**6. First deploy**

Push to `main`; staging deploys. Then **Actions → Promote to production →
Run workflow**. Once it's green:

```bash
docker ps            # reads-admin-app should be Up
sudo docker compose -f docker-compose.prod.yml logs -f app
```

Visit `https://dashboard.kitoblarim.uz`.

## Everyday use

Push to `main` → staging updates (run migrations on its copy) → look at
it (username `staging`) → **Actions → Promote to production → Run
workflow** → production gets the image of the latest commit on `main`, the
one staging runs.

Wait until the "Build and deploy" run is green before promoting. Don't like
what staging shows? Don't promote: push a fix, and promote once it's on
staging.

A change to `docker-compose.prod.yml` or `docker-compose.staging.yml` is
not shipped by CI. Copy it to the server by hand:

```bash
scp docker-compose.prod.yml sarvar@169.58.243.86:/tmp/
ssh -t sarvar@169.58.243.86 'sudo install -m 644 -o root -g root /tmp/docker-compose.prod.yml /opt/apps/reads-admin/'
```

## Moving to deployer and staging (once)

Follow `docs/add-app.md` in the Reads repo. This app is private, so its
GitHub side needs the four `CONTABO_*` variables on this repository (step 4
there). First add the DNS record `reads-admin.staging` → `169.58.243.86` at
ahost.uz, so staging gets its certificate on its first deploy. On the Mac:

```bash
deploy/prepare-app reads-admin ~/dev/sites/Reads-admin
```

On the server:

```bash
sudo add-app reads-admin ghcr.io/akbarov-labs/reads-admin /home/sarvar/reads-admin
```

Then, before merging, three steps only this app needs.

**1. Staging's settings.** `add-app` already pointed `DOMAIN` and `APP_URL`
at `reads-admin.staging.sarvarlogs.uz`. The rest connect it to other apps,
and every one must point at staging, never production:

```bash
sudo nano /opt/apps/reads-admin-staging/.env
```

```
APP_ENV=staging
MAIL_MAILER=log
SEO_SITE_URL=https://reads.staging.sarvarlogs.uz
SEO_REVALIDATE_URL=http://reads-staging-app:3000/api/revalidate
SEO_REVALIDATE_SECRET=<new: openssl rand -hex 32>
CATALOGUE_SYNC_TOKEN=
```

`CATALOGUE_SYNC_TOKEN` empty switches the bot sync off on staging (503): the
real bot keeps syncing into production only.

**2. Staging's database: a copy of production's.** Once, before staging's
first deploy:

```bash
sudo docker volume create \
  --label com.docker.compose.project=reads-admin-staging \
  --label com.docker.compose.volume=app_storage \
  reads-admin-staging_app_storage
sudo docker run --rm \
  -v reads-admin_app_storage:/from:ro \
  -v reads-admin-staging_app_storage:/to \
  alpine cp -a /from/. /to/
```

Copy it again whenever you want fresh data on staging (stop
`reads-admin-staging-app` first). Production's volumes keep their names
after the move: compose names them after the folder, still `reads-admin`.

**3. Staging Reads uses staging admin.** Until now staging Reads read
production's catalogue with its inquiry form off. In
`sudo nano /opt/apps/reads-staging/.env`:

```
API_URL=http://reads-admin-staging-app:80/api
REVALIDATE_SECRET=<the same value as SEO_REVALIDATE_SECRET above>
```

and delete the `INQUIRIES_DISABLED=true` line: test inquiries now land in
staging admin's copy. Then re-run Reads' latest staging deploy in its
Actions tab, after this app's staging is up.

## Creating an admin account

The seeder only runs locally, so a freshly deployed server has no accounts at
all. Create the first admin over SSH:

```bash
docker exec -it reads-admin-app php artisan reads:create-admin
```

It prompts for email, name, and password (twice, hidden). Use `-it` so the
prompts work. To script it instead, pass everything up front — though note
the password then lands in your shell history:

```bash
docker exec reads-admin-app php artisan reads:create-admin \
  --name="Sarvar" --email="you@example.com" --password="..."
```

Re-running for an email that is already an admin is a safe no-op. If the
email belongs to an existing *translator* account, add `--promote` to turn it
into an admin.

Do not use `php artisan make:filament-user` here: it is not aware of the
`users.role` column, so it creates an account stuck on the default
`translator` role, which can sign in but only ever sees its own profile.

## Changing `.env` on the server

After editing `.env`, you must **recreate** the container, not restart it,
from the image it is already running:

```bash
cd /opt/apps/reads-admin
TAG=$(sudo docker inspect reads-admin-app --format '{{.Config.Image}}' | cut -d: -f2)
sudo IMAGE_TAG=$TAG docker compose -f docker-compose.prod.yml up -d --force-recreate --pull never app
```

Without `IMAGE_TAG`, compose asks GHCR for `:latest`, which deploys never
use; with an expired login that fails with `unauthorized`, and leaves the
old container running with the old `.env`. `--pull never` keeps it to the
image already on the server.

`docker compose restart` reuses the existing container, and a container's
environment is captured once when it is *created*. Restarting re-runs
`entrypoint.sh` (so config/route/view caches are rebuilt) but the process
still sees the old values, so `.env` edits appear to have no effect.

## SEO metadata and the revalidation webhook

The public site's `<title>`, meta description and share image for each
translator are produced **here**, not there: the API returns them already
resolved, so what an admin previews in the panel is the exact string Google
indexes. Blank fields are derived from the profile — name, professional
title, location, language pairs and the live book count — which means the
description updates itself when a book is added, with nobody editing it.

These fields are admin-only. Translators never see the section, and a write
to them from a non-admin account is refused server-side by
`App\Observers\TranslatorObserver`, not merely hidden in the UI.

Four variables in the server `.env` drive it:

```
SEO_SITE_NAME=Reads
SEO_SITE_URL=https://tarjima.kitoblarim.uz
SEO_REVALIDATE_URL=http://reads-app:3000/api/revalidate
SEO_REVALIDATE_SECRET=<the same value as REVALIDATE_SECRET in the Reads .env>
```

After any save to a translator, book, language pair or social link, this app
POSTs to `SEO_REVALIDATE_URL` so the site drops its cached copy of that
profile at once, instead of waiting out its 5-minute cache window. The call
goes over the internal Docker network by container name, so it never touches
the public internet and works before any certificate exists.

It is deliberately best-effort: if the site is down or the secret is wrong,
the save still succeeds and a warning goes to `storage/logs/laravel.log`
("Site revalidation was rejected" / "Site revalidation failed"). The site's
own cache window then picks the change up within five minutes. Leave
`SEO_REVALIDATE_URL` blank to switch the pings off entirely.

Verify after a deploy:

```bash
docker compose -f docker-compose.prod.yml exec app \
  php artisan tinker --execute="App\Models\Translator::first()->touch();"
tail -n 20 storage/logs/laravel.log     # silence here means it worked
```

## Catalogue sync with the Kitoblarim bot

The Telegram bot pushes the books its moderators approve to
`PUT /api/sync/editions/{isbn}` and pulls ours from `GET /api/sync/editions`.
One variable in the server `.env` guards both:

```
CATALOGUE_SYNC_TOKEN=<openssl rand -hex 32>
```

The bot sends it as `Authorization: Bearer <token>`, so put the same value in
the bot's own config. Leave it blank and the endpoints answer `503 Catalogue
sync is not configured` rather than letting anyone in. It is a write
credential for the catalogue: treat it like `SEO_REVALIDATE_SECRET`, and
rotate it by changing both sides and recreating the container (see "Changing
`.env` on the server"). The migration that adds the sync columns to
`editions` runs with the normal deploy. What the endpoints do is in
`docs/DATA-MODEL.md`.

The Kitob Marafoni bot (book marathons) uses the same endpoints, plus
`POST /api/sync/books` for books without an ISBN, with a token of its own:

```
CATALOGUE_MARATHON_TOKEN=<openssl rand -hex 32, a different value>
```

Blank shuts that bot out (401) without affecting the Kitoblarim bot; the
endpoints only answer 503 when both tokens are blank.

## Softly library sync (kutubxonam.uz)

Libraries on Softly (library.softly.uz, the system behind kutubxonam.uz)
get their book lists from it: `softly:sync` reads what changed every half
hour 08:00-21:00 Tashkent time, and `softly:sync --full` reads everything
at 02:30. The scheduler runs inside the app container (`scheduler` in
docker/prod/supervisord.conf).

Softly let us use its API on the condition that we keep the load low, so:

- It is off unless `SOFTLY_SYNC_ENABLED=true`. Set it in production only,
  never on staging.
- Softly answers for one library at a time (the `library` header). The
  sync reads the five kutubxonam.uz libraries in `SOFTLY_LIBRARIES`
  (default `1,16,22,25,26`), each with its own place to stop at.
- Requests go one at a time, 2 s apart, at most 400 a day
  (`SOFTLY_DAILY_CAP`). A quick run is usually 1 request per library (5),
  a full run about 66. A library read for the first time is read in full,
  even on a quick run (about 20 requests for Kitobqand).
- Any error stops every request for an hour.

Each run, and why one stopped, is in Laravel's log file (not
`docker logs`):

```bash
sudo docker exec reads-admin-app sh -c "grep -h 'Softly' storage/logs/laravel*.log | tail -5"
```

Run one by hand as `www-data`, so the log files stay writable by the app:

```bash
sudo docker exec -u www-data reads-admin-app php artisan softly:sync --full
```

To link a library, put its Softly location id in the library's admin form.
`softly:sync --full` prints every location id with its copy count. The
ids are Mehr 1, Kitobqand 16, Izlash 22, UzKutubxona.de 25, Idrok 26 (each
library's copies carry its library id). The next run fills the list; a linked library's list
comes only from Softly.

`supervisorctl` does not work in the container (no control socket). To see
that the scheduler is up: `sudo docker top reads-admin-app | grep schedule`.

Softly has no ISBNs, so titles are matched on title and author, and
only an exact match is linked on its own. After each complete nightly
run, a title with nothing like it in the catalogue becomes a book (its
author found under any spelling, or made, and one edition with no ISBN);
the same title in several libraries becomes one book. Titles with near
matches wait under **Catalogue → Softly matches** for an admin to link,
make a book, or skip. A book made by mistake (a magazine, a notebook) is
removed from the same page, which also stops it being made again.
`SOFTLY_CREATE_BOOKS=false` turns the nightly part off. To see what it
would make, or to make the books now instead of at night:

```bash
sudo docker exec -u www-data reads-admin-app php artisan softly:add-books --dry-run
sudo docker exec -u www-data reads-admin-app php artisan softly:add-books
```

Each nightly run logs `missed_by_quick`: copies that went out or came back
without their book's `updatedAt` moving, which quick runs cannot see. If
it is not 0 for a few days, set `SOFTLY_QUICK_MODE=full` and
`SOFTLY_DAILY_CAP=2000`: every quick run then reads all ~66 pages.

## Rollback

Every image is also tagged with its commit SHA (first 7 characters). The
easy way back: **Actions → Promote to production → Run workflow** with an
older commit's SHA in the box. By hand on the server:

```bash
cd /opt/apps/reads-admin
sudo IMAGE_TAG=<previous-commit-sha> docker compose -f docker-compose.prod.yml up -d
```

Note that `IMAGE_TAG` defaults to `latest`, which the server never pulls
during normal deploys (the workflow pulls by commit SHA). If a bare
`docker compose up` fails with `error from registry: denied`, either log in
per step 4 above, or point it at an image already on the box:

```bash
docker images ghcr.io/akbarov-labs/reads-admin   # see what's local
IMAGE_TAG=<one-of-those-tags> docker compose -f docker-compose.prod.yml up -d
```

## Backups and restore

The whole catalogue (editions, moderation work, readers' reviews) lives in
one SQLite file, `storage/app/database.sqlite` in the `app_storage` volume.
The `backup` service in `docker-compose.prod.yml` copies it every night at
**03:30 UTC**, and once more each time the container starts, so every deploy
also proves the backup works.

- Each copy is made with SQLite's `VACUUM INTO`, which reads the live
  database inside one read transaction. The copy is consistent even while
  the panel or the bot sync is writing. A plain `cp` of a live database is
  not, so never back it up that way.
- Before a copy is kept it must pass `PRAGMA integrity_check`. It is then
  gzipped to `reads-admin-<UTC time>.sqlite.gz` in the `db_backups` volume.
- Copies older than `BACKUP_RETENTION_DAYS` (default 14, set in the server
  `.env`) are deleted. Copies made on deploy are kept too, so on busy days
  there are more than one.
- `app_storage` is mounted read-only in the backup container, so the backup
  can never damage the live database.

These copies sit on the same server as the database. They protect against a
bad migration, a wrong bulk edit or a corrupted file, not against losing the
server. For that, the server's `offsite-backup` job copies them every night
at 04:30 UTC, encrypted, to Backblaze B2, together with the uploaded
portraits and avatars (`storage/app/public`):

| Off-site path | What | Kept |
|---|---|---|
| `dumps/reads-admin/` | the nightly `.sqlite.gz` copies | 30 days |
| `files/reads-admin/` | `storage/app/public`, mirrored | as long as the file exists |

It is set up once for the whole server: `docs/offsite-backups.md` in the
Reads repo (oʻzbekcha: `offsite-backups.uz.md`), including a restore drill
of this app's database from the Mac.

**Check that it runs**

```bash
cd /opt/apps/reads-admin
docker compose -f docker-compose.prod.yml logs --tail 20 backup
```

Every run logs one line: `[backup] ... ok: /backups/reads-admin-...sqlite.gz
(size, tables, editions)`, or `[backup] ... FAILED: <reason>`. To list the
copies or take one now:

```bash
docker exec reads-admin-backup ls -la /backups
docker exec reads-admin-backup /usr/local/bin/backup.sh
```

**Check the off-site copies**

```bash
sudo offsite-backup list dumps/reads-admin
```

**Restore**

1. Pick a copy and check it opens. The last two lines should be `ok` and
   the edition count. If the server was lost (or the copy is older than
   14 days), first fetch it from off-site into the backups volume:

   ```bash
   sudo offsite-backup list dumps/reads-admin
   sudo offsite-backup fetch dumps/reads-admin/reads-admin-<UTC time>.sqlite.gz "$(sudo docker volume inspect -f '{{.Mountpoint}}' reads-admin_db_backups)"
   ```

   Then:

   ```bash
   cd /opt/apps/reads-admin
   docker exec reads-admin-backup ls -t /backups
   F=reads-admin-<UTC time>.sqlite.gz
   docker exec reads-admin-backup sh -c "gunzip -c /backups/$F > /tmp/check.sqlite && sqlite3 /tmp/check.sqlite 'PRAGMA integrity_check; SELECT count(*) FROM editions;'"
   ```

2. Stop the app, so nothing writes while the file is replaced:

   ```bash
   docker compose -f docker-compose.prod.yml stop app
   ```

3. Swap the file. This deletes nothing. The current database, plus any
   `-journal`/`-wal`/`-shm` file next to it, moves into a
   `before-restore-<time>` folder, so the restore can be undone. A leftover
   journal must not stay beside the restored file, because SQLite would try
   to replay it onto the restored database.

   ```bash
   docker run --rm --volumes-from reads-admin-app --volumes-from reads-admin-backup alpine:3.20 sh -c "
     set -e
     cd /var/www/html/storage/app
     aside=before-restore-\$(date -u +%Y%m%dT%H%M%SZ)
     mkdir \$aside
     for f in database.sqlite database.sqlite-journal database.sqlite-wal database.sqlite-shm; do
       [ ! -e \$f ] || mv \$f \$aside/
     done
     gunzip -c /backups/$F > database.sqlite
     ls -la . \$aside"
   ```

4. Start the app again. `entrypoint.sh` gives `storage/` back to `www-data`
   and runs any migrations newer than the backup:

   ```bash
   docker compose -f docker-compose.prod.yml start app
   docker compose -f docker-compose.prod.yml logs --tail 30 app
   ```

On a new server, also bring back the uploaded portraits and avatars:

```bash
sudo offsite-backup fetch files/reads-admin "$(sudo docker volume inspect -f '{{.Mountpoint}}' reads-admin_app_storage)/app/public"
sudo docker compose -f docker-compose.prod.yml restart app
```

(The restart lets `entrypoint.sh` give the fetched files to `www-data`.)

Then open the panel and check a few recent edits. Everything written after
the backup's timestamp is gone: panel edits, reader reviews, and editions
that the Kitoblarim bot synced in that window. Once you're sure the restore
is right, delete the `before-restore-*` folder from `storage/app`.

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
  the image is replaced wholesale each time. The second volume,
  `db_backups`, holds the nightly copies of the database (see "Backups and
  restore").
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
