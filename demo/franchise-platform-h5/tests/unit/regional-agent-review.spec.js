import { shallowMount } from '@vue/test-utils';
import { createRouter } from '../../src/router';
import { createSeed } from '../../src/mock/seed';
import { demoStore } from '../../src/mock/store';
import BrandAgents from '../../src/views/brand/Agents.vue';
import BrandAgentDetail from '../../src/views/brand/AgentDetail.vue';
import BrandDashboard from '../../src/views/brand/Dashboard.vue';
import BrandProfitSharing from '../../src/views/brand/ProfitSharing.vue';
import BrandProfitSharingDetail from '../../src/views/brand/ProfitSharingDetail.vue';
import BrandStoreDetail from '../../src/views/brand/StoreDetail.vue';
import StoreSettlements from '../../src/views/store/Settlements.vue';

const elementStubs = {
  AdminMetricStrip: true,
  AdminStatusTag: true,
  'el-button': true,
  'el-drawer': { template: '<div><slot /></div>' },
  'el-input': true,
  'el-option': true,
  'el-select': true,
  'el-table': { props: ['data'], template: '<div><slot /></div>' },
  'el-table-column': { template: '<div><slot :row="$parent.data[0]" /></div>' },
};

function mountPage(component, options = {}) {
  return shallowMount(component, { stubs: elementStubs, ...options });
}

describe('regional agent review regressions', () => {
  beforeEach(() => demoStore.reset());

  it('keeps seed service settlements as independent snapshots matching their sharing records', () => {
    const state = createSeed();

    ['MCO202607301002', 'MCO202607301003'].forEach((id) => {
      const settlement = state.memberConsumptions.find((item) => item.id === id);
      const sharing = state.profitSharingRecords.find((item) => item.businessId === id);

      expect(settlement.regionalSettlement).toEqual(sharing.settlementSnapshot);
      expect(settlement.regionalSettlement).not.toBe(sharing.settlementSnapshot);
    });
  });

  it('renders a three-party service settlement and retains the two-party goods settlement', async () => {
    const wrapper = mountPage(StoreSettlements);
    const serviceSharing = wrapper.vm.records.find((item) => item.businessType === 'service-settlement');
    const goodsSharing = wrapper.vm.records.find((item) => item.businessType !== 'service-settlement');

    await wrapper.setData({ keyword: serviceSharing.businessId });
    expect(wrapper.vm.visibleRecords.map((item) => item.id)).toEqual([serviceSharing.id]);
    expect(wrapper.vm.metrics[0].label).toBe('累计结算基数');
    await wrapper.setData({ keyword: '' });

    wrapper.vm.openDetail(serviceSharing);
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain('业务单号');
    expect(wrapper.text()).toContain('MCO202607301002');
    expect(wrapper.text()).toContain('门店收入');
    expect(wrapper.text()).toContain('集团收入');
    expect(wrapper.text()).toContain('区域代理收益');
    expect(wrapper.text()).toContain('¥164.34 + ¥115.04 + ¥49.30 = ¥328.68');

    wrapper.vm.openDetail(goodsSharing);
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain('品牌抽成');
    expect(wrapper.text()).not.toContain('区域代理收益');
    expect(wrapper.text()).toContain('¥398.40 + ¥99.60 = ¥498.00');
  });

  it('uses the settlement snapshot group rate in the detail simulation prompt', async () => {
    const router = createRouter();
    await router.push('/brand/sharing/PS202607303006');
    const agentDetail = mountPage(BrandProfitSharingDetail, { router });

    expect(agentDetail.text()).toContain('集团35%进入公共补贴资金池');

    demoStore.commit((draft) => {
      draft.profitSharingRecords.unshift({
        id: 'PS-DIRECT-SETTLEMENT',
        orderId: null,
        businessType: 'service-settlement',
        businessId: 'MCO-DIRECT-001',
        storeId: 'TL1002',
        regionalAgentId: null,
        regionalAgentName: '集团直营',
        paidCents: 13200,
        storeIncomeCents: 6600,
        brandCommissionCents: 6600,
        regionalAgentIncomeCents: 0,
        distributionCostCents: 0,
        settlementSnapshot: {
          applicable: true,
          cashSettlement: true,
          serviceId: 'SV1007',
          serviceName: '颈椎矫正',
          serviceSeries: '特色系列',
          storeId: 'TL1002',
          regionalAgentId: null,
          regionalAgentName: '集团直营',
          storeRate: 50,
          groupRate: 50,
          regionalAgentRate: 0,
          settlementCents: 13200,
          storeIncomeCents: 6600,
          groupIncomeCents: 6600,
          regionalAgentIncomeCents: 0,
        },
        status: 'pending',
        timeline: [{ status: 'pending', at: draft.demoNow }],
      });
    });
    await router.push('/brand/sharing/PS-DIRECT-SETTLEMENT');
    const directDetail = mountPage(BrandProfitSharingDetail, { router });

    expect(directDetail.text()).toContain('集团50%进入公共补贴资金池');
  });

  it('labels the list simulation, failed status, and distinct store policy rules', async () => {
    const sharingList = mountPage(BrandProfitSharing);
    const dashboard = mountPage(BrandDashboard);
    const router = createRouter();
    await router.push('/brand/stores/TL1001');
    const storeDetail = mountPage(BrandStoreDetail, { router });

    expect(sharingList.text()).toContain('微信获批最大分账比例');
    expect(dashboard.vm.sharingStatus('failed')).toBe('分账失败');
    expect(storeDetail.text()).toContain('商品订单协议比例');
    expect(storeDetail.text()).toContain('特色/经典项目结算模板');
  });

  it('keeps agent list, store detail, and sharing detail drilldown routes connected', async () => {
    const router = createRouter();
    await router.push('/brand/agents/RA1001');
    const agents = mountPage(BrandAgents, { router });
    const detail = mountPage(BrandAgentDetail, { router });

    expect(agents.vm.visibleSummaries.find((item) => item.agent.id === 'RA1001').stores[0].id).toBe('TL1001');
    expect(detail.vm.overview.stores.map((item) => item.id)).toContain('TL1001');
    expect(detail.vm.overview.records.map((item) => item.id)).toContain('PS202607303006');
    expect(router.match('/brand/stores/TL1001').name).toBe('brand-store-detail');
    expect(router.match('/brand/sharing/PS202607303006').name).toBe('brand-sharing-detail');
  });
});
