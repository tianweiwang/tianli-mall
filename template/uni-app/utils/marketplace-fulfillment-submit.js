// Public child-local selection only. This module never maps private source coordinates.
let keySequence = 0;
function freeze(value) { if (value && typeof value === 'object') { Object.values(value).forEach(freeze); Object.freeze(value); } return value; }
function positive(value) {
  if (typeof value === 'string') { if (!/^[1-9][0-9]{0,9}$/.test(value)) throw new Error('请填写有效的包裹内件号'); value = Number(value); }
  if (!Number.isSafeInteger(value) || value < 1 || value > 2147483647) throw new Error('请填写有效的包裹内件号'); return value;
}
function businessKey(childId) {
  keySequence += 1;
  return 'receive:' + childId + ':' + Date.now().toString(36) + ':' + keySequence.toString(36) + ':'
    + Math.random().toString(36).slice(2, 13) + Math.random().toString(36).slice(2, 13);
}
export function prepareReceiptIntent(receipt, draft) {
  if (!receipt || !receipt.package || !receipt.allowed_actions || receipt.allowed_actions.receive !== true
    || !receipt.receipt_selection || !draft || !['all_eligible', 'items'].includes(draft.mode)) throw new Error('请重新读取当前可收货资料');
  const childStage = receipt.package.schema;
  if ((childStage !== 1 && childStage !== 2) || receipt.package.scope !== (childStage === 1 ? 'marketplace_fulfillment_child_v1' : 'marketplace_fulfillment_child_aftersale_v1')
    || receipt.receipt_selection.schema !== childStage || receipt.receipt_selection.coordinate !== 'child_item_ordinal') throw new Error('请重新读取当前可收货资料');
  let selection = { mode: 'all_eligible' }; let quantity = 0;
  if (draft.mode === 'all_eligible') receipt.receipt_selection.items.forEach(item => { quantity += item.eligible_quantity; });
  else {
    if (!Array.isArray(draft.items)) throw new Error('请选择要确认的商品范围');
    const facts = new Map(receipt.receipt_selection.items.map(item => [item.item_id, item])); const seen = new Set(); const items = [];
    draft.items.forEach(row => {
      const id = positive(row.item_id); const fact = facts.get(id);
      if (!fact || seen.has(id) || !Array.isArray(row.segments)) throw new Error('包裹商品选择不正确');
      seen.add(id); if (!row.segments.length) return;
      const segments = row.segments.map(segment => {
        const first = positive(segment.first); const last = positive(segment.last);
        if (first > last || last > fact.quantity) throw new Error('所选件号超出本包裹商品数量');
        let cursor = first;
        fact.eligible_segments.forEach(allowed => {
          if (allowed.last < cursor || allowed.first > last) return;
          if (allowed.first > cursor) return;
          cursor = Math.min(last, allowed.last) + 1;
        });
        if (cursor !== last + 1) throw new Error('所选范围包含当前不可确认的件，请重新选择');
        return { first, last };
      }).sort((a, b) => a.first - b.first || a.last - b.last);
      const merged = [];
      segments.forEach(segment => {
        const previous = merged[merged.length - 1];
        if (previous && segment.first <= previous.last) throw new Error('所选件号范围重复或交叠');
        if (previous && segment.first === previous.last + 1) previous.last = segment.last;
        else merged.push({ ...segment });
      });
      merged.forEach(segment => { quantity += segment.last - segment.first + 1; });
      items.push({ item_id: id, segments: merged });
    });
    if (!items.length) throw new Error('请选择本次确认收货范围');
    items.sort((a, b) => a.item_id - b.item_id); selection = { mode: 'items', items };
  }
  if (!quantity || !Number.isSafeInteger(quantity) || quantity > 4294967295) throw new Error('本次没有可确认的收货数量');
  const child = receipt.package; const body = { idem_key: businessKey(child.id), selection }; const wire = JSON.stringify(body);
  if (encodeURIComponent(wire).replace(/%[a-fA-F0-9]{2}|./g, 'x').length > 65536) throw new Error('本次收货选择内容超出限制');
  return freeze({ rootId: child.root_order_id, rootNo: child.root_order_no, childId: child.id, childNo: child.order_id, quantity, body, wire });
}
