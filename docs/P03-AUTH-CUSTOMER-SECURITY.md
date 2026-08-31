# SOLE P03 — Authentication & Customer Security (Backend)

## START_SHA
`36eca2810495591b44f1f86c975f4ff287374e81`

## Scope
- Laravel Sanctum first-party session boundary
- Google OAuth as the active customer sign-in path
- mandatory normalized Iranian mobile for account completeness
- retained OTP domain, disabled by default and feature-gated
- real Kavenegar verify/lookup adapter when explicitly enabled/configured
- customer profile/address ownership
- append-only consent history
- account export and controlled deletion lifecycle
- admin/customer identity separation

## Production safety
- Google and OTP are disabled by default in `.env.example`.
- Google auth fails closed in production without HTTPS + secure HttpOnly session configuration.
- Google access/refresh tokens are not persisted.
- OTP codes are stored only as HMAC digests and are single-use with TTL/attempt/resend/rate-limit controls.
- Preview/test providers are not wired into production bindings.
- Customer social login cannot attach to a privileged admin account.
- No server activation or real credential enrollment occurs in P03.

## Next
Frontend account/auth integration and final cross-repository closure are completed in the frontend P03 branch/tracking issue #36.
