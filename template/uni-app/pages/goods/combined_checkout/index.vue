<template>
	<view class="combined" :style="colorStyle">
		<view class="panel">
			<view class="heading">{{ order ? '跨店订单' : '跨店结算' }}</view>
			<view class="muted">统一付款，店铺分别发货、收货和售后</view>
			<view v-if="order && order.state !== 'quoted'" class="order-summary">
				<text>合单号 {{ order.order_no }}</text>
				<text class="font-color">{{ stateText }}</text>
			</view>
		</view>
		<view v-if="canSelectAddress" class="panel">
			<view class="heading">收货地址</view>
			<view v-if="address" class="address">
				<view>{{ address.real_name }} {{ address.phone }}</view>
				<view class="muted">{{ address.province }}{{ address.city }}{{ address.district }} {{ address.detail }}</view>
			</view>
			<view v-else-if="!busy" class="muted">请先添加收货地址</view>
			<picker v-if="addresses.length" :disabled="busy" :range="addressLabels" :value="addressIndex" @change="selectAddress">
				<view class="action secondary">选择收货地址</view>
			</picker>
			<navigator v-else-if="!busy && !error" class="action secondary" url="/pages/users/user_address/index">添加收货地址</navigator>
		</view>
		<view v-if="error" class="panel notice" role="alert">
			<view>{{ error }}</view>
			<button v-if="!busy && canRefresh" class="action secondary" @tap="refresh">重新加载</button>
		</view>
		<view v-if="busy" class="panel muted" role="status">正在处理，请稍候</view>
		<template v-if="order">
			<view v-for="store in order.stores" :key="store.mer_id" class="panel">
				<view class="order-summary">
					<view class="heading">{{ store.name }}</view>
					<text v-if="store.order_no" class="muted">{{ storeState(store) }}</text>
				</view>
				<view v-for="(item, index) in store.items" :key="index" class="product">
					<image v-if="item.image" class="product-image" :src="item.image" mode="aspectFill" />
					<view class="product-description">
						<view>{{ item.name }}</view>
						<view class="muted">{{ item.sku_name }}</view>
						<view class="order-summary">
							<text>¥{{ item.unit_price }}</text>
							<text class="muted">×{{ item.quantity }}</text>
						</view>
					</view>
				</view>
				<view class="order-summary subtotal">
					<text class="muted">商品 ¥{{ store.goods_amount }} · 运费 ¥{{ store.shipping_amount }}</text>
					<text>¥{{ store.amount }}</text>
				</view>
				<view v-if="Number(store.refunded_amount) > 0" class="muted">本店已退 ¥{{ store.refunded_amount }}</view>
				<navigator v-if="store.order_no && order.state === 'paid'" class="action secondary" :url="storeOrderUrl(store.order_no)">
					查看店铺订单
				</navigator>
			</view>
			<view class="panel">
				<view class="order-summary heading">
					<text>{{ order.state === 'paid' ? '合计已付' : '合计' }}</text>
					<text class="font-color">¥{{ order.state === 'paid' ? order.paid_amount : order.total_amount }}</text>
				</view>
				<view v-if="Number(order.refunded_amount) > 0" class="muted">合计已退 ¥{{ order.refunded_amount }}</view>
				<button v-if="order.state === 'quoted'" class="action primary bg-color" :disabled="busy" @tap="submitOrder">提交合单</button>
				<button v-else-if="order.state === 'pending'" class="action primary bg-color" :disabled="busy" @tap="payOrder">
					测试支付 ¥{{ order.total_amount }}
				</button>
				<button v-if="order.state === 'pending'" class="action secondary" :disabled="busy" @tap="cancelOrder">取消合单</button>
			</view>
		</template>
	</view>
</template>

<script>
import { getAddressList } from '@/api/user.js';
import { cancelCombinedCheckout, confirmCombinedCheckout, createCombinedCheckout, getCombinedCheckout, payCombinedCheckout } from '@/api/order.js';
import { toLogin } from '@/libs/login.js';
import colors from '@/mixins/color.js';

const states = { quoted: '待提交', pending: '待支付', paid: '已支付', cancelled: '已取消' };
const storeStates = { 0: '待发货', 1: '待收货', 2: '已收货', 3: '已完成' };

export default {
	mixins: [colors],
	data() {
		return { groups: [], orderNo: '', order: null, addresses: [], addressIndex: 0, busy: false, error: '' };
	},
	computed: {
		address() { return this.addresses[this.addressIndex]; },
		addressLabels() {
			return this.addresses.map((address) =>
				address.real_name + ' ' + address.province + address.city + address.district + ' ' + address.detail
			);
		},
		canSelectAddress() { return this.groups.length > 1 && (!this.order || this.order.state === 'quoted'); },
		canRefresh() { return !!this.orderNo || this.groups.length > 1; },
		stateText() { return states[this.order.state] || ''; },
	},
	onLoad(options) {
		this.orderNo = options.order_no || '';
		if (!this.orderNo && options.groups) {
			try {
				const raw = options.groups;
				const groups = JSON.parse(raw.charAt(0) === '[' ? raw : decodeURIComponent(raw));
				if (Array.isArray(groups)) this.groups = groups;
			} catch (error) {
				this.error = '购物车选择已失效，请返回购物车重新选择商品';
			}
		}
		if (!this.canRefresh) this.error = '请从购物车选择至少两家店的商品';
	},
	onShow() {
		if (this.$store.getters.isLogin) this.refresh();
		else toLogin();
	},
	methods: {
		async refresh() {
			if (this.busy || !this.canRefresh) return;
			this.busy = true;
			this.error = '';
			try {
				if (this.orderNo) {
					const { data } = await getCombinedCheckout(this.orderNo);
					this.order = data;
				} else {
					const selectedId = this.address && this.address.id;
					const { data } = await getAddressList({ page: 1, limit: 100 });
					this.addresses = data;
					const selectedIndex = data.findIndex((address) => String(address.id) === String(selectedId));
					const defaultIndex = data.findIndex((address) => Number(address.is_default) === 1);
					this.addressIndex = selectedIndex >= 0 ? selectedIndex : Math.max(defaultIndex, 0);
					if (this.address) await this.quote();
				}
			} catch (error) {
				this.showError(error, '暂时无法加载，请重试');
			} finally {
				this.busy = false;
			}
		},
		async selectAddress(event) {
			if (this.busy) return;
			this.addressIndex = Number(event.detail.value);
			this.busy = true;
			this.error = '';
			try {
				await this.quote();
			} catch (error) {
				this.showError(error, '当前地址暂时无法结算，请重试');
			} finally {
				this.busy = false;
			}
		},
		async quote() {
			this.order = null;
			const { data } = await confirmCombinedCheckout({ address_id: Number(this.address.id), groups: this.groups });
			this.order = data;
		},
		submitOrder() { return this.commit(createCombinedCheckout, 'quoted', '提交结果待确认，请重试'); },
		payOrder() { return this.commit(payCombinedCheckout, 'pending', '支付结果待确认，请重试'); },
		cancelOrder() {
			uni.showModal({
				title: '取消跨店订单',
				content: '取消后将释放各店铺为本单预留的库存。',
				confirmText: '确认取消',
				success: (result) => {
					if (result.confirm) this.commit(cancelCombinedCheckout, 'pending', '取消结果待确认，请重试');
				},
			});
		},
		async commit(action, startingState, errorMessage) {
			if (this.busy || !this.order || this.order.state !== startingState) return;
			const number = this.order.order_no;
			this.busy = true;
			this.error = '';
			// Keep the same order number and idempotency key across network retries.
			this.orderNo = number;
			try {
				const { data } = await action(number);
				this.order = data;
			} catch (error) {
				try {
					const { data } = await getCombinedCheckout(number);
					this.order = data;
					if (data.state === startingState) this.showError(error, errorMessage);
				} catch (refreshError) {
					this.showError(error, errorMessage);
				}
			} finally {
				this.busy = false;
			}
			if (startingState === 'quoted' && this.order.state === 'pending') {
				uni.redirectTo({ url: '/pages/goods/combined_checkout/index?order_no=' + encodeURIComponent(number) });
			}
		},
		showError(error, fallback) {
			this.error = (typeof error === 'string' ? error : error && error.msg) || fallback;
		},
		storeOrderUrl(orderNo) {
			return '/pages/goods/order_details/index?order_id=' + encodeURIComponent(orderNo);
		},
		storeState(store) {
			if (store.cancelled) return '已取消';
			if (Number(store.refunded_amount) >= Number(store.amount) && Number(store.refunded_amount) > 0) return '已退款';
			return store.paid ? storeStates[store.status] || '已支付' : '待支付';
		},
	},
};
</script>

<style scoped>
.combined { box-sizing: border-box; max-width: 850rpx; min-height: 100vh; margin: 0 auto; padding: 24rpx; background: #f5f5f5; color: #282828; }
.panel { margin-bottom: 20rpx; padding: 24rpx; border-radius: 16rpx; background: #fff; font-size: 28rpx; line-height: 1.5; }
.heading { margin-bottom: 8rpx; font-size: 32rpx; font-weight: 600; overflow-wrap: anywhere; }
.muted { color: #666; font-size: 25rpx; overflow-wrap: anywhere; }
.address { margin-bottom: 12rpx; }
.order-summary { display: flex; align-items: center; justify-content: space-between; gap: 12rpx; overflow-wrap: anywhere; }
.order-summary > text:last-child { flex-shrink: 0; }
.product { display: flex; gap: 16rpx; margin-top: 20rpx; }
.product-image { width: 104rpx; height: 104rpx; flex: 0 0 104rpx; border-radius: 8rpx; background: #f5f5f5; }
.product-description { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.subtotal { margin-top: 16rpx; padding-top: 12rpx; border-top: 1rpx solid #eee; }
.action { box-sizing: border-box; display: flex; align-items: center; justify-content: center; width: 100%; min-height: 76rpx; margin: 16rpx 0 0; padding: 10rpx 20rpx; border-radius: 12rpx; font-size: 28rpx; line-height: 1.4; text-align: center; }
.action::after { border: 0; }
.secondary { border: 1rpx solid #a4a4a4; background: #fff; color: #282828; }
.primary { color: #fff; }
.notice { border-left: 6rpx solid #b44c31; color: #842d17; }
</style>
