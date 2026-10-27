// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2024 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

import store from "../store";
import Cache from '../utils/cache';
import {
	Debounce
} from '@/utils/validate.js'
// #ifdef H5 || APP-PLUS
import {
	isWeixin
} from "../utils";
import auth from './wechat';
// #endif

import {
	LOGIN_STATUS
} from './../config/cache';
import Routine from '@/libs/routine';


function prePage() {
	let pages = getCurrentPages();
	let prePage = pages[pages.length - 1];
	if (!prePage) return '';
	// #ifdef MP
	if (!prePage.route) return '';
	const route = '/' + prePage.route.replace(/^\/+/, '');
	const options = prePage.options || {};
	const query = Object.keys(options)
		.filter(key => options[key] !== null && options[key] !== undefined)
		.map(key => encodeURIComponent(key) + '=' + encodeURIComponent(String(options[key])))
		.join('&');
	return route + (query ? '?' + query : '');
	// #endif
	// #ifndef MP
	// #ifndef APP-PLUS
	return prePage.route;
	// #endif
	// #ifdef APP-PLUS
	return prePage.$page.fullPath;
	// #endif
	// #endif

}




// The debounce may outlive a login. Recheck the requesting session at execution time too.
const deferredLogin = Debounce((push, pathLogin, revision, token) => {
	try {
		if ((Cache.get(LOGIN_STATUS) || false) !== token) return;
	} catch (error) { return; }
	if (store.state.app.authRevision === revision && store.state.app.token === token) _toLogin(push, pathLogin);
}, 800);
export function toLogin(push, pathLogin) {
	deferredLogin(push, pathLogin, store.state.app.authRevision, store.state.app.token);
}

function _toLogin(push, pathLogin) {
	store.commit("LOGOUT");
	let path = prePage();

	// #ifdef H5
	path = location.pathname + location.search;
	// #endif
	const BASIC_CONFIG = Cache.get('BASIC_CONFIG') || {}
	if (!pathLogin)
		pathLogin = '/pages/users/login/index'
	// #ifdef MP
	if (path.split('?')[0] !== pathLogin.split('?')[0]) {
		Cache.set('login_back_url', path);
	}
	// #endif
	// #ifndef MP
	if (path !== pathLogin) {
		Cache.set('login_back_url', path);
	}
	// #endif
	// #ifdef H5
	if (isWeixin() && BASIC_CONFIG.wechat_status) {
		uni.navigateTo({
			url: '/pages/users/wechat_login/index',
		});

	} else {
		uni.navigateTo({
			url: '/pages/users/login/index'
		})
	}
	// #endif

	// #ifdef MP
	let url
	if (!BASIC_CONFIG.wechat_auth_switch) {
		url = '/pages/users/binding_phone/index?pageType=0'
	} else {
		url = '/pages/users/wechat_login/index'
	}
	uni.navigateTo({
		url
	})
	// #endif

	// #ifdef APP-PLUS
	uni.navigateTo({
		url: '/pages/users/login/index'
	})
	// #endif

}


export function checkLogin() {
	let token = false;
	try { token = Cache.get(LOGIN_STATUS); } catch (error) { /* Treat an unreadable token as an unverified session. */ }
	if (typeof token !== 'string' || !token) {
		store.commit('LOGOUT', { onlySyncMissingToken: true });
		return false;
	} else {
		store.commit('UPDATE_LOGIN', token);
		return store.state.app.token === token;
	}

}
