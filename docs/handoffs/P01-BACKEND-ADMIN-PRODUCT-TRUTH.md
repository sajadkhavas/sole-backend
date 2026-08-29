# P01 — Backend, Admin and Product Truth

## Acceptance record

- Repository: `sajadkhavas/sole-backend`
- Phase branch: `phase/sole-p01-backend-admin-product-truth`
- Backend START_SHA: `c0a1426b4e5ec818cdedce75490e2bcc7b9689c6`
- Accepted implementation END_SHA: `e60df1050aa051703ca470b036815d970ff9648b`
- Pull request: `sajadkhavas/sole-backend#1`
- Exact implementation CI: Backend quality gate run `33247284200` — PASS
- Status: ACCEPTED_PENDING_MERGE
- Server required: no

## Completed execution parts

- [x] P01.1 — independent Laravel 13 / Filament 5 / MySQL baseline, pinned dependencies, environment and immutable-release contract, CI
- [x] P01.2 — deny-by-default administrator identity, explicit RBAC, model policies, audited grant/revoke workflow and append-only privileged mutation evidence
- [x] P01.3 — SOLE-owned MySQL migrations, constraints, indexes, foreign keys, factories, migrate/rollback/re-migrate verification
- [x] P01.4 — category, collection, product, variant/SKU, integer minor-unit pricing, versioned settings and transactional append-only inventory truth
- [x] P01.5 — policy-protected Filament operations, explicit publish/archive transitions, ledger-only inventory adjustment, no unsafe bulk/delete paths
- [x] P01.6 — database-backed readiness, versioned read-only storefront catalog API, OpenAPI contract and production demo-data prohibition
- [x] P01.7 — style, PHP syntax, SQLite regression, MySQL integration, real MySQL concurrency, route/boot, dependency audit and production-config gates

## Security and truth invariants

- Filament panel access requires an active user plus explicit `admin.access` permission.
- RBAC synchronization never grants a user access.
- Super-admin access is granted/revoked only by explicit operator commands and produces audit evidence.
- Catalog deletion is denied; publish/archive are explicit policy-authorized transitions.
- Price uses integer minor units plus a three-character currency code.
- Inventory balance cannot be edited directly. All stock changes go through `InventoryLedger`, a database transaction and `lockForUpdate()`.
- Inventory movements and audit records are append-only.
- Public catalog endpoints expose only published products with active variants that have positive available stock.
- `DatabaseSeeder` creates no production product or administrator truth.
- No donor data, donor Git history, `.env`, credentials, bakery/ToolMaster legacy or sandbox/mock truth was imported.

## QA evidence

Exact implementation SHA `e60df1050aa051703ca470b036815d970ff9648b` passed:

1. `composer validate --strict`
2. locked dependency install
3. Pint style gate
4. PHP syntax scan across app/config/database/routes/tests
5. MySQL 8.4 `migrate:fresh`, RBAC sync, rollback and re-migrate
6. full SQLite regression suite
7. full MySQL integration suite
8. real concurrent inventory mutation test with row locking
9. route and operator-command boot checks
10. `composer audit --locked --no-dev`
11. production configuration cache and route boot with debug/prototype disabled

During acceptance, CI exposed and the phase corrected a MySQL 64-character generated-index-name failure and an Eloquent runtime/default mismatch for setting versioning. No gate was weakened.

## Rollback impact

Before merge, rollback is branch-local and main remains `c0a1426b4e5ec818cdedce75490e2bcc7b9689c6`.
After merge but before any deployment, code rollback is the backend START_SHA. No production database or production server mutation is part of P01.

## Cross-repository closure

The frontend repository `sajadkhavas/solemate-kickz` owns the final cross-repository P01 registry, storefront fail-closed demo-data rule and backend merge SHA after PR #1 merges. P01 is not considered globally closed until that frontend record also passes exact-head CI and merges.
