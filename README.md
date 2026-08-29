# SOLE Backend

Source of truth for SOLE catalog, pricing, inventory, administration, and storefront APIs.

## P01 status

- Phase: `P01 — Backend + Admin + Product Truth`
- Phase branch: `phase/sole-p01-backend-admin-product-truth`
- Bootstrap SHA on `main`: `c0a1426b4e5ec818cdedce75490e2bcc7b9689c6`
- Official skeleton: `laravel/laravel` `13.x` at `aa0cf127fc365a56ee016867144ddffabc2290ae`
- Runtime: PHP `^8.3` (CI: `8.5`)
- Framework: Laravel `^13.17` (locked by Composer)
- Admin: Filament `^5.0` (locked by Composer)
- Database: MySQL `8.4`

All application work happens on a phase branch and reaches `main` only through a reviewed pull request.

## Local setup

Requirements: PHP 8.3+, Composer 2, Node.js, and MySQL 8.4.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer qa
```

Never commit `.env`, credentials, production data, or donor-project data.

## Required quality gate

`composer qa` validates the Composer manifest, checks Pint formatting, and runs the test suite. GitHub Actions additionally runs migrations against MySQL 8.4 and verifies that production configuration can be cached with prototype and debug modes disabled.

## Production contract

- Health endpoint: `GET /up`
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_PROTOTYPE_MODE=false`
- immutable releases under `/var/www/sole-backend/releases/<release-id>`
- `current` is a symlink to the active release
- shared runtime state belongs under `/var/www/sole-backend/shared`

See [docs/production/RELEASE-CONTRACT.md](docs/production/RELEASE-CONTRACT.md) and [docs/phases/P01.md](docs/phases/P01.md).
