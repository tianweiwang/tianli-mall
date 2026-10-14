// Bound to main 477732 MerchantPermissions::ALL, not a fictitious permission API.
export const permissions = [
  { value: 'merchant.profile.read', label: '本店信息', description: '读取本人和本店资料；商家后台进入页面需要此项' },
  { value: 'merchant.employee.manage', label: '员工管理', description: '读取、新建和维护本店员工' },
  { value: 'merchant.role.manage', label: '角色管理', description: '读取、新建和维护本店角色及权限' },
  { value: 'product.read', label: '商品查看', description: '商品列表、详情和平台启用分类' },
  { value: 'product.write', label: '商品编辑', description: '新建和维护商品资料、规格' },
  { value: 'product.submit_review', label: '商品送审', description: '把已保存的商品提交审核' },
  { value: 'stock.adjust', label: '库存调整', description: '按实际 SKU 增减库存并填写原因' },
  { value: 'attachment.manage', label: '素材管理', description: '维护本店素材与素材分类' },
  { value: 'freight.manage', label: '运费模板', description: '维护本店运费模板' },
];
const known = permissions.map((item) => item.value);
const phpTrim = (value) => value.replace(/^[ \t\n\r\u0000\u000b]+|[ \t\n\r\u0000\u000b]+$/g, '');
function text(value, max, label) {
  if (typeof value !== 'string' || value.includes('\u0000') || Array.from(value).length > max) throw new Error(label + '长度或格式不正确');
  return value;
}
export function recordId(value) {
  if (!Number.isInteger(value) || value < 1 || value > 4294967295) throw new Error('未能读取实际记录编号');
  return value;
}
export function employeeWrite(form) {
  const account = typeof form.account === 'string' ? phpTrim(form.account) : '';
  if (!account) throw new Error('请填写员工账号');
  text(account, 191, '员工账号');
  return { account, role_id: recordId(form.role_id), name: text(form.name, 64, '姓名'), avatar: text(form.avatar, 512, '头像地址') };
}
export function roleWrite(form) {
  const name = typeof form.name === 'string' ? phpTrim(form.name) : '';
  if (!name) throw new Error('请填写角色名称');
  text(name, 64, '角色名称');
  if (!Array.isArray(form.permissions) || form.permissions.some((value) => !known.includes(value))) throw new Error('请使用当前权限目录中的选项');
  return { name, permissions: Array.from(new Set(form.permissions)) };
}
export function employeeDto(value) {
  if (!value || typeof value !== 'object') throw new Error('未能读取员工记录');
  recordId(value.id); recordId(value.mer_id); recordId(value.role_id);
  if (typeof value.account !== 'string' || typeof value.name !== 'string' || typeof value.avatar !== 'string' || ![0, 1].includes(value.status)
    || !Number.isInteger(value.add_time) || !Number.isInteger(value.update_time)) throw new Error('员工记录格式不正确');
  return value;
}
export function roleDto(value) {
  if (!value || typeof value !== 'object') throw new Error('未能读取角色记录');
  recordId(value.id); recordId(value.mer_id);
  if (typeof value.name !== 'string' || !Array.isArray(value.permissions) || value.permissions.some((item) => !known.includes(item))
    || !Number.isInteger(value.add_time) || !Number.isInteger(value.update_time)) throw new Error('角色记录格式不正确');
  return value;
}
export function listDto(value, read) {
  if (!value || !Array.isArray(value.list) || !Number.isInteger(value.count) || value.count < 0) throw new Error('列表响应格式不正确');
  return { list: value.list.map(read), count: value.count };
}
export function knownRoleOptions(profile, employees) {
  const options = new Map();
  const role = profile && profile.role;
  // me() returns findForContext raw DB scalar ids; only canonical positive numeric strings are normalized here.
  const meId = role && (typeof role.id === 'string' && /^[1-9][0-9]{0,9}$/.test(role.id) ? Number(role.id) : role.id);
  if (role && Number.isInteger(meId) && meId > 0 && meId <= 4294967295 && typeof role.name === 'string') options.set(meId, { id: meId, name: role.name });
  employees.forEach((employee) => {
    const id = recordId(employee.role_id);
    if (!options.has(id)) options.set(id, { id, name: '员工“' + (employee.name || employee.account) + '”的当前角色（未返回角色名）' });
  });
  return Array.from(options.values());
}
export function passwordDelivery(value, origin) {
  if (!value || value.endpoint !== '/merchantapi/password/setup' || typeof value.token !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(value.token)
    || !Number.isInteger(value.expires_at) || value.expires_at <= 0) throw new Error('未能读取服务器返回的一次性设置链接');
  return { url: origin + '/merchant/#/password-setup?token=' + encodeURIComponent(value.token), expires_at: value.expires_at };
}
export function formatTime(value) {
  return Number.isInteger(value) && value > 0 ? new Date(value * 1000).toLocaleString('zh-CN', { hour12: false }) : '—';
}
