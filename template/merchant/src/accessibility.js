export const fieldNames = {
  methods: { fieldId(key) { return 'marketplace-merchant-' + this._uid + '-' + key; } },
};
function applyControlLabel(element, binding) {
  const value = typeof binding.value === 'string' ? { label: binding.value } : binding.value;
  if (!value || typeof value.label !== 'string' || !value.label) throw new Error('表单控件名称不能为空');
  const inputs = Array.from(element.querySelectorAll('input:not([type="hidden"]), textarea, select'));
  if (element.matches('input, textarea, select')) inputs.unshift(element);
  inputs.forEach((input, index) => {
    input.setAttribute('aria-label', value.label);
    if (value.id) input.id = index === 0 ? value.id : value.id + '-input-' + index;
  });
  if (element.getAttribute('role') === 'switch') element.setAttribute('aria-label', value.label);
  [['.el-input-number__decrease', '减少'], ['.el-input-number__increase', '增加']].forEach(([selector, action]) => {
    const button = element.querySelector(selector);
    if (button) button.setAttribute('aria-label', value.label + ' ' + action);
  });
}
export const controlLabel = { inserted: applyControlLabel, componentUpdated: applyControlLabel };
