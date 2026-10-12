<?php
declare(strict_types=1);

namespace crmeb\services\workerman\chat;

use crmeb\exceptions\AuthSessionException;

/** Only a successful server credential reader creates a connection identity. */
final readonly class ChatIdentity
{
    private string $credentialValue;

    public function __construct(
        public string $kind,
        public int $uid,
        string $credential,
        public ?int $expiresAt = null,
        public string $avatar = ''
    ) {
        if (!in_array($kind, ['api', 'kefu', 'guest'], true)
            || $credential === '' || str_contains($credential, "\0")
            || str_contains($avatar, "\0") || preg_match('//u', $avatar) !== 1) {
            throw new AuthSessionException();
        }
        if ($kind === 'guest') {
            if ($uid < -2147483647 || $uid >= 0 || $expiresAt === null || $expiresAt <= 0
                || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $credential) !== 1) {
                throw new AuthSessionException();
            }
        } elseif ($uid <= 0 || $uid > 4294967295 || $expiresAt !== null || $avatar !== '') {
            throw new AuthSessionException();
        }
        $this->credentialValue = $credential;
    }

    public function credential(): string
    {
        return $this->credentialValue;
    }

    public function __debugInfo(): array
    {
        return ['kind' => $this->kind, 'uid' => $this->uid,
            'expiresAt' => $this->expiresAt, 'avatar' => $this->avatar];
    }
}
