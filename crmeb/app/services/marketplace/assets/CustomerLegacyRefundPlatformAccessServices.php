<?php
declare(strict_types=1);

namespace app\services\marketplace\assets;

use app\Request;
use app\model\system\SystemMenus;
use app\model\system\admin\SystemAdmin;
use app\services\system\admin\SystemRoleServices;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use think\exception\HttpException;
use think\Model;

/** Current original refund list/detail grant for the fixed private material reader. */
final class CustomerLegacyRefundPlatformAccessServices
{
    private const READ_ROUTES=[
        'POST'=>'refund/<reader_kind>/<refund_id>/materials/<asset_id>/read-ticket',
        'GET'=>'refund/<reader_kind>/<refund_id>/materials/<asset_id>/content',
    ];
    private const ORIGINAL=['list'=>'refund/list','detail'=>'refund/info/<uni>'];

    /**
     * The result is the current authenticated administrator, not a refund/asset grant.
     * The caller must independently read the complete saved legacy refund and material reference.
     */
    public function authorizeRequest(Request $request, int $refundId): array
    {
        try {
            self::assertRoute($request, $refundId);
            $reader=(string)$request->route('reader_kind',null,'');
            $identity = $request->adminInfo();
            $adminId = self::positiveId($request->adminId());
            $verified = $request->verifiedAdminSession();
            if ($request->isAdminLogin() !== true || !is_array($identity)
                || (string)($identity['id'] ?? '') !== (string)$adminId
                || $verified['id'] !== (string)$adminId
                || !hash_equals($request->verifiedTokenFingerprint(), $verified['fingerprint'])) {
                throw new AuthSessionException();
            }

            // This is a fresh DB read, never the adminInfo roles or an ACL cache.
            $admin = self::data((new SystemAdmin())->db()->where('id', '=', $adminId)
                ->field(['id', 'status', 'is_del', 'level', 'roles', 'division_id'])->find());
            if ($admin === null || (string)$admin['id'] !== (string)$adminId
                || (int)$admin['status'] !== 1 || (int)$admin['is_del'] !== 0) {
                throw new AuthSessionException();
            }
            // The existing original refund consumer bypasses role checks for level 0.
            if ((int)$admin['level'] === 0) return ['id'=>$adminId,'reader_kind'=>$reader];

            $roles = self::ids($admin['roles']);
            if ($roles === []) throw new HttpException(403, '没有权限访问');
            /** @var SystemRoleServices $roleService */
            $roleService = app()->make(SystemRoleServices::class);
            // getRoleIds queries only the currently enabled roles, with no cache.
            $grants = self::ids(array_values($roleService->getRoleIds($roles)));
            if ($grants === []) throw new HttpException(403, '没有权限访问');
            foreach ((new SystemMenus())->db()->whereIn('id', $grants)
                ->where('auth_type', '=', 2)->where('is_del', '=', 0)
                ->field(['id', 'api_url', 'methods'])->select() as $model) {
                $menu = self::data($model);
                if ($menu === null || !is_string($menu['api_url']) || !is_string($menu['methods'])) {
                    throw new AuthUnavailableException();
                }
                // These are the original GET registration and verifyAuth normalization.
                if (strtoupper(trim($menu['methods'])) === 'GET'
                    && trim(strtolower(str_replace(' ', '', $menu['api_url']))) === self::ORIGINAL[$reader]) {
                    return ['id'=>$adminId,'reader_kind'=>$reader];
                }
            }
            throw new HttpException(403, '没有权限访问');
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    private static function assertRoute(Request $request,int $refundId):void
    {
        if($request!==app()->request||app()->http->getName()!=='adminapi')throw new HttpException(403,'没有权限访问');
        $method=strtoupper($request->method());$reader=$request->route('reader_kind',null,'');
        if(!is_string($reader)||!isset(self::ORIGINAL[$reader])||!isset(self::READ_ROUTES[$method])
            ||trim(strtolower((string)$request->rule()->getRule()))!==self::READ_ROUTES[$method]
            ||$request->rule()->getOption('legacy_refund_private_reader')!=='platform'
            ||self::positiveId($refundId)!==self::positiveId($request->route('refund_id',null,'')))throw new HttpException(403,'没有权限访问');
        $assetId=$request->route('asset_id',null,'');
        if(!is_string($assetId)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$assetId)!==1
            ||(strlen($assetId)===20&&strcmp($assetId,'18446744073709551615')>0))throw new HttpException(403,'没有权限访问');
    }

    private static function positiveId(mixed $value): int
    {
        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/D', $value) === 1) $value = (int)$value;
        if (!is_int($value) || $value < 1 || $value > 4294967295) throw new AuthSessionException();
        return $value;
    }

    private static function ids(mixed $value): array
    {
        if (is_string($value)) $value = $value === '' ? [] : explode(',', $value);
        if (!is_array($value) || !array_is_list($value)) throw new AuthUnavailableException();
        $ids = [];
        foreach ($value as $id) {
            if ($id === '' || $id === '0' || $id === 0) continue;
            if (is_string($id) && preg_match('/\A[1-9][0-9]{0,9}\z/D', $id) === 1) $id = (int)$id;
            if (!is_int($id) || $id < 1 || $id > 4294967295) throw new AuthUnavailableException();
            $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    private static function data(array|Model|null $value): ?array
    {
        $value = $value instanceof Model ? $value->getData() : $value;
        return is_array($value) && $value !== [] ? $value : null;
    }
}
