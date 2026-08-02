## 1. Specification and regression coverage

- [x] 1.1 Validate the OpenSpec proposal, design, specification delta, and task list.
- [x] 1.2 Add a focused `form-builder` PHP 8.2 integration probe case.
- [x] 1.3 Run the new case against the current image and confirm it fails on the interface return-type diagnostic.

## 2. Minimal compatibility implementation

- [x] 2.1 Add the project-owned FormBuilder `CustomComponent` compatibility class with only the five upstream return types.
- [x] 2.2 Add Composer classmap exclusion/inclusion entries without changing `composer.lock`.
- [x] 2.3 Rebuild the optimized PHP image and confirm the focused probe passes with unchanged serialized rules.

## 3. Authenticated regression verification

- [x] 3.1 Log in with a temporary local credential and verify the system-configuration endpoint returns business status 200.
- [x] 3.2 Open representative dynamic-form pages without submitting data and verify there are no FormBuilder diagnostics.
- [x] 3.3 Restore the database, Redis cache, administrator credential, and transient runtime marker state.

## 4. Acceptance and closeout

- [x] 4.1 Run the relevant PHP 8.2 integration verification and confirm all cases pass.
- [x] 4.2 Run the optimized runtime verification against the explicit pre-upgrade snapshot and confirm all checks pass.
- [x] 4.3 Confirm `vendor/` and `composer.lock` are unchanged and review the final diff for scope.
- [x] 4.4 Re-run `openspec validate fix-form-builder-php82-compat` after implementation and mark completed tasks.
