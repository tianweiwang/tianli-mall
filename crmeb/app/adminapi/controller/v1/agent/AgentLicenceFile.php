<?php
declare(strict_types=1);
namespace app\adminapi\controller\v1\agent;

use app\Request;
use app\dao\marketplace\assets\CustomerAgentLicenceAssetDao;
use app\services\marketplace\assets\CustomerAgentLicenceAssetServices;
use app\api\controller\v1\user\AgentLicenceController;
use think\Response;

final class AgentLicenceFile
{
    public function content(Request $request,CustomerAgentLicenceAssetServices $assets):Response
    {
        $applyId=CustomerAgentLicenceAssetDao::integer($request->route('apply_id',null,null),1,2147483647);
        $assetId=CustomerAgentLicenceAssetDao::assetId($request->route('asset_id',null,null));
        return AgentLicenceController::respond($assets->platformContent($request,$applyId,$assetId));
    }
}
