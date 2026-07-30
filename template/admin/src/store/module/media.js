// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

/**
 * 布局配置
 * */

export default {
  namespaced: true,
  state: {
    isMobile: false, // 是否为手机
    isTablet: false, // 是否为平板
    isDesktop: true, // 是否为桌面
    isFullscreen: false, // 是否切换到了全屏
  },
  mutations: {
    /**
     * @description 设置设备类型
     * @param {Object} state vuex state
     * @param {String} type 设备类型，可选值为 Mobile、Tablet、Desktop
     */
    setDevice(state, type) {
      state.isMobile = false;
      state.isTablet = false;
      state.isDesktop = false;
      state[`is${type}`] = true;
    },
  },
  actions: {},
};
