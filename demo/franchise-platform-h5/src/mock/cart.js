import { productForStoreById } from './selectors';

function normalizeQuantity(quantity) {
  return Math.max(1, Math.floor(Number(quantity) || 1));
}

function matches(item, target) {
  return item.productId === target.productId && item.spec === target.spec;
}

export function addCartItem(state, input) {
  const product = productForStoreById(state, state.activeStoreId, input.productId);
  if (!product) {
    throw new Error('商品已下架或当前门店不可售');
  }
  if (product.stock <= 0) {
    throw new Error('商品库存不足');
  }
  const quantity = normalizeQuantity(input.quantity);
  const existing = state.cart.find((item) => matches(item, input));
  if (existing) {
    existing.quantity += quantity;
    return existing;
  }

  const item = {
    productId: input.productId,
    spec: input.spec,
    quantity,
  };
  state.cart.push(item);
  return item;
}

export function setCartItemQuantity(state, target, quantity) {
  const item = state.cart.find((entry) => matches(entry, target));
  if (!item) {
    throw new Error('购物车商品不存在');
  }
  item.quantity = normalizeQuantity(quantity);
  return item;
}

export function removeCartItem(state, target) {
  state.cart = state.cart.filter((item) => !matches(item, target));
}
