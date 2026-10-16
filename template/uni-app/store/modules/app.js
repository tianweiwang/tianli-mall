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
	getUserInfo
} from "../../api/user.js";
import {
	LOGIN_STATUS,
	UID,
	USER_INFO,
	EXPIRES_TIME,
	STATE_R_KEY
} from '../../config/cache';
import Cache from '../../utils/cache';
import { pruneMarketplaceConfirmationOwner } from '../../utils/marketplace-confirmation-draft';

const ownerUid = value => {
	if (typeof value === 'number') return Number.isSafeInteger(value) && value > 0 ? String(value) : '';
	return typeof value === 'string' && /^[1-9][0-9]*$/.test(value) ? value : '';
};
const failedCleanupOwners = new Set();
const failedIdentityCaches = new Set();
let cachedLoginBlocked = false;
let userInfoRevision = 0;
const identityChanged = () => Object.assign(new Error('登录状态已变化，请重新获取用户信息'), { staleIdentity: true });

function cleanupState(state) {
	state.confirmationCleanupFailed = failedCleanupOwners.size > 0 || failedIdentityCaches.size > 0;
}
function initialIdentityValue(key, fallback) {
	try { return Cache.get(key) || fallback; }
	catch (error) { failedIdentityCaches.add(key); cachedLoginBlocked = true; return fallback; }
}
function clearIdentityCache(state, key) {
	let cleared = false;
	try {
		if (Cache.clear(key) !== false) {
			const value = uni.getStorageSync(key);
			cleared = value === '' || value === null || value === undefined;
		}
	} catch (error) { /* The memory identity has already been invalidated. */ }
	if (cleared) failedIdentityCaches.delete(key);
	else { failedIdentityCaches.add(key); cachedLoginBlocked = true; }
	cleanupState(state);
	return cleared;
}
function writeIdentityCache(state, key, value, expiry) {
	let written = false;
	try {
		const expected = typeof value === 'object' ? JSON.stringify(value) : value;
		written = Cache.set(key, value, expiry) !== false && uni.getStorageSync(key) === expected;
	} catch (error) { /* A failed cache write cannot be treated as a saved identity. */ }
	if (!written) { failedIdentityCaches.add(key); cachedLoginBlocked = true; }
	cleanupState(state);
	return written;
}

// A cached UID is only a cleanup hint. Only USERINFO's current network response grants verifiedUid.
function invalidateIdentity(state, advance = true) {
	let previous = ownerUid(state.verifiedUid) || ownerUid(state.uid);
	if (advance) state.authRevision = Number.isSafeInteger(state.authRevision) && state.authRevision >= 0 &&
		state.authRevision < Number.MAX_SAFE_INTEGER ? state.authRevision + 1 : Number.MAX_SAFE_INTEGER;
	state.userInfo = {};
	state.uid = 0;
	state.verifiedUid = '';
	if (!previous) {
		try { previous = ownerUid(Cache.get(UID)); }
		catch (error) { failedIdentityCaches.add(UID); cachedLoginBlocked = true; }
	}
	if (previous) failedCleanupOwners.add(previous);
	for (const uid of Array.from(failedCleanupOwners)) {
		try {
			if (pruneMarketplaceConfirmationOwner(uid) === true) failedCleanupOwners.delete(uid);
		} catch (error) { /* Keep the failed owner for the next explicit identity cleanup. */ }
	}
	clearIdentityCache(state, USER_INFO);
	clearIdentityCache(state, UID);
	for (const key of Array.from(failedIdentityCaches)) {
		if (key !== USER_INFO && key !== UID && key !== LOGIN_STATUS) clearIdentityCache(state, key);
	}
}

const state = {
	token: initialIdentityValue(LOGIN_STATUS, false),
	backgroundColor: "#fff",
	userInfo: {},
	uid: initialIdentityValue(UID, 0),
	authRevision: 0,
	verifiedUid: '',
	confirmationCleanupFailed: failedIdentityCaches.size > 0,
	homeActive: false,
	phoneStatus: true,
	pageFooter: uni.getStorageSync('pageFoot') || {},
	activityTab: ''
};

const mutations = {
	SETPHONESTATUS(state, val) {
		state.phoneStatus = val;
	},
	LOGIN(state, opt) {
		state.token = false;
		invalidateIdentity(state);
		const next = state.authRevision < Number.MAX_SAFE_INTEGER && opt && typeof opt.token === 'string' && opt.token ? opt.token : false;
		if (next) {
			let saved = writeIdentityCache(state, LOGIN_STATUS, next, opt.time);
			try { saved = saved && Cache.get(LOGIN_STATUS) === next; } catch (error) { saved = false; }
			if (saved) {
				failedIdentityCaches.delete(LOGIN_STATUS);
				state.token = next;
			} else { failedIdentityCaches.add(LOGIN_STATUS); cachedLoginBlocked = true; }
		} else clearIdentityCache(state, LOGIN_STATUS);
		cleanupState(state);
		if (!state.confirmationCleanupFailed) cachedLoginBlocked = false;
	},
	SETUID(state, val) {
		if (!state.token || (state.verifiedUid && ownerUid(val) !== state.verifiedUid)) return;
		state.uid = val;
		writeIdentityCache(state, UID, val);
	},
	UPDATE_LOGIN(state, token) {
		const next = typeof token === 'string' && token ? token : false;
		if (cachedLoginBlocked && state.token !== next) {
			state.token = false;
			invalidateIdentity(state);
			// Retry removal of the stale cache, never promote it back into a logged-out memory session.
			clearIdentityCache(state, LOGIN_STATUS);
			return;
		}
		if (state.token !== next) invalidateIdentity(state);
		state.token = state.authRevision < Number.MAX_SAFE_INTEGER ? next : false;
	},
	ACTIVITYTAB(state, tab) {
		state.activityTab = tab;
	},
	LOGOUT(state, options) {
		const alreadyAnonymous = options && options.onlySyncMissingToken && !state.token && !state.verifiedUid &&
			!ownerUid(state.uid) && Object.keys(state.userInfo || {}).length === 0;
		state.token = false;
		invalidateIdentity(state, !alreadyAnonymous);
		clearIdentityCache(state, LOGIN_STATUS);
		clearIdentityCache(state, 'snsapiCode');
		clearIdentityCache(state, EXPIRES_TIME);
		clearIdentityCache(state, STATE_R_KEY);
		writeIdentityCache(state, 'authIng', false);
		if (!(options && options.onlySyncMissingToken) && !state.confirmationCleanupFailed) cachedLoginBlocked = false;
	},
	INVALIDATE_VERIFIED_IDENTITY(state) {
		invalidateIdentity(state);
	},
	BACKGROUND_COLOR(state, color) {
		state.color = color;
		document.body.style.backgroundColor = color;
	},
	UPDATE_USERINFO(state, userInfo) {
		if (!state.token || !userInfo || typeof userInfo !== 'object' || Array.isArray(userInfo) ||
			(state.verifiedUid && ownerUid(userInfo.uid) !== state.verifiedUid)) return;
		state.userInfo = userInfo;
		writeIdentityCache(state, USER_INFO, userInfo);
	},
	VERIFIED_USERINFO(state, value) {
		if (state.authRevision !== value.authRevision || state.token !== value.token ||
			!ownerUid(value.user.uid)) return;
		// Parallel force reads each resolve their own real response; an older read cannot overwrite newer display data.
		if (value.userInfoRevision !== userInfoRevision && state.verifiedUid) return;
		state.uid = value.user.uid;
		state.userInfo = value.user;
		state.verifiedUid = ownerUid(value.user.uid);
		writeIdentityCache(state, UID, value.user.uid);
		writeIdentityCache(state, USER_INFO, value.user);
	},
	OPEN_HOME(state) {
		state.homeActive = true;
	},
	CLOSE_HOME(state) {
		state.homeActive = false;
	},
	FOOT_UPLOAD(state, data) {
		state.pageFooter = data
	}
};

const actions = {

	USERINFO({
		state,
		commit
	}, force) {
		if (!force && state.token && ownerUid(state.userInfo && state.userInfo.uid) &&
			state.verifiedUid === ownerUid(state.userInfo.uid) && state.verifiedUid === ownerUid(state.uid))
			return Promise.resolve(state.userInfo);
		const authRevision = state.authRevision, token = state.token;
		const revision = ++userInfoRevision;
		if (!token || !Number.isSafeInteger(authRevision) || authRevision < 0 || authRevision >= Number.MAX_SAFE_INTEGER)
			return Promise.reject(identityChanged());
		return getUserInfo().then(res => {
			const user = res && res.data;
			if (state.authRevision !== authRevision || state.token !== token) throw identityChanged();
			if (!user || typeof user !== 'object' || Array.isArray(user) || !ownerUid(user.uid)) throw new Error('用户信息未完整取得，请重试');
			// A real owner change invalidates every old page even if a token producer reused the token.
			if (state.verifiedUid && state.verifiedUid !== ownerUid(user.uid)) {
				commit('INVALIDATE_VERIFIED_IDENTITY');
				throw identityChanged();
			}
			commit('VERIFIED_USERINFO', { user, token, authRevision, userInfoRevision: revision });
			if (state.authRevision !== authRevision || state.token !== token || state.verifiedUid !== ownerUid(user.uid)) throw identityChanged();
			return user;
		});
	}
};

export default {
	state,
	mutations,
	actions
};
