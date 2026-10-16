<template>
	<view :style="colorStyle">
		<view v-if="marketplaceParentRequired && (!marketplaceCheckout || marketplaceNotice || marketplaceRevalidating)" class="marketplace-parent-notice" aria-live="polite">
			<view>{{marketplaceNotice || $t(`正在核实本人原确认内容，请稍候。`)}}</view>
			<button class="marketplace-return-action" @tap="returnToMarketplaceConfirmation">{{$t(`返回原确认页`)}}</button>
		</view>
		<view v-show="!marketplaceParentRequired || (marketplaceCheckout && !marketplaceRevalidating)" class='address-management' :class='addressList.length < 1 && page > 1 ? "fff":""'>
			<view class='line'>
				<image src='../../../static/images/line.jpg' v-if="addressList.length"></image>
			</view>
			<radio-group class="radio-group" @change="radioChange" v-if="addressList.length">
				<view class='item' v-for="(item,index) in addressList" :key="index">
					<view class='address' @click='goOrder(item.id)'>
						<view class='consignee'>{{$t(`收货人`)}}：{{item.real_name}}<text
								class='phone'>{{item.phone}}</text></view>
						<view>{{$t(`收货地址`)}}：{{item.province}}{{item.city}}{{item.district}}{{item.detail}}</view>
					</view>
					<view class='operation acea-row row-between-wrapper'>
						<!-- #ifndef MP -->
						<radio class="radio" :value="index.toString()" :checked="item.is_default ? true : false">
							<text>{{$t(`设为默认`)}}</text>
						</radio>
						<!-- #endif -->
						<!-- #ifdef MP -->
						<radio class="radio" :value="index" :checked="item.is_default ? true : false">
							<text>{{$t(`设为默认`)}}</text>
						</radio>
						<!-- #endif -->
						<view class='acea-row row-middle'>
							<view @click='editAddress(item.id)'><text class='iconfont icon-bianji'></text>{{$t(`编辑`)}}
							</view>
							<view @click='delAddress(index)'><text class='iconfont icon-shanchu'></text>{{$t(`删除`)}}
							</view>
						</view>
					</view>
				</view>
			</radio-group>
			<view class='loadingicon acea-row row-center-wrapper' v-if="addressList.length">
				<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
			</view>
			<view class='noCommodity' v-if="addressList.length < 1 && page > 1">
				<view class='pictrue'>
					<image :src="imgHost + '/statics/images/noAddress.png'"></image>
				</view>
			</view>
			<view style='height:120rpx;'></view>
			<view class='footer acea-row row-between-wrapper'>
				<!-- #ifdef APP-PLUS -->
				<view class='addressBnt on' @click='addAddress'><text
						class='iconfont icon-tianjiadizhi'></text>{{$t(`添加新地址`)}}</view>
				<!-- #endif -->
				<!-- #ifdef MP-->
				<view class='addressBnt wxbnt' @click='addAddress'><text
						class='iconfont icon-tianjiadizhi'></text>{{$t(`添加新地址`)}}</view>
				<view class='addressBnt' @click='getWxAddress'><text
						class='iconfont icon-weixin2'></text>{{$t(`导入微信地址`)}}
				</view>
				<!-- #endif -->
				<!-- #ifdef H5-->
				<view class='addressBnt' :class="this.$wechat.isWeixin()?'wxbnt':'on'" @click='addAddress'><text
						class='iconfont icon-tianjiadizhi'></text>{{$t(`添加新地址`)}}</view>
				<view class=""></view>
				<view class='addressBnt' @click='getAddress' v-if="this.$wechat.isWeixin()"><text
						class='iconfont icon-weixin2'></text>{{$t(`导入微信地址`)}}</view>
				<!-- #endif -->
			</view>
		</view>
		<!-- #ifdef MP -->
		<!-- <authorize @onLoadFun="onLoadFun" :isAuto="isAuto" :isShowAuth="isShowAuth" @authColse="authColse"></authorize> -->
		<!-- #endif -->
		<!-- #ifndef MP -->
		<home></home>
		<!-- #endif -->
	</view>
</template>

<script>
	import {
		getAddressList,
		setAddressDefault,
		delAddress,
		editAddress,
		postAddress,
		getAddressDetail
	} from '@/api/user.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	// #ifdef MP
	import authorize from '@/components/Authorize';
	// #endif
	import home from '@/components/home';
	import colors from '@/mixins/color.js';
	import {orderConfirmation, orderConfirmationRestore} from '@/api/order.js';
	import {prepareMarketplaceCheckoutChild, returnMarketplaceCheckoutSelection, marketplaceChildCurrent, marketplaceChildLifetimeCurrent, marketplaceChildGuard, hideMarketplaceCheckoutChild, revalidateMarketplaceCheckoutChild,
		marketplaceConfirmationUrl} from '@/utils/marketplace-confirmation-draft.js';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		components: {
			// #ifdef MP
			authorize,
			// #endif
			home
		},
		mixins: [colors],
		data() {
			return {
				marketplaceCheckout: null,
				marketplaceNotice: '',
				marketplaceRevalidating: false,
				marketplaceReturning: false,
				marketplaceOrderKey: '',
				marketplaceParentRequired: false,
				marketplaceChannelUnavailable: false,
				marketplacePendingAddress: null,
				imgHost: HTTP_REQUEST_URL,
				addressList: [],
				cartId: '',
				pinkId: 0,
				couponId: 0,
				loading: false,
				loadend: false,
				loadTitle: this.$t(`加载更多`),
				page: 1,
				limit: 20,
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				news: '',
				noCoupon: 0,
				is_gift: 0,
				order_id: 0
			};
		},
		computed: mapGetters(['isLogin']),
		onLoad(options) {
			this.marketplaceParentRequired = typeof options.orderKey === 'string' && options.orderKey !== '';
			if (this.marketplaceParentRequired) {
				this.marketplaceOrderKey = options.orderKey;
				let childChannel = null;
				try { childChannel = this.getOpenerEventChannel(); } catch (error) { /* Same-key recovery does not need an opener. */ }
				prepareMarketplaceCheckoutChild(this, options, childChannel, {lookup: orderConfirmation, restore: orderConfirmationRestore}).then(ready => {
					if (ready && this._marketplaceChildEpoch === 0 && marketplaceChildCurrent(this)) { this.news = this.marketplaceCheckout.source_kind === 'temporary' ? 1 : 0; this.getAddressList(true); }
				}).catch(error => { if (this._marketplaceChildEpoch === 0 && marketplaceChildLifetimeCurrent(this)) this.marketplaceNotice = error.message || this.$t(`原确认暂不能恢复，请查询原订单。`); });
				return;
			}
			let channel = null;
			try {
				if (typeof this.getOpenerEventChannel === 'function') channel = this.getOpenerEventChannel();
			} catch (error) {
				this.marketplaceChannelUnavailable = true;
			}
			if (channel && typeof channel.on === 'function' && typeof channel.emit === 'function') {
				channel.on('marketplaceCheckoutContext', (context) => {
					if (context && context.version === 1 && context.mer_id && typeof context.orderKey === 'string' &&
						context.orderKey && typeof context.cartId === 'string' && context.cartId &&
						['persistent', 'temporary'].includes(context.source_kind) &&
						(!this.marketplaceParentRequired || (context.orderKey === options.orderKey && String(context.mer_id) === String(options.mer_id)))) {
						this.marketplaceCheckout = context;
						this.news = context.source_kind === 'temporary' ? 1 : 0;
						this._marketplaceCheckoutChannel = channel;
					}
				});
			} else {
				this.marketplaceChannelUnavailable = true;
			}
			if (this.isLogin) {
				this.cartId = options.cartId || '';
				this.noCoupon = options.noCoupon || 0;
				this.pinkId = options.pinkId || 0;
				this.couponId = options.couponId || 0;
				this.news = this.marketplaceCheckout ? (this.marketplaceCheckout.source_kind === 'temporary' ? 1 : 0) : options.news || 0;
				this.orderId = options.order_id || 0;
				this.is_gift = options.is_gift || 0;
				this.getAddressList(true);
			} else {
				toLogin();
			}
		},
		onShow: function() {
			if (this.marketplaceParentRequired) {
				if (!this._marketplaceDidShow) { this._marketplaceDidShow = true; return; }
				const task = revalidateMarketplaceCheckoutChild(this, {lookup: orderConfirmation}), epoch = this._marketplaceChildEpoch;
				return task.then(ready => {
					if (!ready || this._marketplaceChildEpoch !== epoch || !marketplaceChildCurrent(this)) return;
					if (this.marketplacePendingAddress) {
						const value = this.marketplacePendingAddress; this.marketplacePendingAddress = null;
						return this.returnMarketplaceAddress(value.addressId);
					}
					this.loading = false; this.getAddressList(true);
				});
			}
			if (this.marketplacePendingAddress) {
				const value = this.marketplacePendingAddress;
				this.marketplacePendingAddress = null;
				return this.returnMarketplaceAddress(value.addressId);
			}
			let that = this;
			that.getAddressList(true);
		},
		onHide() { hideMarketplaceCheckoutChild(this); },
		onUnload() { this._marketplaceAlive = false; },
		watch: {
			'$store.state.app.authRevision'() {
				if (!this.marketplaceParentRequired) return;
				this._marketplaceAlive = false; this.marketplaceCheckout = null; this.addressList = [];
				this.marketplaceNotice = this.$t(`登录身份已变化，请返回原确认页查询。`);
			}
		},
		methods: {
			returnToMarketplaceConfirmation() { uni.redirectTo({url: marketplaceConfirmationUrl(this.marketplaceOrderKey)}); },
			returnMarketplaceAddress(addressId) {
				if (!marketplaceChildCurrent(this) || this.marketplaceReturning) return;
				const current = marketplaceChildGuard(this, 'return-address-result');
				this.marketplaceReturning = true;
				return returnMarketplaceCheckoutSelection(this, 'address', addressId, {lookup: orderConfirmation, detail: getAddressDetail}).catch(error => {
					if (current()) this.marketplaceNotice = error.message || this.$t(`选择尚未保存到原确认页，请返回查询。`);
				}).finally(() => { if (current()) this.marketplaceReturning = false; });
			},
			openMarketplaceAddressForm(id) {
				if (!marketplaceChildCurrent(this) || this.marketplaceReturning) return;
				const original = this.marketplaceCheckout, context = {...original, parentInstance: this._marketplaceInstance};
				const related = () => marketplaceChildLifetimeCurrent(this) && this.marketplaceCheckout === original &&
					this._marketplaceInstance === context.parentInstance && original.ownerUid === context.ownerUid && original.authRevision === context.authRevision &&
					original.orderKey === context.orderKey && original.generation === context.generation && String(original.mer_id) === String(context.mer_id);
				uni.navigateTo({
					url: '/pages/users/user_address/index?orderKey=' + encodeURIComponent(context.orderKey) +
						'&mer_id=' + encodeURIComponent(context.mer_id) + (id ? '&id=' + encodeURIComponent(id) : ''),
					events: {marketplaceAddressSelected: (value) => {
						if (related() && value && value.generation === context.generation && value.ownerUid === context.ownerUid && value.authRevision === context.authRevision &&
							value.parentInstance === context.parentInstance && value.orderKey === context.orderKey && String(value.mer_id) === String(context.mer_id)) {
							this.marketplacePendingAddress = value;
						}
					}},
					success: (res) => {
						if (!related() || !res.eventChannel || typeof res.eventChannel.emit !== 'function') {
							return;
						}
						res.eventChannel.emit('marketplaceCheckoutContext', context);
					}
				});
			},
			onLoadFun: function() {
				this.getAddressList();
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			/*
			 * 导入微信地址（小程序）
			 */
			getWxAddress: function() {
				let that = this;
				const current = marketplaceChildGuard(this, 'import-native');
				if (!current()) return;
				uni.authorize({
					scope: 'scope.address',
					success: function(res) {
						if (!current()) return;
						uni.chooseAddress({
							success: function(res) {
								if (!current()) return;
								let addressP = {};
								addressP.province = res.provinceName;
								addressP.city = res.cityName;
								addressP.district = res.countyName;
								
								editAddress({
									address: addressP,
									is_default: 1,
									real_name: res.userName,
									post_code: res.postalCode,
									phone: res.telNumber,
									detail: res.detailInfo,
									id: 0,
									type: 1
								}).then(res => {
									if (!current()) return;
									that.$util.Tips({
										title: that.$t(`添加成功`),
										icon: 'success'
									}, function() {
										if (!current()) return;
										that.getAddressList(true);
									});
								}).catch(err => {
									if (!current()) return;
									return that.$util.Tips({
										title: err
									});
								});
							},
							fail: function(err) {
								if (!current()) return;
								if (err.errMsg == 'chooseAddress:cancel') return that.$util
									.Tips({
										title: that.$t(`取消选择`)
									});
							},
						})
					},
					fail: function(res) {
						if (!current()) return;
						uni.showModal({
							title: that.$t(`您已拒绝导入微信地址权限`),
							content: that.$t(`是否进入权限管理，调整授权？`),
							success(res) {
								if (!current()) return;
								if (res.confirm) {
									uni.openSetting({
										success: function(res) {
											if (!current()) return;
										}
									});
								} else if (res.cancel) {
									return that.$util.Tips({
										title: that.$t(`已取消！`)
									});
								}
							}
						})
					}
				})
			},
			/*
			 * 导入微信地址（公众号）
			 */
			getAddress() {
				let that = this;
				const current = marketplaceChildGuard(this, 'import-wechat');
				if (!current()) return;
				that.$wechat.openAddress().then(userInfo => {
					if (!current()) return;
					// open();
					editAddress({
							real_name: userInfo.userName,
							phone: userInfo.telNumber,
							address: {
								province: userInfo.provinceName,
								city: userInfo.cityName,
								district: userInfo.countryName
							},
							detail: userInfo.detailInfo,
							post_code: userInfo.postalCode,
							is_default: 1,
							type: 1
						})
						.then(() => {
							if (!current()) return;
							that.$util.Tips({
								title: that.$t(`添加成功`),
								icon: 'success'
							}, function() {
								if (!current()) return;
								// close();
								that.getAddressList(true);
							});
						})
						.catch(err => {
							if (!current()) return;
							// close();
							return that.$util.Tips({
								title: err || that.$t(`添加失败`)
							});
						});
				});
			},
			/**
			 * 获取地址列表
			 * 
			 */
			getAddressList: function(isPage) {
				if (this.marketplaceParentRequired && !marketplaceChildCurrent(this)) return;
				let that = this;
				if (isPage) {
					that.loadend = false;
					that.page = 1;
					that.$set(that, 'addressList', []);
				};
				if (that.loading) return;
				if (that.loadend) return;
				const current = marketplaceChildGuard(this, 'address-list');
				if (!current()) return;
				that.loading = true;
				that.loadTitle = '';
				getAddressList({
					page: that.page,
					limit: that.limit
				}).then(res => {
					if (!current()) return;
					let list = res.data;
					let loadend = list.length < that.limit;
					that.addressList = that.$util.SplitArray(list, that.addressList);
					that.$set(that, 'addressList', that.addressList);
					that.loadend = loadend;
					that.loadTitle = loadend ? that.$t(`我也是有底线的`) : that.$t(`加载更多`);
					that.page = that.page + 1;
					that.loading = false;
				}).catch(err => {
					if (!current()) return;
					that.loading = false;
					that.loadTitle = that.$t(`加载更多`);
				});
			},
			/**
			 * 设置默认地址
			 */
			radioChange: function(e) {
				let index = parseInt(e.detail.value),
					that = this;
				const current = marketplaceChildGuard(this, 'address-default');
				if (!current()) return;
				let address = this.addressList[index];
				if (address == undefined) return that.$util.Tips({
					title: that.$t(`您设置的默认地址不存在!`)
				});
				setAddressDefault(address.id).then(res => {
					if (!current()) return;
					for (let i = 0, len = that.addressList.length; i < len; i++) {
						if (i == index) that.addressList[i].is_default = true;
						else that.addressList[i].is_default = false;
					}
					that.$util.Tips({
						title: that.$t(`设置成功`),
						icon: 'success'
					}, function() {
						if (!current()) return;
						that.$set(that, 'addressList', that.addressList);
					});
				}).catch(err => {
					if (!current()) return;
					return that.$util.Tips({
						title: err
					});
				});
			},
			/**
			 * 编辑地址
			 */
			editAddress: function(id) {
				if (this.marketplaceParentRequired && !this.marketplaceCheckout) return this.$util.Tips({title: this.$t(`原确认页连接不可用，请返回原确认页`)});
				if (this.marketplaceCheckout) return this.openMarketplaceAddressForm(id);
				let cartId = this.cartId,
					pinkId = this.pinkId,
					couponId = this.couponId;
				this.cartId = '';
				this.pinkId = '';
				this.couponId = '';
				uni.navigateTo({
					url: '/pages/users/user_address/index?id=' + id + '&cartId=' + cartId + '&pinkId=' +
						pinkId + '&couponId=' +
						couponId + '&new=' + this.news + '&is_gift=' + this.is_gift + '&order_id=' + this.orderId
				})
			},
			/**
			 * 删除地址
			 */
			delAddress: function(index) {
				let that = this,
					address = this.addressList[index];
				const current = marketplaceChildGuard(this, 'address-delete');
				if (!current()) return;
				if (address == undefined) return that.$util.Tips({
					title: that.$t(`您删除的地址不存在!`)
				});
				delAddress(address.id).then(res => {
					if (!current()) return;
					that.$util.Tips({
						title: that.$t(`删除成功`),
						icon: 'success'
					}, function() {
						if (!current()) return;
						that.addressList.splice(index, 1);
						that.$set(that, 'addressList', that.addressList);
					});
				}).catch(err => {
					if (!current()) return;
					return that.$util.Tips({
						title: err
					});
				});
			},
			/**
			 * 新增地址
			 */
			addAddress: function() {
				if (this.marketplaceParentRequired && !this.marketplaceCheckout) return this.$util.Tips({title: this.$t(`原确认页连接不可用，请返回原确认页`)});
				if (this.marketplaceCheckout) return this.openMarketplaceAddressForm();
				let cartId = this.cartId,
					pinkId = this.pinkId,
					couponId = this.couponId;
				this.cartId = '';
				this.pinkId = '';
				this.couponId = '';
				uni.navigateTo({
					url: '/pages/users/user_address/index?cartId=' + cartId + '&pinkId=' + pinkId +
						'&couponId=' + couponId + '&new=' + this.news + '&is_gift=' + this.is_gift + '&order_id=' + this.orderId
				})
			},
			goOrder: function(id) {
				if (this.marketplaceParentRequired && !this.marketplaceCheckout) return this.$util.Tips({title: this.$t(`原确认页连接不可用，请返回原确认页`)});
				if (this.marketplaceCheckout) return this.returnMarketplaceAddress(id);
				let cartId = '';
				let pinkId = '';
				let couponId = '';
				if (this.cartId && id) {
					cartId = this.cartId;
					pinkId = this.pinkId;
					couponId = this.couponId;
					this.cartId = '';
					this.pinkId = '';
					this.couponId = '';
					uni.redirectTo({
						url: '/pages/goods/order_confirm/index?is_address=1&new=' + this.news + '&cartId=' +
							cartId + '&addressId=' + id + '&pinkId=' +
							pinkId + '&couponId=' + couponId + '&noCoupon=' + this.noCoupon + '&is_gift=' + this.is_gift + '&order_id=' + this.orderId

					})
				}
			}
		},
		onReachBottom: function() {
			this.getAddressList();
		}
	}
</script>

<style>
	.address-management.fff {
		background-color: #fff;
		height: 1300rpx
	}

	.address-management .line {
		width: 100%;
		height: 3rpx;
	}

	.address-management .line image {
		width: 100%;
		height: 100%;
		display: block;
	}

	.address-management .item {
		background-color: #fff;
		padding: 0 30rpx;
		margin-bottom: 12rpx;
	}

	.address-management .item .address {
		padding: 30rpx 0;
		border-bottom: 1rpx solid #eee;
		font-size: 28rpx;
		color: #282828;
	}

	.address-management .item .address .consignee {
		font-size: 28rpx;
		font-weight: bold;
		margin-bottom: 8rpx;
	}

	.address-management .item .address .consignee .phone {
		margin-left: 25rpx;
	}

	.address-management .item .operation {
		height: 83rpx;
		font-size: 28rpx;
		color: #282828;
	}

	.address-management .item .operation .radio text {
		margin-left: 13rpx;
	}

	.address-management .item .operation .iconfont {
		color: #2c2c2c;
		font-size: 35rpx;
		vertical-align: -2rpx;
		margin-right: 10rpx;
	}

	.address-management .item .operation .iconfont.icon-shanchu {
		margin-left: 40rpx;
		font-size: 38rpx;
	}

	.address-management .footer {
		position: fixed;
		width: 100%;
		background-color: #fff;
		bottom: 0;
		height: 106rpx;
		padding: 0 30rpx;
		box-sizing: border-box;
	}

	.address-management .footer .addressBnt {
		width: 330rpx;
		height: 76rpx;
		border-radius: 50rpx;
		text-align: center;
		line-height: 76rpx;
		font-size: 30rpx;
		color: #fff;
		background-color: var(--view-theme);
	}

	.address-management .footer .addressBnt.on {
		width: 690rpx;
		margin: 0 auto;
	}

	.address-management .footer .addressBnt .iconfont {
		font-size: 35rpx;
		margin-right: 8rpx;
		vertical-align: -1rpx;
	}

	.address-management .footer .addressBnt.wxbnt {
		background-color: #FE960F;
	}
	.marketplace-parent-notice {
		overflow-wrap: anywhere;
		margin: 20rpx 30rpx;
		color: #868686;
		font-size: 28rpx;
		line-height: 1.5;
		text-align: left;
	}
	.marketplace-return-action {
		box-sizing: border-box;
		width: 100%;
		margin: 16rpx 0 0;
		padding: 12rpx 20rpx;
		min-height: 64rpx;
		display: flex;
		align-items: center;
		justify-content: center;
		text-align: center;
		font: inherit;
		line-height: 1.4;
		background: #fff;
		border: 1rpx solid #ddd;
	}
	.marketplace-return-action::after { border: 0; }
</style>
