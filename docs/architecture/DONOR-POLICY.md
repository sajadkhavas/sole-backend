# Donor repository policy

LBB and Winimi are read-only reference implementations, not SOLE foundations.

Any adapted pattern must be reviewed for Laravel 13, PHP 8.3+, Filament 5, SOLE domain terminology, authorization, tests, and deployment compatibility. The source repository, exact donor SHA, adapted files, reason, and verification evidence must be recorded in the relevant phase document or pull request.

Forbidden imports include credentials, `.env` files, production/customer data, compiled artifacts, historical migrations, prototypes, mocks, test fixtures presented as production data, LBB-specific deployment assumptions, and Winimi bakery/ToolMaster domain code.
