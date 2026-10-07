## 1. Regional agent domain

- [x] 1.1 Add versioned regional-agent fixtures, store attribution, and deterministic reset data.
- [x] 1.2 Add pure applicable-series and 50/35/15 or 50/50 settlement calculations.
- [x] 1.3 Add agent list, detail, pending, and settled selectors derived from original sharing records.

## 2. Service settlement integration

- [x] 2.1 Save agent and split snapshots for eligible principal service settlements.
- [x] 2.2 Save zero-cash snapshots for eligible points settlements without creating cash sharing records.
- [x] 2.3 Preserve existing behavior for non-eligible series and make repeated settlement idempotent.

## 3. Sharing state

- [x] 3.1 Add failed and retry transitions while preserving the existing return flow.
- [x] 3.2 Derive agent pending and settled totals from sharing status without duplicate balances.
- [x] 3.3 Label personal commission as a separate group marketing expense.

## 4. Brand and store UI

- [x] 4.1 Add brand regional-agent routes, navigation, list, and detail pages.
- [x] 4.2 Add agent attribution and three-party snapshots to store, service settlement, order, and sharing views.
- [x] 4.3 Add agent pending entry to the brand dashboard and simulated brand-sharing notice.
- [x] 4.4 Keep mobile and desktop fields and actions readable without overflow.

## 5. Verification

- [x] 5.1 Add failing-first unit tests for formulas, applicability, snapshots, state transitions, reset, and idempotency.
- [x] 5.2 Add route and component tests for agent pages and bidirectional drilldowns.
- [x] 5.3 Add Playwright coverage for agent-store principal settlement, completion, direct-store settlement, points settlement, and separate personal commission.
- [x] 5.4 Run focused and full Vitest, production build, Playwright, OpenSpec validation, and diff checks.
- [x] 5.5 Run the defect learning loop and report remaining production-payment risks.
