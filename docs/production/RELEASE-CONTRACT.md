# Production release contract

No production release may start without an approved release record and a green staging gate. P12 prepares and rehearses this contract but does not perform the P14 public production release.

## Separation of environments

| Environment | Purpose | Data rule |
| --- | --- | --- |
| development | Local implementation | Synthetic data permitted |
| preview/staging | Production-like QA | Sanitized/synthetic data only |
| production | Customer traffic | Prototype, mock, and test modes forbidden |

## Immutable layout

```text
/var/www/sole-backend/
├── releases/
│   └── <40-char-git-sha>/
├── current -> releases/<active-sha>
└── shared/
    ├── .env
    ├── storage/
    └── bootstrap-cache/
```

Never edit `current` in place. Build a new release directory from an exact Git SHA, link approved shared state, run validation, and atomically switch the `current` symlink. `storage` and `bootstrap/cache` are the only application writable paths and resolve into `shared`.

## Mandatory release record

```text
CURRENT_SHA=
NEW_SHA=
RELEASE_PATH=
ROLLBACK_TARGET=
HEALTH_CHECK_RESULT=
```

No credential value or `.env` content belongs in a release record.

## Candidate preparation

`scripts/production/prepare-release.sh` fetches one exact full SHA into a never-before-used release directory, links shared state, installs locked production dependencies, runs Laravel optimization, runs the secret-safe readiness command and reads migration status. Preparation does not alter `current` and does not run database migrations.

## Promotion gate

1. Record candidate SHA and rollback target.
2. Prove a current backup checksum and disposable restore for the target environment.
3. Confirm any pending migration is backward-compatible with both old and new application code.
4. Require the explicit P13/P14 activation guard.
5. If specifically approved, run reviewed forward-only migrations with Laravel's isolated migration lock.
6. Atomically switch `current`.
7. Reload PHP-FPM, restart the long-lived queue worker and verify the HTTPS health endpoint.
8. If application health fails, atomically restore the prior code symlink and services.
9. Database rollback is never automatic; schema/data recovery follows its separately reviewed compatibility or restore plan.

## Queue and scheduler

The queue worker uses a 60-second timeout while the durable queue retry-after is at least 90 seconds. This ordering is a release invariant. The worker is supervised by systemd, receives SIGTERM, has bounded tries/backoff and is restarted during deployment so long-lived code is refreshed. The scheduler is a short-lived oneshot launched every minute by a persistent timer.

## Rollback

`scripts/production/rollback-release.sh` requires an explicit approval token and an exact existing release SHA. It rolls back code only, restarts/reloads application services and requires health to pass. It never invokes `migrate:rollback`.

## P12 server rehearsal

P12 may prepare inactive candidates and perform disposable restore drills after a read-only server inventory. It may not switch public production traffic. P13 proves this release mechanism on staging; P14 alone authorizes the public production switch.
