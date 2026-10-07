// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------
export default {
  namespaced: true,
  state: {
    toggleStatus: false,
  },
  mutations: {
    TOGGLE_STATUS: (state, toggleStatus) => {
      state.toggleStatus = toggleStatus;
    },
  },
  getters: {
    toggleStatus: (state) => state.toggleStatus,
  },
};
