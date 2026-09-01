# P06 Cart, Checkout and Orders

P06 makes price, stock, shipping eligibility, totals, reservation and order state backend-authoritative. Guest carts use an opaque UUID capability held by the same-origin frontend in an HttpOnly cookie; authenticated checkout adopts the cart and requires a customer-owned address.

## Operator contract

Before checkout can accept requests, create the `checkout_policy` business setting with:

```json
{
  "allowed_country_codes": ["IR"],
  "shipping_minor": 100000,
  "free_shipping_threshold_minor": 5000000,
  "reservation_minutes": 15
}
```

All amounts are integer minor units in the variant currency. Missing or invalid policy fails closed with HTTP 503. P06 supports no coupon or client-provided amount. Payment is not activated; new orders stop at `awaiting_payment` until P07.

Run `php artisan sole:orders:expire` through the scheduler every minute. It locks each order/reservation/balance, releases expired reserved stock and appends an `expired` event. `order_events` are append-only. Valid state transitions are enforced by `OrderStateService`.

Checkout requires a UUID `Idempotency-Key`. Reusing it with the same user/cart/address returns the original order; reuse with a different fingerprint fails. The database unique constraint remains the final replay boundary.

## Security and privacy

- Cart tokens are unguessable capabilities and never stored in browser-readable storage.
- Customer order endpoints are Sanctum-authenticated and always scoped by `user_id`.
- Shipping snapshots contain only the address needed to fulfil the order.
- Inventory uses transaction-scoped `FOR UPDATE` locking and deterministic location order.
- No payment credential, provider payload or production data is introduced in P06.

## Verification

- `php artisan test --compact tests/Feature/CartCheckoutOrdersTest.php`
- `vendor/bin/pint --dirty --format agent`
- complete SQLite/MySQL quality workflow, migration rollback and production config cache

API contract: `docs/openapi/sole-commerce-v1.yaml`.
