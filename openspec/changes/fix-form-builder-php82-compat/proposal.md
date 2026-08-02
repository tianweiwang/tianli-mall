## Why

The authenticated system-configuration page fails on PHP 8.2 because the locked `xaboy/form-builder` 2.0.17 `CustomComponent` implements `JsonSerializable` and `ArrayAccess` without the return types required by the current runtime. ThinkPHP converts the resulting deprecation into an exception, so dynamic form JSON cannot be returned to the admin client.

The upstream 2.1.1 release adds the five required return types, but it also contains broader form-builder changes and removals. The application already uses project-owned Composer classmap overrides for narrow PHP 8.2 compatibility repairs, so this change applies the upstream signature repair without changing dependency behavior.

## What Changes

- Add an isolated PHP 8.2 integration probe that loads the deployed `FormBuilder\Driver\CustomComponent`, exercises JSON serialization and all `ArrayAccess` operations, and rejects any diagnostic under `E_ALL`.
- Add a project-owned compatibility copy of `CustomComponent` with the five upstream PHP 8.2 return types while preserving the 2.0.17 implementation and serialized rule structure.
- Route Composer's authoritative classmap to the project-owned class and exclude the packaged implementation without modifying `vendor/` or `composer.lock`.
- Rebuild the optimized PHP image and verify the authenticated system-configuration endpoint plus representative dynamic-form pages.
- Restore the acceptance database and Redis state after authenticated testing, then run the existing runtime and snapshot acceptance gates.

## Capabilities

### Modified Capabilities

- `php-82-runtime`: Require dynamic FormBuilder components to load, serialize, and support array access on PHP 8.2 without diagnostics or contract drift.

## Impact

- Affected files: Composer autoload configuration, one project-owned FormBuilder compatibility class, the PHP 8.2 integration probe and verifier case list.
- Runtime behavior: dynamic admin forms can be serialized on PHP 8.2; form rules and frontend payloads remain unchanged.
- Dependency behavior: package versions and the Composer lock remain unchanged.
- Out of scope: upgrading `xaboy/form-builder`, changing form semantics, modifying `vendor/`, queue configuration, logistics configuration, and remote deployment.
