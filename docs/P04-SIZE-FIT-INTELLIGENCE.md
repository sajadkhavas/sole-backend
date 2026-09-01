# P04 — Size & Fit Intelligence

## Contract

- `size_guides` are one-per-product and remain invisible until `status=published`.
- Every published response names its source. Verification time controls confidence; it does not create certainty.
- Recommendation input is limited to 180–340 mm, processed in memory and never stored.
- `fit_events` contain only an allow-listed event, product, confidence bucket, recommended size and optional idempotency UUID.
- Customer feedback requires an authenticated owner and a variant belonging to the selected product.
- P07 may later add verified size-return evidence. P04 does not fabricate return or purchase verification.

## Gates

- Laravel Pint and PHP syntax
- migration/rollback on MySQL
- full SQLite and MySQL regression
- idempotency, ownership, privacy and uncertainty feature tests
- production configuration cache

## Official references

- https://laravel.com/docs/13.x/validation
- https://laravel.com/docs/13.x/rate-limiting
- https://laravel.com/docs/13.x/http-tests
- https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/examples/dialog/
- https://www.edpb.europa.eu/sme/be-compliant/secure-personal-data_en
