import Vue from 'vue';
import { createSeed, DEMO_VERSION } from './seed';
import { activateBindingCustomer } from './transitions';

export const STORAGE_KEY = 'tianli.franchise-demo.v1';

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

export function createDemoStore(storage = window.localStorage) {
  const storedValue = storage.getItem(STORAGE_KEY);
  let data;
  let notice = '';

  if (storedValue === null) {
    data = createSeed();
  } else {
    try {
      data = JSON.parse(storedValue);
      if (!data || data.version !== DEMO_VERSION) {
        throw new Error('version mismatch');
      }
    } catch (_error) {
      data = createSeed();
      notice = '本地数据异常，演示数据已恢复。';
    }
  }

  const distributorCustomerId = data.distributor?.customerId || 'CUS1001';
  (data.orders || []).forEach((order) => {
    if (!order.customerId) {
      order.customerId = distributorCustomerId;
    }
  });

  const activeBinding = (data.distributionBindings || []).find(
    (item) => item.id === data.activeDistributionBindingId && item.status === 'active',
  );
  if (activeBinding) {
    activateBindingCustomer(data, activeBinding);
  }

  const state = Vue.observable({ data, notice });

  function persist() {
    storage.setItem(STORAGE_KEY, JSON.stringify(state.data));
  }

  persist();

  return {
    state,
    snapshot() {
      return clone(state.data);
    },
    clearNotice() {
      state.notice = '';
    },
    commit(mutator) {
      const draft = clone(state.data);
      mutator(draft);
      state.data = draft;
      persist();
    },
    reset() {
      state.data = createSeed();
      state.notice = '演示数据已重置。';
      persist();
    },
  };
}

export const demoStore = createDemoStore();
