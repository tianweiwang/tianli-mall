import imgSelectModal from './imgModal.vue';

const imgModal = {};

imgModal.install = function (Vue) {
  const ModalConstructor = Vue.extend(imgSelectModal);
  const instance = new ModalConstructor();
  instance.$mount(document.createElement('div'));
  document.body.appendChild(instance.$el);
  let invocation = 0;
  Vue.prototype.$imgModal = function (callback, isMore, modelName, boolean, productId = null) {
    const current = ++invocation;
    instance.visible = false;
    instance.resetScope();
    instance.$nextTick(() => {
      if (current !== invocation) return;
      instance.productId = productId;
      instance.callback = value => { if (current === invocation) callback(value); };
      instance.more = isMore;
      instance.modelName = modelName;
      instance.booleanVal = boolean;
      instance.visible = true;
    });
  };
};

export default imgModal;
