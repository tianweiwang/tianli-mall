## 1. Member domain state

- [x] 1.1 Add versioned per-customer membership, account, benefit, consumption, and subsidy fixtures.
- [x] 1.2 Add customer-scoped membership and asset selectors.
- [x] 1.3 Add idempotent membership purchase and stored-value recharge transitions.
- [x] 1.4 Migrate existing points rewards and redemption to the active customer's account.

## 2. Pricing and settlement

- [x] 2.1 Add membership product-pricing and payment-source calculations.
- [x] 2.2 Connect stored-value product payment to orders, asset ledgers, and subsidy progress.
- [x] 2.3 Add validated one-time store service settlement with principal or points.
- [x] 2.4 Create one subsidy plan when eligible principal consumption reaches 5100 yuan.

## 3. Weekly subsidy

- [x] 3.1 Add period-idempotent brand subsidy batch execution.
- [x] 3.2 Synchronize customer balances, plan progress, customer ledgers, and brand run records.

## 4. Consumer UI

- [x] 4.1 Add membership and asset routes with purchase, recharge, benefits, balances, and ledgers.
- [x] 4.2 Add membership and asset summaries to the active customer's profile.
- [x] 4.3 Show member service prices and store-settlement guidance.
- [x] 4.4 Add product member pricing and stored-value payment selection to checkout and payment.

## 5. Store and brand UI

- [x] 5.1 Add service settlement to verified booking details and verification results.
- [x] 5.2 Add the brand membership operations route and navigation entry.
- [x] 5.3 Add member, purchase/recharge, consumption, subsidy-plan, and subsidy-run views.
- [x] 5.4 Add customer membership and asset detail drilldowns.

## 6. Verification

- [x] 6.1 Add failing-first unit regressions for isolation, pricing, balance invariants, and idempotency.
- [x] 6.2 Add focused component tests for all three roles.
- [x] 6.3 Add the complete mobile-customer and desktop-management Playwright journey.
- [x] 6.4 Run full Vitest, build, Playwright, OpenSpec, and diff checks.
- [x] 6.5 Run the defect learning loop and report remaining risk.
