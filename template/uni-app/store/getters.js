// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2024 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

export default {
  token: state => state.app.token,
  isLogin: state => !!state.app.token,
  backgroundColor: state => state.app.backgroundColor,
  userInfo: state => state.app.userInfo || {},
	uid:state => state.app.uid,
	homeActive: state => state.app.homeActive,
	home: state => state.app.home,
	cartNum: state => state.indexData.cartNum,
	activityTab: state => state.app.activityTab,
};
// export default {
//   token: state => 'REDACTED_LOCAL_TOKEN',
//   isLogin: state => true,
//   backgroundColor: state => state.app.backgroundColor,
//   userInfo: state => state.app.userInfo || {}
// };
