<?php
declare(strict_types=1);

namespace app\services\marketplace;

use app\dao\marketplace\EmployeeDao;
use app\dao\marketplace\MerchantDao;
use app\dao\marketplace\PasswordSetupDao;
use app\dao\marketplace\RoleDao;
use app\services\marketplace\context\MerchantContext;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\MerchantForbiddenException;
use crmeb\exceptions\MerchantNotFoundException;
use crmeb\services\auth\AccountChangeGuard;
use crmeb\services\auth\AuthSessionStore;
use crmeb\services\auth\AuthTokenV2;
use crmeb\services\auth\CredentialStamp;
use think\exception\HttpException;

/** Fixed-purpose employee authentication; no request identity is kept on this service. */
final class EmployeeAuthServices
{
    private const APP = 'merchantapi';

    public function __construct(
        private EmployeeDao $employees,
        private MerchantDao $merchants,
        private RoleDao $roles,
        private PasswordSetupDao $setups
    ) {}

    public function login(string $account, string $password): array
    {
        $account = self::normalizeAccount($account);
        self::validatePassword($password);
        try {
            $store = AuthSessionStore::fromConfig();
            $identity = $this->eligible($this->employees->findIdentityByNormalizedAccount($account));
            $digest = $identity['employee']['pwd'];
            if (!password_verify($password, $digest)) {
                throw new AuthSessionException();
            }
            // Credential login alone may initialize a server authorization version.
            $id = (string)$identity['employee']['id'];
            $version = $store->initAccount(self::APP, $id);
            $current = $this->eligible($this->employees->findIdentityByEmployeeId((int)$id));
            if (!hash_equals($digest, $current['employee']['pwd'])
                || $current['employee']['account'] !== $account) {
                throw new AuthSessionException();
            }
            $issued = (new AuthTokenV2($store))->issue(self::APP, $id, $version,
                CredentialStamp::for(self::APP, $id, $digest));
            return self::publicSession($issued);
        } catch (AuthException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function authenticate(string $token, ?string $requestId = null): MerchantContext
    {
        try {
            if ($requestId !== null && preg_match('/\A[a-f0-9]{32}\z/D', $requestId) !== 1) {
                throw new AuthUnavailableException();
            }
            $identity = null;
            $verified = AuthTokenV2::fromConfig()->verify(self::APP, $token,
                function (string $id) use (&$identity): string {
                    $identity = $this->eligible($this->employees->findIdentityByEmployeeId((int)$id));
                    return $identity['employee']['pwd'];
                });
            if ($identity === null || (string)$identity['employee']['id'] !== $verified['id']) {
                throw new AuthSessionException();
            }
            return new MerchantContext((int)$identity['employee']['mer_id'],
                (int)$identity['employee']['id'], $identity['role']['permissions'],
                $verified['version'], $requestId ?? bin2hex(random_bytes(16)));
        } catch (AuthException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function refresh(string $token): array
    {
        try {
            return self::publicSession(AuthTokenV2::fromConfig()->refresh(self::APP, $token,
                fn(string $id): string => $this->eligible(
                    $this->employees->findIdentityByEmployeeId((int)$id))['employee']['pwd']));
        } catch (AuthException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function logout(string $token): void
    {
        try {
            // A direct service call receives the same current-identity checks.
            $this->authenticate($token);
            AuthTokenV2::fromConfig()->revoke(self::APP, $token);
        } catch (AuthException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function setupPassword(string $token, string $password): void
    {
        self::validatePassword($password);
        $decoded = base64_decode(strtr($token, '-_', '+/') . '=', true);
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1
            || !is_string($decoded) || strlen($decoded) !== 32
            || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $token) {
            throw new HttpException(422, '参数格式错误');
        }
        try {
            $tokenHash = hash('sha256', $token, true);
            $setup = $this->validSetup($this->setups->findForSetup($tokenHash), time());
            $employeeId = (int)$setup['employee_id'];
            $merId = (int)$setup['mer_id'];
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new AuthUnavailableException();
            }
            AccountChangeGuard::run(self::APP, (string)$employeeId,
                function () use ($tokenHash, $employeeId, $merId, $passwordHash): void {
                    if (!$this->merchants->lockForPasswordSetup($merId)) {
                        throw new AuthSessionException();
                    }
                    $now = time();
                    $setup = $this->validSetup($this->setups->lockForSetup($tokenHash), $now);
                    $identity = $this->employees->lockIdentityForPasswordSetup($employeeId);
                    if ($identity === null || (int)$setup['employee_id'] !== $employeeId
                        || (int)$setup['mer_id'] !== $merId
                        || (int)$setup['mer_id'] !== (int)$identity['employee']['mer_id']
                        || (int)$identity['employee']['id'] !== $employeeId
                        || (int)$identity['employee']['status'] !== 1
                        || (int)$identity['employee']['is_del'] !== 0
                        || $identity['employee']['pwd'] !== null
                        || $identity['merchant']['closed_at'] !== null) {
                        throw new AuthSessionException();
                    }
                    if ($this->employees->initializePasswordForSetup($employeeId,
                        (int)$setup['mer_id'], $passwordHash, $now) !== 1
                        || $this->setups->consumeForSetup((int)$setup['id'],
                            (int)$setup['mer_id'], $employeeId, $now) !== 1) {
                        throw new AuthUnavailableException();
                    }
                });
        } catch (AuthException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function me(MerchantContext $ctx): array
    {
        $ctx->require('merchant.profile.read');
        try {
            $current = $this->eligible($this->employees->findIdentityByEmployeeId($ctx->employeeId));
            if ((int)$current['employee']['mer_id'] !== $ctx->merId) {
                throw new MerchantNotFoundException();
            }
            if (!in_array('merchant.profile.read', $current['role']['permissions'], true)) {
                throw new MerchantForbiddenException();
            }
            $employee = $this->employees->readSelf($ctx);
            $role = $this->roles->findForContext($ctx, (int)$current['employee']['role_id']);
            $merchant = $this->merchants->findForContext($ctx);
            if ($employee === null || $role === null || $merchant === null) {
                throw new MerchantNotFoundException();
            }
            $role['permissions'] = MerchantPermissions::validate($role['permissions']);
            return ['employee' => $employee, 'role' => $role, 'merchant' => $merchant];
        } catch (AuthException|MerchantForbiddenException|MerchantNotFoundException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public static function normalizeAccount(string $account): string
    {
        if (preg_match('//u', $account) !== 1) {
            throw new HttpException(422, '参数格式错误');
        }
        $account = strtr(trim($account), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        if ($account === ''
            || mb_strlen($account, 'UTF-8') > 191 || str_contains($account, "\0")) {
            throw new HttpException(422, '参数格式错误');
        }
        return $account;
    }

    private static function validatePassword(string $password): void
    {
        if (strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new HttpException(422, '密码长度应为8至72字节');
        }
    }

    /** Recheck the current actor; protected self-writes must not reauthenticate the old token. */
    public function authorizeAction(MerchantContext $ctx, string $permission, bool $forWrite = false): void
    {
        $ctx->require($permission);
        try {
            if ($forWrite && !$this->merchants->lockForManagement($ctx)) {
                throw new AuthSessionException();
            }
            $identity = $this->eligible($this->employees->findIdentityByEmployeeId($ctx->employeeId));
            if ((int)$identity['employee']['mer_id'] !== $ctx->merId) {
                throw new AuthSessionException();
            }
            if (!in_array($permission, $identity['role']['permissions'], true)) {
                throw new MerchantForbiddenException();
            }
        } catch (AuthException|MerchantForbiddenException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    private function eligible(?array $identity): array
    {
        if ($identity === null || (int)$identity['employee']['id'] <= 0
            || (int)$identity['employee']['mer_id'] <= 0
            || (int)$identity['employee']['role_id'] !== (int)$identity['role']['id']
            || (int)$identity['employee']['mer_id'] !== (int)$identity['role']['mer_id']
            || (int)$identity['employee']['mer_id'] !== (int)$identity['merchant']['mer_id']
            || (int)$identity['employee']['status'] !== 1 || (int)$identity['employee']['is_del'] !== 0
            || (int)$identity['merchant']['review_status'] !== 1
            || (int)$identity['merchant']['security_locked'] !== 0
            || $identity['merchant']['closed_at'] !== null
            || !is_string($identity['employee']['pwd']) || $identity['employee']['pwd'] === '') {
            throw new AuthSessionException();
        }
        $identity['role']['permissions'] = MerchantPermissions::validate($identity['role']['permissions']);
        return $identity;
    }

    private function validSetup(?array $setup, int $now): array
    {
        if ($setup === null || (int)$setup['id'] <= 0 || (int)$setup['mer_id'] <= 0
            || (int)$setup['employee_id'] <= 0 || $setup['consumed_at'] !== null
            || (int)$setup['add_time'] <= 0 || (int)$setup['add_time'] > $now
            || (int)$setup['expires_at'] !== (int)$setup['add_time'] + 86400
            || (int)$setup['expires_at'] <= $now) {
            throw new AuthSessionException();
        }
        return $setup;
    }

    private static function publicSession(array $issued): array
    {
        return ['token' => $issued['token'], 'exp' => $issued['exp']];
    }
}
