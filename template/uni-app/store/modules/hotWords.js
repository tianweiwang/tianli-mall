// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2024 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

export default {
	namespaced: true,
	state: {
		// 搜索关键字
		hotWord: []
	},
	getters: {},
	mutations: {
		setHotWord(state, fastsearchforhotwords) {
			state.hotWord = fastsearchforhotwords;
		}
	}
}
