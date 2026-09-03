# P12 — Production Readiness

Status: IN PROGRESS until repository gates and real server rehearsal evidence both pass.

## Security and threat model

Public storefront requests terminate at nginx and reach the loopback frontend service. Public API traffic reaches Laravel only through nginx and PHP-FPM with the document root fixed to `public/`. Admin access remains authenticated and RBAC-gated. Provider callbacks remain signed/verified at the backend boundary. The browser is never authoritative for price, stock, payment, shipping, loyalty balance or analytics commerce outcomes.

Production must run with `APP_ENV=production`, `APP_DEBUG=false`, HTTPS application/public URLs, secure HttpOnly SameSite session cookies, durable queue/failed-job storage and a non-ephemeral cache store. `sole:production:check` reports only boolean invariants and never emits credentials or connection strings. Environment access stays inside Laravel config files; the permanent audit rejects `env()` usage elsewhere.

Severity-1 and severity-2 readiness findings are release blockers. They may be fixed or the candidate rejected; they are not waived.

## Secret inventory and rotation

Secret classes are: Laravel `APP_KEY`, database credentials, Redis authentication when configured, Google OAuth secret, Kavenegar key/template controls, payment merchant credentials, shipping webhook secret, and any future provider credentials. Values live only in root/service-owned shared environment or client-default files with mode 0400/0600. Git, CI artifacts, release ledgers, command output and server evidence may record presence/status only.

Rotation order is provider/database credential -> shared secret file -> config cache rebuild -> service reload/restart -> secret-safe health verification -> old credential revocation. `APP_KEY` is not rotated casually because encrypted application data/cookies may depend on it; any rotation requires an explicit data compatibility plan.

## Queue and scheduler

The accepted database/Redis queue contract uses `retry_after=90s` by default and the systemd worker uses `--timeout=60`, so a stuck worker is terminated before the reserved job becomes visible for retry. The worker has three tries, bounded backoff, a one-hour max lifetime, graceful SIGTERM and systemd restart supervision. Failed jobs remain in the database UUID store for inspection/retry.

The Laravel scheduler is a systemd oneshot launched by a one-minute persistent timer. Existing scheduled commands retain `withoutOverlapping`; only one timer unit is installed per host. Deployment restarts the long-lived queue worker so new code is loaded. Scheduler invocations are short-lived and resolve `current` on each tick.

## Backup and restore

`scripts/production/mysql-backup.sh` uses MySQL logical backup with `--single-transaction`, `--quick`, routines/events/triggers, gzip compression, mode 0600 output and a SHA-256 sidecar. Credentials are read from a protected MySQL client defaults file and are never echoed. Retention defaults to seven days, but pruning is fail-closed unless `SOLE_BACKUP_PRUNE=YES` is explicitly supplied; an off-host encrypted copy is required before P14.

A backup is not accepted as recoverable until `mysql-restore-drill.sh` verifies the checksum, restores into a database whose name is restricted to `sole_restore_*`, proves migration history and critical tables, then drops the disposable database. It never restores over the production schema.

## Immutable backend release

Backend layout is `/var/www/sole-backend/{releases,current,shared}`. Each candidate is fetched by full SHA into a new release directory. `.env`, `storage`, and `bootstrap/cache` are linked from `shared`; release source is not edited in place. Locked production dependencies are installed, Laravel optimization runs, the production invariant command must pass and migration status is read before a candidate is considered prepared.

Activation is intentionally guarded for P13/P14 with `SOLE_ACTIVATION_APPROVAL=P13_OR_P14_APPROVED`. Database migrations additionally require `SOLE_MIGRATION_APPROVAL=BACKWARD_COMPATIBLE_REVIEWED`; automatic database rollback is forbidden. Code rollback is a separate explicit operation requiring `SOLE_ROLLBACK_APPROVAL=ROLLBACK_APPROVED`.

## Migration safety

P12 itself introduces no database migration. Future promotion may run only reviewed backward-compatible expand migrations before/with a code switch. Destructive contract migrations, column reuse, type narrowing and rollback that could discard post-deploy writes require a later forward cleanup release and are not part of an automatic rollback.

## Capacity and performance

Frontend F12 limits remain immutable: homepage <= 610000 bytes / <= 190000 gzip, largest non-3D JS <= 650000 bytes, global CSS <= 125000 bytes / <= 22000 gzip, and the model viewer remains isolated.

Server readiness records CPU count/load, memory/swap, filesystem capacity/inodes, open-file limit, process/task limits, Node/PHP-FPM worker memory, MySQL connection capacity and Redis memory policy without changing them automatically. Alert thresholds are reviewed from observed staging load in P13; P12 does not fabricate capacity from synthetic guesses.

## Alert ownership and incident policy

Severity 1: public checkout/payment integrity, data loss/corruption, sustained total outage or security compromise. Owner: release operator immediately; action: stop promotion, preserve evidence, rollback code when safe, reconcile data/provider truth.

Severity 2: elevated server 5xx, queue backlog/failed jobs, database/Redis exhaustion, degraded login/catalog/checkout or backup failure. Owner: application operator; action: investigate correlation/RED evidence, stop further releases and use the relevant runbook.

Severity 3: bounded non-critical degradation, individual RUM regressions or warning-level capacity drift. Owner: engineering backlog with trend review.

Evidence sources are `journalctl` for systemd services, nginx error/access telemetry, Laravel structured telemetry/error tables, queue failed-job state, MySQL/Redis status, backup checksums and immutable release ledger. Alerts must name an owner and a concrete response; P12 does not activate an external paging vendor.

## Official references

- Laravel 13 Deployment: https://laravel.com/docs/13.x/deployment
- Laravel 13 Queues: https://laravel.com/docs/13.x/queues
- MySQL 8.4 Backup and Recovery: https://dev.mysql.com/doc/refman/8.4/en/backup-and-recovery.html
- MySQL 8.4 mysqldump: https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html
- nginx proxy module: https://nginx.org/en/docs/http/ngx_http_proxy_module.html
- systemd service sandboxing: https://www.freedesktop.org/software/systemd/man/latest/systemd.exec.html
- systemd security analysis: https://www.freedesktop.org/software/systemd/man/latest/systemd-analyze.html
- OWASP ASVS 5.0: https://owasp.org/www-project-application-security-verification-standard/
