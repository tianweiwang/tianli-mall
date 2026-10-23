# Historical584 R2 design clarification

This clarification accompanies the immutable `full-a-published-current-normal-next-source-r2` package, PINS SHA-256 `18dc8e7366783771daedca66389f9746f041582bee92783c8d9a3b9d6dca484f`. No frozen R2 file or PINS is changed by this record.

R2's initial DESIGN.md was written before Root began editing the new Git handoff. Its final two paragraphs contain two stale statements: that Main/Val both stay584 and that both worktrees'194 live requirements must pass before sealing this Root. These statements do not describe R2's actual final source-authoring qualification and must not be used as evidence for the current Main checkout.

The final implemented and frozen scope is **HISTORICAL_584_SOURCE_ONLY_CURRENT_MAIN_NOT_ASSERTED**. R2 reused the immutable R1 AUTHOR-INPUTS closed Main584 and Val584 windows for all194 requirement rows, performed194 actual read-only `git show 584:<path>` commands with captured native PID/wait/complete streams, and freshly read Val's194 actual584 requirements. It never adopted Root's new dirty Main file bytes. Its `AUTHOR-CHECKS.json`, `historical-source-qualification.json`, added `validation/DESIGN-TASKS.md` note and PINS all state this boundary.

All actual M/S runtime clean-HEAD, exact parent, Git delta, full tracked namespace, complete source byte and live dual-worktree guards remain unchanged. R2 is not runtime-ready on the changed Main checkout, and no M/S or business process ran for R2. A later Git handoff commit does not retroactively change the historical584 source label.

Profile Python function bodies are wholly unchanged; only their independent active constants/hash edges changed. Root M/S source_authority is augmented by the explicit active-contract consistency and immutable predecessor-M673-failure checks. Every non-authority Python helper function and the complete S business suffix remain unchanged.

M RESULT remains31 fields. The predecessor failed M is a Source-only named authority consumed during qualification, with all673 actual flat native receipts and the actual nested outer receipt; it is not a sixth business retirement, a successful freeze, a new Run or an additional M RESULT field.

This records the final agreed qualification boundary while preserving the earlier frozen design text as historical authoring evidence.
