<?php
declare(strict_types=1);

/** Test-only issuer for invalid-token cases; never used by application code. */
function audit_forge_jwt(array $payload, string $key, string $alg = 'HS256'): string
{
    if (!in_array($alg, ['HS256', 'HS512', 'none'], true)) {
        throw new InvalidArgumentException('unsupported test signing algorithm');
    }
    $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $header = $encode(json_encode(['typ' => 'JWT', 'alg' => $alg], JSON_THROW_ON_ERROR));
    $body = $encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $input = $header . '.' . $body;
    $signature = $alg === 'none' ? '' : $encode(hash_hmac($alg === 'HS512' ? 'sha512' : 'sha256', $input, $key, true));
    return $input . '.' . $signature;
}
