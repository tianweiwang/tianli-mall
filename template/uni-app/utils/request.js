// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2024 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

import {
	HTTP_REQUEST_URL,
	HEADER,
	TOKENNAME,
	TIMEOUT
} from '@/config/app';
import {
	toLogin,
	checkLogin
} from '../libs/login';
import store from '../store';
import i18n from './lang.js';
import Cache from './cache';
import { LOGIN_STATUS } from '@/config/cache';

/**
 * 发送请求
 */
function baseRequest(url, method, data, {
	noAuth = false,
	noVerify = false,
	identitySensitive = false
}) {
	let Url = HTTP_REQUEST_URL,
		header = { ...HEADER };
	const sessionScoped = !noAuth || identitySensitive;
	if (sessionScoped) checkLogin();

	if (!noAuth) {
		//登录过期自动登录
		if (!store.state.app.token) {
			toLogin();
			return Promise.reject({
				msg: i18n.t(`未登录`)
			});
		}
	}
	const token = store.state.app.token, authRevision = store.state.app.authRevision;
	const matchesIdentity = () => {
		try {
			return Number.isSafeInteger(authRevision) && authRevision >= 0 && authRevision < Number.MAX_SAFE_INTEGER &&
				store.state.app.authRevision === authRevision && store.state.app.token === token &&
				(Cache.get(LOGIN_STATUS) || false) === token;
		} catch (error) { return false; }
	};
	const currentSession = () => !sessionScoped || matchesIdentity();
	const staleIdentity = () => ({ status: 409, staleIdentity: true, msg: i18n.t(`登录状态已变化，请重试`) });
	Object.keys(header).forEach(key => {
		if (key.toLowerCase() === TOKENNAME.toLowerCase()) delete header[key];
	});
	if (token) header[TOKENNAME] = 'Bearer ' + token;
	if (!currentSession()) return Promise.reject(staleIdentity());

	return new Promise((reslove, reject) => {
		if (uni.getStorageSync('locale')) {
			header['Cb-lang'] = uni.getStorageSync('locale')
		}
		uni.request({
			url: Url + '/api/' + url,
			method: method || 'GET',
			header: header,
			data: data || {},
			timeout: TIMEOUT,
			success: (res) => {
				if (!currentSession()) return reject(staleIdentity());
				if (noVerify) return reslove(res.data, res);
				if (!res || !res.data || typeof res.data !== 'object' || Array.isArray(res.data))
					return reject({ msg: i18n.t(`服务器响应未完整取得，请重试`) });
				if (res.data.status == 200)
					reslove(res.data, res);
				else if (res.data.status == 401) {
					if (!matchesIdentity()) return reject(staleIdentity());
					toLogin();
					reject(res.data);
				} else if (res.data.status == 402) {
					uni.showModal({
						title: i18n.t(`提示`),
						content: res.data.msg,
						showCancel: false,
						confirmText: i18n.t(`我知道了`)
					});
					reject(res.data.msg || i18n.t(`系统错误`));
				} else
					reject(res.data.msg || i18n.t(`系统错误`));
			},
			fail: (msg) => {
				if (!currentSession()) return reject(staleIdentity());
				let data = {
					mag: i18n.t(`请求失败`),
					status: 1 //1没网
				}
				// #ifdef APP-PLUS
				reject(data);
				// #endif
				// #ifndef APP-PLUS
				reject(i18n.t(`请求失败`));
				// #endif
			}
		})
	});
}

const request = {};

['options', 'get', 'post', 'put', 'head', 'delete', 'trace', 'connect'].forEach((method) => {
	request[method] = (api, data, opt) => baseRequest(api, method, data, opt || {})
});



export default request;
