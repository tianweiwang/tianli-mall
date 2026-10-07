<template>
  <el-popover
    placement="bottom-end"
    trigger="click"
    width="190"
    popper-class="demo-navigator-menu"
  >
    <nav class="demo-role-menu" aria-label="演示角色导航">
      <button type="button" @click="navigate('customer')">
        <Smartphone :size="17" aria-hidden="true" />
        消费者端
      </button>
      <button type="button" @click="navigate('store')">
        <Store :size="17" aria-hidden="true" />
        门店端
      </button>
      <button type="button" @click="navigate('brand')">
        <Building2 :size="17" aria-hidden="true" />
        品牌方后台
      </button>
      <section v-if="customerAccounts.length > 1" class="demo-customer-switcher" aria-label="消费者账号">
        <span>消费者账号</span>
        <button
          v-for="customer in customerAccounts"
          :key="customer.id"
          type="button"
          :class="{ 'is-active': customer.id === state.activeCustomerId }"
          :aria-pressed="customer.id === state.activeCustomerId"
          @click="switchConsumer(customer.id)"
        >
          <UserRound :size="16" aria-hidden="true" />
          {{ customer.name }}
        </button>
      </section>
      <button type="button" class="demo-role-menu__reset" @click="resetDemo">
        <RotateCcw :size="17" aria-hidden="true" />
        重置演示数据
      </button>
    </nav>

    <el-tooltip slot="reference" content="演示导航" placement="left">
      <button
        type="button"
        class="demo-navigator"
        aria-label="打开演示导航"
      >
        <MoreHorizontal :size="20" aria-hidden="true" />
      </button>
    </el-tooltip>
  </el-popover>
</template>

<script>
import {
  Building2,
  MoreHorizontal,
  RotateCcw,
  Smartphone,
  Store,
  UserRound,
} from 'lucide-vue';
import { switchableCustomerAccounts } from '../../mock/selectors';
import { demoStore } from '../../mock/store';
import { switchCustomer } from '../../mock/transitions';

export default {
  name: 'DemoNavigator',
  components: {
    Building2,
    MoreHorizontal,
    RotateCcw,
    Smartphone,
    Store,
    UserRound,
  },
  computed: {
    state() {
      return demoStore.state.data;
    },
    customerAccounts() {
      return switchableCustomerAccounts(this.state);
    },
  },
  methods: {
    navigate(role) {
      const routes = {
        customer: `/c/store/${demoStore.state.data.activeStoreId}`,
        store: '/store/dashboard',
        brand: '/brand/dashboard',
      };
      demoStore.commit((draft) => {
        draft.activeRole = role;
      });
      if (this.$route.path !== routes[role]) {
        this.$router.push(routes[role]);
      }
    },
    switchConsumer(customerId) {
      demoStore.commit((draft) => switchCustomer(draft, customerId));
      const path = `/c/store/${demoStore.state.data.activeStoreId}/profile`;
      if (this.$route.path !== path) {
        this.$router.push(path);
      }
    },
    async resetDemo() {
      try {
        await this.$confirm(
          '重置后当前订单、预约和核销记录将恢复为初始演示数据。',
          '重置演示数据',
          {
            confirmButtonText: '确认重置',
            cancelButtonText: '取消',
            type: 'warning',
          },
        );
        demoStore.reset();
        if (this.$route.path !== '/c/store/TL1001') {
          await this.$router.push('/c/store/TL1001');
        }
        this.$message.success('演示数据已重置');
      } catch (_error) {
        // Cancel leaves the current demo state untouched.
      }
    },
  },
};
</script>
