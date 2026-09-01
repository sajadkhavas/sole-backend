# P07 — Payment, Shipping & Returns

## Status

Implementation branch for `P07 — Payment, Shipping & Returns`.

- START_SHA: `269616149acbd8977fd55c2bfde6fd65bffbe45a`
- Branch: `phase/sole-p07-payment-shipping-returns`
- Frontend tracking: `sajadkhavas/solemate-kickz#47`
- Production/server mutation: **none**
- Live provider credentials: **none**

## Payment truth

`PaymentGateway` is the only provider-facing payment boundary. `SOLE_PAYMENT_PROVIDER` defaults to `disabled`, so production cannot start a charge unless a later controlled environment explicitly selects and configures a provider.

The implemented ZarinPal adapter is based on current official ZarinPal API/SDK contracts. Initiation creates a durable payment attempt first, then sends only the documented request fields. The callback URL carries the public payment-attempt UUID so the storefront can submit callback state back to the backend for server-to-server verification.

A browser redirect/callback is never payment truth. An order becomes `paid` only after the backend verifies the provider response, confirms the authority, exact order amount/currency, reservation validity and current order state under database locks. The payment idempotency key and immutable request fingerprint prevent replay with changed input; a second successful callback is a no-op.

Ambiguous provider outcomes do not become paid. They create reconciliation evidence and remain pending/manual-review until provider truth can be established.

## Shipping truth

`ShippingProvider` is the only provider-facing shipping boundary. No undocumented carrier API is implemented. P07 provides a first-party `configured` provider whose eligible services are stored in the audited `BusinessSetting` policy `shipping_provider_policy`.

Shipping quote amount/currency/eligibility are server-owned. Checkout requires a live, unconsumed quote owned by the same authenticated customer, cart and address; the order snapshots the selected provider/service and quote price.

Fulfillment events enter through the signed `/api/v1/commerce/shipping/provider-events` boundary. The exact raw request body is authenticated with HMAC-SHA256 and a configured secret. `event_id` is idempotent per shipment and shipment state transitions are explicit.

Inventory remains reserved after payment. On the first valid `shipped` transition, the reservation is committed atomically by decrementing both `on_hand` and `reserved`; cancellation/expiry may only release still-active reservations. Dispatched orders cannot use the cancellation path and must use the return workflow.

## Returns and refunds

Return requests require customer ownership and confirmed delivery. Return states are controlled by a durable state machine and cannot be rewritten directly.

Refund request amount is never accepted from the browser. The backend derives the remaining refundable amount from the verified paid attempt minus all active/completed refund requests. UUID idempotency prevents duplicate reservations of refundable value.

P07 deliberately does **not** execute a live monetary refund because provider credentials/live financial activation are excluded from this phase. The durable refund request/state machine is ready for controlled provider dispatch after production provider enrollment. The ZarinPal adapter therefore reports refund dispatch as deferred rather than fabricating a successful refund.

## Failure handling

- provider disabled/missing configuration: fail closed;
- initiation or verification timeout: no paid order is created;
- callback authority mismatch: reject;
- amount/currency/order-state mismatch: reject;
- provider `already verified` without matching local paid evidence: reconciliation/manual review, never fabricated success;
- duplicate payment initiation/callback/shipment event/refund request: idempotent;
- shipping policy unavailable/no eligible service: fail closed;
- invalid shipping event signature: reject before state mutation;
- inventory inconsistency at dispatch: transaction fails and shipment does not advance.

## API contract

`docs/openapi/sole-commerce-v1.yaml` version `1.1.0` documents the P06/P07 commerce boundary.

## Permanent tests

`tests/Feature/PaymentShippingReturnsTest.php` covers:

- authoritative shipping quotes and checkout snapshot;
- payment initiation idempotency;
- server-to-server verification and duplicate callback protection;
- reconciliation fail-closed behavior;
- signed fulfillment events;
- inventory commit at dispatch;
- order progression through processing/fulfilled;
- delivered-order return request;
- server-derived refund amount and refund idempotency;
- disabled payment default and invalid shipping signature rejection.

The existing P06 cart/checkout/order suite is evolved to use authoritative shipping quotes while preserving its cart ownership, price/stock, checkout idempotency, reservation release and append-only order-state invariants.

## Official references

- ZarinPal official organization/SDK and payment API contract: `https://github.com/zarinpal`
- Laravel HTTP client timeout/retry behavior: `https://laravel.com/docs/13.x/http-client`
- Laravel database transactions/pessimistic locks: `https://laravel.com/docs/13.x/database`
- Laravel validation: `https://laravel.com/docs/13.x/validation`

## Rollback

Backend rollback target is P06 final main `269616149acbd8977fd55c2bfde6fd65bffbe45a`. P07 adds only commerce/payment/shipping/return schema and services. No production provider credential enrollment, live charge/refund, production deployment or production-data mutation occurs in this phase.
