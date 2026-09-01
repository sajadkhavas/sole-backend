# P05 — Discovery & PDP Conversion

P05 makes storefront discovery and PDP decision support backend-authoritative without crossing into P06 checkout/order authority, P07 fulfillment/policy workflow, P08 verified review publication, or P09 notification delivery.

## Contract

- `GET /api/v1/catalog/products` owns search, facets, availability, price/size filters, merchandising priority, sort and pagination.
- Published products with active variants remain discoverable when sold out so availability can be stated honestly and waitlist intent can be captured.
- No-result responses may expose a bounded spelling recovery suggestion; no result is silently fabricated.
- `GET /api/v1/catalog/products/{slug}/related` ranks related published inventory using explicit merchandising priority and recency.
- PDP resources expose per-variant stock quantity and fail-closed decision-support states for social proof, delivery and returns until later phases provide verified evidence.
- `POST /api/v1/catalog/products/{slug}/back-in-stock` accepts only unavailable variants owned by that published product, requires explicit `p05-v1` consent, encrypts the normalized email at rest, stores a deterministic hash only for idempotency, and does not send notifications. Delivery is P09.

## Merchandising truth

`products.merchandising_priority` is an explicit operator-controlled integer. It can influence ordering but must not be presented as scarcity, popularity or customer demand.

## Official references reviewed

- Laravel validation: https://laravel.com/docs/13.x/validation
- Laravel encrypted Eloquent casts: https://laravel.com/docs/12.x/eloquent-mutators#encrypted-casting
- Laravel encryption: https://laravel.com/docs/12.x/encryption
- TanStack Router search params: https://tanstack.com/router/latest/docs/framework/react/guide/search-params
- W3C WAI-ARIA APG patterns: https://www.w3.org/WAI/ARIA/apg/patterns/

## Rollback impact

Rollback drops only `back_in_stock_intents` and `products.merchandising_priority`. It does not mutate orders, payments, customer auth, inventory ledger balances, media assets, or P04 fit data.
