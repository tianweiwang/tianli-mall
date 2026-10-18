import Vue from 'vue';
// UI lifecycle only. It is not server authentication and stores no credential.
export const platformAuthEpoch = new Vue({ data: { value: 0 } });
const listeners = new Set();
export function advancePlatformAuthEpoch() {
  if (!Number.isSafeInteger(platformAuthEpoch.value) || platformAuthEpoch.value >= Number.MAX_SAFE_INTEGER) throw new Error('登录代次不可用，请重新载入页面');
  platformAuthEpoch.value += 1;
  listeners.forEach(listener => listener());
}
export function subscribePlatformAuthEpoch(listener) {
  listeners.add(listener); return () => listeners.delete(listener);
}