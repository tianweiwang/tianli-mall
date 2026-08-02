## ADDED Requirements

### Requirement: Dynamic FormBuilder compatibility
The PHP 8.2 runtime SHALL load dynamic FormBuilder components from project-owned compatibility code without changing the locked package behavior or editing packaged files.

#### Scenario: Custom component satisfies PHP 8.2 interface contracts
- **WHEN** `FormBuilder\Driver\CustomComponent` is loaded under `E_ALL`
- **THEN** `JsonSerializable::jsonSerialize()` and all four `ArrayAccess` methods SHALL satisfy their PHP 8.2 return-type contracts without a diagnostic
- **THEN** Composer SHALL resolve the class from project-owned compatibility code rather than `vendor/`
- **THEN** `composer.lock` SHALL remain unchanged

#### Scenario: Dynamic form rules retain their contract
- **WHEN** a representative Element UI form component is built, serialized, encoded as JSON, and accessed through `ArrayAccess`
- **THEN** `build()`, `jsonSerialize()`, `json_encode()`, and `toJson()` SHALL preserve equivalent rule data
- **THEN** set, get, exists, and unset operations SHALL preserve the locked 2.0.17 behavior

#### Scenario: Authenticated configuration form loads
- **WHEN** an authenticated administrator opens the system-configuration form on the PHP 8.2 runtime
- **THEN** the configuration-form API SHALL return business status 200
- **THEN** representative dynamic-form pages SHALL render without a FormBuilder type, deprecation, or serialization error
- **THEN** verification SHALL NOT submit or change business configuration
