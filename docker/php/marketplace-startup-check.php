<?php
declare(strict_types=1);

/** Null is success. Including this file never ends the original healthcheck. */
function marketplaceSchemaStartupFailure(): ?string
{
    set_error_handler(static function (int $severity): bool {
        // Never retain the original warning text, file, line or previous exception.
        throw new RuntimeException('SCHEMA_UNAVAILABLE');
    });
    try {
        require_once '/var/www/vendor/autoload.php';
        $manifest = \crmeb\services\marketplace\SchemaVersionGate::manifest('/var/www/marketplace-release.json');
        [$pdo, $prefix] = \crmeb\services\marketplace\SchemaVersionRuntime::standalone('/var/www');
        \crmeb\services\marketplace\SchemaVersionGate::assertCompatible($pdo, $prefix, $manifest);
        return null;
    } catch (Throwable $error) {
        if ($error instanceof \crmeb\exceptions\MarketplaceSchemaException
            && in_array($error->getMessage(), ['SCHEMA_UNAVAILABLE', 'SCHEMA_INCOMPATIBLE', 'RELEASE_IDENTITY_INVALID'], true)) {
            return $error->getMessage();
        }
        return 'SCHEMA_UNAVAILABLE';
    } finally {
        restore_error_handler();
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $failure = marketplaceSchemaStartupFailure();
    if ($failure !== null) {
        fwrite(STDERR, $failure . PHP_EOL);
        exit(2);
    }
}
