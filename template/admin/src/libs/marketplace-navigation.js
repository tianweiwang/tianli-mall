import setting from '@/setting';
export function platformCan(store, permission) { const grants = store.state.userInfo.uniqueAuth; return Array.isArray(grants) && grants.includes(permission); }
export function withMarketplaceMenus(menuList, grants) {
  if (!Array.isArray(menuList) || !Array.isArray(grants)) throw new Error('平台菜单或权限返回不完整');
  const rootPath = setting.routePre + '/marketplace';
  const list = menuList.filter((item) => !item.marketplaceProjection).map((item) => ({ ...item }));
  const children = [
    { permission: 'marketplace-merchants-list', path: rootPath + '/merchants', title: '入驻商家' },
    { permission: 'marketplace-products-list', path: rootPath + '/products', title: '商品审核' },
    { permission: 'marketplace-aftersales-list', path: rootPath + '/aftersales', title: '多商户售后' },
    { permission: 'marketplace-reviews-list', path: rootPath + '/reviews', title: '商品评价' },
  ].filter((item) => grants.includes(item.permission)).map((item) => ({ path: item.path, title: item.title, icon: '', header: 'marketplace', is_header: 0, is_show: 1 }));
  const existing = list.find((item) => item.path === rootPath);
  if (existing) {
    if (existing.children !== undefined && !Array.isArray(existing.children)) throw new Error('经营商家菜单返回不完整');
    const paths = ['/merchants', '/products', '/aftersales', '/reviews'].map((path) => rootPath + path);
    existing.children = (existing.children || []).filter((item) => !paths.includes(item.path)).concat(children);
  } else if (children.length) list.push({ path: rootPath, title: '经营商家', icon: 'office-building', header: 'marketplace', is_header: 1, is_show: 1, marketplaceProjection: true, children });
  return list;
}
