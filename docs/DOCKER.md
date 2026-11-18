# Docker deployment (development / controlled pilot)

## What this package provides

- PHP 8.2 with Apache, PDO MySQL and MySQLi extensions.
- MariaDB 10.11 with a persistent Docker volume.
- Africa/Nairobi timezone.
- Environment-based database credentials and application base URL.
- Health checks and restart policies.

**Important database status:** the repository's database documentation says the SQL currently available is an extension set, not a verified complete fresh-install base schema. Docker does not import migrations automatically and does not create a production-ready Dibonke database. You must import your existing, compatible Dibonke database backup into the MariaDB service, or prepare and validate the canonical base schema first. Do not point this compose stack at the live database.

## Requirements

- Docker Desktop (Windows/macOS) or Docker Engine + Docker Compose plugin.
- Git.
- A database backup for the Dibonke development database.

## Start

1. Clone the repository and checkout `feature/dibonke-product-catalog` (or the approved release branch).
2. Copy `.env.example` to `.env`.
3. Set two different strong passwords for `DB_PASSWORD` and `DB_ROOT_PASSWORD`.
4. Build and start:

   ```bash
   docker compose up -d --build
   docker compose ps
   ```

5. Open `http://localhost:8080`.

The app uses `DB_HOST=db` inside the Docker network. Do not set it to `localhost` from the web container.

## Import an existing development database

Place a SQL dump outside the public web root, then import it into the database container. This command reads the database name from the container environment and prompts for the root password:

```bash
docker compose exec -T db sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < ./private/dibonke_bookshop_db.sql
```

Keep the dump private and delete it from the project directory after import. Never commit a database dump or place it under a publicly served uploads directory.

Apply the project's SQL migrations only in their documented dependency order, after taking a backup. The exact migration set depends on which extensions are already present in the imported database; do not blindly re-import non-idempotent migrations.

## Configure the application

- `APP_BASE_URL=` uses the root URL. If hosted below a path, set the matching path, e.g. `/dibonke_bookshop`.
- Database settings are read from `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`.
- In Docker, Compose supplies `DB_HOST=db`.
- For local XAMPP, leave the environment variables unset to use the local defaults in `config/db.php`.

## Backups

Create a database backup:

```bash
docker compose exec -T db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > dibonke-backup.sql
```

Restore only after taking a second backup of the current database:

```bash
docker compose exec -T db sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < dibonke-backup.sql
```

The database volume is persistent. `docker compose down` does not delete it. **Do not use `docker compose down -v` unless you intentionally want to permanently delete the database volume.**

## Production hardening still required

Before internet-facing deployment:

- Replace development passwords and store secrets in a managed secret store.
- Configure HTTPS at a reverse proxy/load balancer and set secure cookie/session options.
- Restrict database network access; never publish port 3306 publicly.
- Configure a proper domain and public HTTPS callback URLs for Safaricom Daraja.
- Review file uploads, session handling, CSRF, authorization and audit coverage.
- Add scheduled off-host encrypted backups and test restore procedures.
- Configure SMTP and monitoring/log retention.
- Complete the canonical base schema and test all migrations/workflows on a clean database.
- Run security review and load/restore tests.

This compose file is a development/controlled-pilot starting point, not a declaration of production readiness.
