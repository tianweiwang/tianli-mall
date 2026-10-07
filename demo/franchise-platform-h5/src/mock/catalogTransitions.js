const DEFAULT_PRODUCT_IMAGE = './products/igood-01.svg';
const CATALOG_TYPES = {
  product: 'products',
  service: 'services',
};

function requiredText(value, label) {
  const text = String(value || '').trim();
  if (!text) throw new Error(`${label}不能为空`);
  return text;
}

function positiveInteger(value, message) {
  const number = Math.floor(Number(value));
  if (!Number.isFinite(number) || number <= 0) throw new Error(message);
  return number;
}

function nonNegativeInteger(value, message) {
  const number = Math.floor(Number(value));
  if (!Number.isFinite(number) || number < 0) throw new Error(message);
  return number;
}

function activeStoreIds(state, storeIds) {
  const ids = [...new Set((storeIds || []).map((item) => String(item).trim()).filter(Boolean))];
  if (!ids.length) throw new Error('至少选择一家营业门店');
  const activeIds = new Set(
    (state.stores || []).filter((item) => item.status === 'active').map((item) => item.id),
  );
  if (ids.some((id) => !activeIds.has(id))) throw new Error('适用门店不可用');
  return ids;
}

function catalogList(state, type) {
  const field = CATALOG_TYPES[type];
  if (!field) throw new Error('目录类型无效');
  state[field] = state[field] || [];
  return state[field];
}

function normalizeService(state, input) {
  const priceModel = input.priceModel === 'quote' ? 'quote' : 'standard';
  const service = {
    name: requiredText(input.name, '名称'),
    series: requiredText(input.series, '系列'),
    description: requiredText(input.description, '项目说明'),
    durationMinutes: positiveInteger(input.durationMinutes, '服务时长必须大于 0'),
    priceModel,
    unitPriceCents: positiveInteger(input.unitPriceCents, '单次价格必须大于 0'),
    storeIds: activeStoreIds(state, input.storeIds),
  };
  if (priceModel === 'standard') {
    const packagePriceCents = nonNegativeInteger(
      input.packagePriceCents || 0,
      '疗程价格和数量必须同时有效',
    );
    const packageCount = nonNegativeInteger(
      input.packageCount || 0,
      '疗程价格和数量必须同时有效',
    );
    if ((packagePriceCents > 0) !== (packageCount > 0)) {
      throw new Error('疗程价格和数量必须同时有效');
    }
    if (packagePriceCents > 0) {
      service.packagePriceCents = packagePriceCents;
      service.packageCount = packageCount;
      service.packageUnit = requiredText(input.packageUnit || '次', '疗程单位');
    }
  }
  return service;
}

function normalizeProduct(state, input) {
  const specs = [...new Set(
    (input.specs || []).map((item) => String(item).trim()).filter(Boolean),
  )];
  if (!specs.length) throw new Error('至少填写一个商品规格');
  const priceCents = positiveInteger(input.priceCents, '商品价格必须大于 0');
  const originalPriceCents = nonNegativeInteger(
    input.originalPriceCents || priceCents,
    '商品划线价不能小于 0',
  );
  const deliveryType = input.deliveryType === 'large' ? 'large' : 'express';
  return {
    name: requiredText(input.name, '名称'),
    series: requiredText(input.series || '维养系列', '系列'),
    category: requiredText(input.category, '分类'),
    unit: requiredText(input.unit, '单位'),
    specs,
    priceCents,
    originalPriceCents,
    stock: nonNegativeInteger(input.stock, '库存不能小于 0'),
    deliveryType,
    image: String(input.image || '').trim() || DEFAULT_PRODUCT_IMAGE,
    storeIds: activeStoreIds(state, input.storeIds),
  };
}

function normalizeInput(state, type, input) {
  if (type === 'service') return normalizeService(state, input);
  if (type === 'product') return normalizeProduct(state, input);
  throw new Error('目录类型无效');
}

function nextCatalogId(state, type) {
  state.sequence = state.sequence || {};
  const current = Number(state.sequence[type] || 0) + 1;
  state.sequence[type] = current;
  return type === 'service' ? `SV${1000 + current}` : current;
}

export function createCatalogItem(state, type, input) {
  const list = catalogList(state, type);
  const item = {
    id: nextCatalogId(state, type),
    ...normalizeInput(state, type, input),
    status: 'active',
    createdAt: state.demoNow,
    updatedAt: state.demoNow,
  };
  if (type === 'product') item.sales = 0;
  list.unshift(item);
  return item;
}

export function updateCatalogItem(state, type, id, input) {
  const list = catalogList(state, type);
  const item = list.find((candidate) => candidate.id === id);
  if (!item) throw new Error('目录项目不存在');
  const preserved = {
    id: item.id,
    status: item.status,
    createdAt: item.createdAt,
  };
  if (type === 'product') preserved.sales = item.sales;
  Object.keys(item).forEach((key) => {
    if (!['id', 'status', 'createdAt', 'sales'].includes(key)) delete item[key];
  });
  Object.assign(item, normalizeInput(state, type, input), preserved, {
    updatedAt: state.demoNow,
  });
  return item;
}

export function setCatalogItemStatus(state, type, id, status) {
  if (!['active', 'inactive'].includes(status)) throw new Error('目录状态无效');
  const item = catalogList(state, type).find((candidate) => candidate.id === id);
  if (!item) throw new Error('目录项目不存在');
  item.status = status;
  item.updatedAt = state.demoNow;
  return item;
}
