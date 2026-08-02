## Context

`xaboy/form-builder` 2.0.17 declares `FormBuilder\Driver\CustomComponent` as both `JsonSerializable` and `ArrayAccess`, but its five interface methods have no return types. PHP 8.2 emits interface compatibility deprecations while loading the class, and the application error handler turns the first diagnostic into a terminating exception. This blocks `SystemConfigServices::getConfigForm()` and can affect other admin workflows that generate dynamic forms.

The project already excludes incompatible packaged classes from Composer's optimized classmap and maps project-owned compatibility replacements from `crmeb/compat`. The production image runs `composer dump-autoload --classmap-authoritative`, so a classmap replacement remains deterministic after a clean dependency install.

## Goals / Non-Goals

**Goals:**

- Load `CustomComponent` on PHP 8.2 under `E_ALL` without diagnostics.
- Preserve the exact 2.0.17 component behavior and JSON rule structure.
- Exercise all five repaired interface methods in an automated image-level probe.
- Restore authenticated system-configuration access without editing `vendor/` or changing the lock file.

**Non-Goals:**

- Upgrade FormBuilder or refactor application form construction.
- Suppress PHP deprecations globally or add `ReturnTypeWillChange` as a temporary waiver.
- Save or mutate business configuration during browser verification.
- Address unrelated queue or logistics findings.

## Decisions

### Use the existing classmap compatibility pattern

Add the packaged `CustomComponent.php` to `exclude-from-classmap` and add a project-owned compatibility file to `classmap`. The compatibility class retains the locked 2.0.17 source and adds only:

- `jsonSerialize(): mixed`
- `offsetExists($offset): bool`
- `offsetGet($offset): mixed`
- `offsetSet($offset, $value): void`
- `offsetUnset($offset): void`

This matches the upstream 2.1.1 signature repair while avoiding its unrelated package changes.

### Verify behavior, not only source text

Extend the existing PHP 8.2 integration probe with a `form-builder` case. It shall instantiate a real Element UI component, validate the loaded class path, compare `build()`, `jsonSerialize()`, `json_encode()`, and `toJson()` results, and exercise set/get/exists/unset operations. The probe's strict error handler makes the current packaged class fail before assertions, proving the regression test detects the production error.

### Keep authenticated verification read-only

After the image-level probe passes, authenticate to the local admin, open the system-configuration page and representative dynamic-form pages, and record API failures. Do not submit forms. Restore the database and Redis snapshot afterward because login and admin asset loading update runtime state.

## Risks / Trade-offs

- A copied compatibility class must be reviewed when the dependency is intentionally upgraded. The classmap path and focused probe make that ownership explicit.
- A package upgrade would reduce local compatibility code but carries a broader behavior delta. That option is deferred until a dedicated dependency-upgrade change can test all form builders.
- A Composer patch plugin could apply a tiny diff but would introduce another build dependency and patch lifecycle; it is unnecessary because the repository already has a verified override pattern.

## Rollback

Remove the compatibility class and its two Composer autoload entries, rebuild the prior image, and restore the acceptance database/Redis snapshot. No schema or business-data migration is involved.
