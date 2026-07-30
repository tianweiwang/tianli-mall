<template>
  <div class="ivu-global-footer i-copyright" v-if="isShow && copyright">
    <div class="ivu-global-footer-copyright">{{ copyright }}</div>
  </div>
</template>
<script>
import { getCrmebCopyRight } from '@/api/system';
import { Session } from '@/utils/storage.js';

export default {
  name: 'i-copyright',
  data() {
    return {
      copyright: '',
      isShow: false,
    };
  },
  created() {
    this.getVersion();
  },
  methods: {
    getVersion() {
      getCrmebCopyRight().then((res) => {
        this.copyright = res.data.copyrightContext;
        Session.set('copyright', res.data.copyrightContext);
        this.isShow = true;
      });
    },
  },
};
</script>
<style lang="scss">
.ivu-global-footer {
  /* margin: 48px 0 24px 0; */
  /* padding: 0 16px; */
  margin: 5px 0px;
  text-align: center;
  box-sizing: border-box;
}
.i-copyright {
  flex: 0 0 auto;
}
.ivu-global-footer-copyright {
  color: #808695;
  font-size: 14px;
}
</style>
