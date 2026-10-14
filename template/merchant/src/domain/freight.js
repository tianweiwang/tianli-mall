import { decimal, recordId } from './product.js';
export function regionRule() { return { region: [], first: '1.00', price: '0.00', continue: '1.00', continue_price: '0.00' }; }
export function blankFreight() { return { name: '', type: 1, appoint: 0, no_delivery: 0, sort: 0, region_info: [{ ...regionRule(), region: { city_id: 0, name: '默认全国' } }], appoint_info: [], no_delivery_info: [] }; }
export function freightFromDetail(data) {
  const form = blankFreight();
  ['name', 'type', 'appoint', 'no_delivery', 'sort', 'region_info', 'appoint_info', 'no_delivery_info'].forEach((key) => { if (data[key] === undefined) throw new Error('运费模板详情返回不完整'); form[key] = JSON.parse(JSON.stringify(data[key])); });
  return form;
}
export function cityOptions(list) { return list.filter((row) => row.level === 0).map((province) => ({ value: province.city_id, label: province.name, children: (province.children || []).filter((city) => city.level === 1).map((city) => ({ value: city.city_id, label: city.name })) })).filter((row) => row.children.length); }
export function toPaths(places) { return Array.isArray(places) ? places.flatMap((province) => province.children.map((city) => [province.city_id, city.city_id])) : []; }
export function fromPaths(paths, cities) {
  const provinces = [];
  paths.forEach((path) => {
    const province = cities.find((row) => row.city_id === path[0]); const city = province && province.children.find((row) => row.city_id === path[1] && row.level === 1);
    if (!province || !city) throw new Error('地域选择与当前城市列表不一致');
    let chosen = provinces.find((row) => row.city_id === province.city_id); if (!chosen) { chosen = { city_id: province.city_id, name: province.name, children: [] }; provinces.push(chosen); }
    if (!chosen.children.some((row) => row.city_id === city.city_id)) chosen.children.push({ city_id: city.city_id, name: city.name });
  }); return provinces;
}
function places(value) {
  if (!Array.isArray(value) || !value.length) throw new Error('请选择规则适用的城市');
  return value.map((province) => { if (!province.children.length) throw new Error('请选择规则适用的城市'); return { city_id: recordId(province.city_id), name: province.name, children: province.children.map((city) => ({ city_id: recordId(city.city_id), name: city.name })) }; });
}
function quantity(value, type, positive) { const normalized = decimal(value); if (positive && normalized === '0.00') throw new Error('首件和续件数量必须大于零'); if (type === 1 && !normalized.endsWith('.00')) throw new Error('按件计费的数量必须为整数'); return normalized; }
export function freightWrite(form) {
  if (!form.name.trim()) throw new Error('请填写模板名称');
  if (!Number.isInteger(form.sort) || form.sort < -2147483648 || form.sort > 2147483647) throw new Error('排序必须为整数');
  const body = { name: form.name.trim(), type: form.type, appoint: form.appoint ? 1 : 0, no_delivery: form.no_delivery ? 1 : 0, sort: form.sort, region_info: [], appoint_info: [], no_delivery_info: [] };
  let defaults = 0;
  body.region_info = form.region_info.map((rule) => {
    const nationwide = !Array.isArray(rule.region) && rule.region.city_id === 0; if (nationwide) defaults += 1;
    return { region: nationwide ? { city_id: 0, name: '默认全国' } : places(rule.region), first: quantity(rule.first, form.type, true), price: decimal(rule.price), continue: quantity(rule.continue, form.type, true), continue_price: decimal(rule.continue_price) };
  });
  if (defaults !== 1) throw new Error('运费规则需要一项默认全国规则');
  if (form.appoint) { if (!form.appoint_info.length) throw new Error('请添加指定包邮规则'); body.appoint_info = form.appoint_info.map((rule) => ({ place: places(rule.place), a_num: quantity(rule.a_num, form.type, false), a_price: decimal(rule.a_price) })); }
  if (form.no_delivery) { if (!form.no_delivery_info.length) throw new Error('请添加不配送地域'); body.no_delivery_info = form.no_delivery_info.map((rule) => ({ place: places(rule.place) })); }
  ['region_info', 'appoint_info', 'no_delivery_info'].forEach((key) => {
    if (body[key].length > 200) throw new Error('每类规则最多 200 组'); const seen = new Set();
    body[key].forEach((rule) => { const value = key === 'region_info' ? rule.region : rule.place; toPaths(value).forEach((path) => { if (seen.has(path[1])) throw new Error('同一类规则中的城市不能重复'); seen.add(path[1]); }); }); if (seen.size + (key === 'region_info' ? defaults : 0) > 10000) throw new Error('地域数量超出上限');
  }); return body;
}
