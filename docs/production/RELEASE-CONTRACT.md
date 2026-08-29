# Production release contract

No production release may start without an approved release record and a green staging gate.

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
│   └── <release-id>/
├── current -> releases/<active-release-id>
└── shared/
    ├── .env
    └── storage/
```

Never edit `current` in place. Build a new release directory from an exact Git SHA, link approved shared state, run validation, and atomically switch the `current` symlink.

## Mandatory release record

```text
CURRENT_SHA=
NEW_SHA=
RELEASE_PATH=
ROLLBACK_TARGET=
HEALTH_CHECK_RESULT=
```

## Promotion gate

1. Record the candidate SHA and rollback target.
2. Install from `composer.lock` with production flags.
3. Cache production configuration, routes, events, and views.
4. Run forward-only production migrations according to the migration plan.
5. Start the candidate and verify `GET /up` plus application smoke checks.
6. Promote staging evidence separately from production approval.
7. Atomically switch `current` and repeat health checks.
8. On failure, switch `current` to `ROLLBACK_TARGET`; database rollback requires its separately reviewed compatibility plan.

The service definition, fixed Node/PHP versions, process ownership, and monitoring checks are completed in the deployment phase before the first production promotion.
