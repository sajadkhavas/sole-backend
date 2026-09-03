# P11 — Observability, RUM & CRO

Implementation branch: `phase/sole-p11-observability-rum-cro`

This phase adds first-party, provider-neutral observability and consent-aware analytics without production provider activation.

- W3C-compatible `traceparent` correlation and server-generated request IDs.
- Structured JSON telemetry with bounded route/method/status dimensions and no raw URL, query, body, identity or credential payloads.
- RED-style request metric buckets plus sanitized append-only error evidence.
- Versioned analytics taxonomy with authenticated explicit analytics consent and session-scoped pseudonymous IDs.
- Core Web Vitals ingestion contract for LCP, INP, CLS and TTFB; browser implementation is owned by the frontend P11 branch.
- Server-authoritative commerce funnel outcomes are emitted only from successful backend responses; clients cannot submit `cart_engaged`, `order_created` or `payment_paid`.
- Daily funnel snapshots count distinct consented sessions.
- CRO experiment definitions require hypothesis, metric, guardrails, sample plan, stable allocations and rollback before activation.
- Admin access is RBAC-gated; no external analytics/observability credentials or providers are configured.

No production deployment or data mutation is performed by this implementation commit.