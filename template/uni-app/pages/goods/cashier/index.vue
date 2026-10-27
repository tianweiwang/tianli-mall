<template>
	<view class="page" :class="{'marketplace-cashier': marketplaceV1}" v-if="payPriceShow || marketplaceV1">
		<view class="pay-price" v-if="payPriceShow">
			<view class="price">
				<text class="unit">{{$t(`￥`)}}</text>
				<text v-if="marketplaceV1" class="marketplace-test-amount">{{payPriceShow}}</text>
				<numberScroll v-else :num='payPriceShow' color="#E93323" width='30' height='50' fontSize='50'></numberScroll>
			</view>
			<view class="count-down" v-if="!marketplaceV1">
				{{$t(`支付剩余时间`)}}：
				<countDown :is-day="false" :tip-text="' '" :day-text="' '" :hour-text="' : '" :minute-text="' : '"
					:second-text="' '" :datatime="invalidTime"></countDown>
			</view>
		</view>
		<view class="payment">
			<view class="title">
				{{$t(`支付方式`)}}
			</view>
			<view v-if="marketplaceV1" class="marketplace-test-notice" aria-live="polite">
				<view>{{$t(`此页面仅提供内部测试支付，不扣真实资金。真实支付尚未开放。`)}}</view>
				<view v-if="testPaymentLoading">{{$t(`正在读取订单和支付方式…`)}}</view>
				<view v-else-if="!testPaymentAvailable && !testPaymentError">{{$t(`当前订单或环境未开放测试支付，请返回订单详情。`)}}</view>
				<view v-if="testPaymentError">{{testPaymentError}}</view>
			</view>
			<view class="item acea-row row-between-wrapper" v-for="(item,index) in cartArr" :key="index"
				v-show='item.payStatus' @click="payType(item.number || 0, item.value, index)">
				<view class="left acea-row row-between-wrapper">
					<view class="iconfont" :class="item.icon"></view>
					<view class="text">
						<view class="name">{{$t(item.name)}}</view>
						<view class="info" v-if="item.value == 'yue'">
							{{$t(item.title)}} <span class="money">{{$t(`￥`)}}{{ item.number }}</span>
						</view>
						<view class="info" v-else>{{$t(item.title)}}</view>
					</view>
				</view>
				<view class="iconfont" :class="active==index?'icon-xuanzhong11 font-num':'icon-weixuan'"></view>
			</view>
		</view>
		<view class="btn">
			<button v-if="marketplaceV1" class="button marketplace-test-button acea-row row-center-wrapper"
				:disabled="!testPaymentAvailable || !testPaymentSelected || testPaymentPending" @tap="goPay(number, paytype)">
				{{testPaymentPending ? $t(`正在确认测试支付`) : $t(`确认测试支付（不扣款）`)}}
			</button>
			<view v-else class="button acea-row row-center-wrapper" @click='goPay(number, paytype)'>{{$t(`确认支付`)}}</view>
			<view class="wait-pay" @click="waitPay">{{$t(`暂不支付`)}}</view>
		</view>
		<view v-show="false" v-html="formContent"></view>
	</view>
</template>

<script>
	import countDown from '@/components/countDown';
	import numberScroll from '@/components/numberScroll.vue'
	import {
		getCashierOrder,
		orderPay,
		getSavedOrderFulfillment,
		getOrderTestPaymentOptions,
		payOrderWithTestDriver
	} from '@/api/order.js';
	import { readOrderFulfillment, savedFulfillmentMoney } from '@/utils/marketplace-fulfillment.js';
	import {
		basicConfig
	} from '@/api/public.js'
	export default {
		components: {
			countDown,
			numberScroll
		},
		data() {
			return {
				marketplaceV1: false,
				testPaymentLoading: false,
				testPaymentAvailable: false,
				testPaymentSelected: false,
				testPaymentPending: false,
				testPaymentError: '',
				testPaymentOrderPk: '',
				paytype: '',
				number: 0,
				checked: false,
				datatime: 1676344056,
				//支付方式
				cartArr: [{
						"name": this.$t(`微信支付`),
						"icon": "icon-weixin2",
						value: 'weixin',
						title: this.$t(`使用微信快捷支付`),
						payStatus: 1,
					},
					{
						"name": this.$t(`支付宝支付`),
						"icon": "icon-zhifubao",
						value: 'alipay',
						title: this.$t(`使用支付宝支付`),
						payStatus: 1,
					},
					{
						"name": this.$t(`余额支付`),
						"icon": "icon-yuezhifu",
						value: 'yue',
						title: this.$t(`可用余额`),
						payStatus: 1,
					},
					{
						"name": this.$t(`线下支付`),
						"icon": "icon-yuezhifu1",
						value: 'offline',
						title: this.$t(`使用线下付款`),
						payStatus: 2,
					}, {
						"name": this.$t(`好友代付`),
						"icon": "icon-haoyoudaizhifu",
						value: 'friend',
						title: this.$t(`找微信好友支付`),
						payStatus: 1,
					}
				],
				orderId: 0,
				fromType: '',
				active: 0,
				payPrice: 0,
				payPriceShow: 0,
				payPostage: 0,
				offlinePostage: false,
				invalidTime: 0,
				initIn: false,
				jumpData: {
					orderId: '',
					msg: ''
				},
				formContent: '',
				oid: 0,
				is_gift: 0
			}
		},
		watch: {
			testPaymentOwner() {
				if (!this.marketplaceV1) return;
				this.payPriceShow = 0;
				this.testPaymentLoading = false;
				this.testPaymentAvailable = false;
				this.testPaymentSelected = false;
				this.cartArr = [];
				this.testPaymentError = '登录状态已变化，请重新打开原订单';
			},
			cartArr: {
				handler(newV, oldValue) {
					let newPayList = [];
					newV.forEach((item, index) => {
						if (item.payStatus) {
							item.index = index;
							newPayList.push(item)
						}
					});
					this.$nextTick(e => {
						if (this.marketplaceV1 || !newPayList.length) {
							this.active = -1;
							this.paytype = '';
							return;
						}
						this.active = newPayList[0].index;
						this.paytype = newPayList[0].value;
					})

				},
				immediate: true,
				deep: true
			}
		},
		computed: {
			testPaymentOwner() { return String(this.$store.state.app.uid) + ':' + String(this.$store.state.app.authRevision); }
		},
		onLoad(options) {
			if (options.order_id) this.orderId = options.order_id
			if (options.from_type) this.fromType = options.from_type
			this.marketplaceV1 = options.marketplace === '1';
			if (this.marketplaceV1) this.loadMarketplacePayment();
			else this.getBasicConfig();
		},
		onShow() {
			if (typeof wx === 'undefined' || typeof wx.getEnterOptionsSync !== 'function') return;
			let options = wx.getEnterOptionsSync();
			if (options.scene == '1038' && options.referrerInfo.appId == 'wxef277996acc166c3' && this.initIn) {
				// 代表从收银台小程序返回
				let extraData = options.referrerInfo.extraData;
				this.initIn = false
				if (!extraData) {
					// "当前通过物理按键返回，未接收到返参，建议自行查询交易结果";
					this.$util.Tips({
						title: this.$t(`取消支付`)
					}, {
						tab: 5,
						url: `/pages/goods/order_pay_status/index?order_id=${this.orderId}&msg=${this.$t(`取消支付`)}&type=3&totalPrice=${this.payPriceShow}&status=2`
					});
				} else {
					if (extraData.code == 'success') {
						let url = `/pages/goods/order_pay_status/index?order_id=${this.orderId}&msg=${this.jumpData.msg}&type=3&totalPrice=${this.payPriceShow}`
						if(this.is_gift) url += '&is_gift=1'
						this.$util.Tips({
							title: this.$t(`支付成功`),
							icon: 'success'
						}, {
							tab: 5,
							url
						});
					} else if (extraData.code == 'cancel') {
						// "支付已取消";
						this.$util.Tips({
							title: this.$t(`取消支付`)
						}, {
							tab: 5,
							url: `/pages/goods/order_pay_status/index?order_id=${this.orderId}&msg=${this.$t(`取消支付`)}&type=3&totalPrice=${this.payPriceShow}&status=2`
						});
					} else {
						// "支付失败：" + extraData.errmsg;
						uni.reLaunch({
							url: `/pages/goods/order_pay_status/index?order_id=${this.orderId}&msg=${this.$t(`支付失败`)}&totalPrice=${this.payPriceShow}`
						})
					}
				}
			}
		},
		methods: {
			testPaymentIdentity() {
				return String(this.$store.state.app.uid) + ':' + String(this.$store.state.app.authRevision) + ':' + this.orderId;
			},
			loadMarketplacePayment() {
				const identity = this.testPaymentIdentity();
				this.testPaymentLoading = true;
				this.cartArr = [];
				this.testPaymentAvailable = false;
				this.testPaymentSelected = false;
				this.testPaymentError = '';
				uni.showLoading({title: this.$t(`正在读取订单`)});
				return Promise.all([getSavedOrderFulfillment(this.orderId, 1, 20), getOrderTestPaymentOptions(this.orderId)]).then(([saved, options]) => {
					if (identity !== this.testPaymentIdentity()) return;
					const view = readOrderFulfillment(saved.data, true), capability = options.data;
					if (view.kind !== 'root' || view.order.order_id !== this.orderId || !capability || capability.schema !== 1 ||
						capability.scope !== 'testing_payment_availability_v1' || capability.order_no !== this.orderId ||
						typeof capability.available !== 'boolean' || capability.real_payment_available !== false) throw new Error('测试支付资料不完整');
					this.payPriceShow = savedFulfillmentMoney(view.order.pay_price);
					this.testPaymentOrderPk = String(view.order.id);
					this.testPaymentAvailable = capability.available && [0, '0'].includes(view.order.paid) && [0, '0'].includes(view.order.is_cancel);
					if (this.testPaymentAvailable) this.cartArr = [{name: '测试支付', icon: 'icon-yuezhifu1', value: 'marketplace_test',
						title: '内部测试驱动，不扣真实资金', payStatus: 1}];
				}).catch(error => {
					if (identity === this.testPaymentIdentity()) this.testPaymentError = error.msg || error.message || String(error);
				}).finally(() => {
					if (identity === this.testPaymentIdentity()) this.testPaymentLoading = false;
					uni.hideLoading();
				});
			},
			payMarketplaceTest() {
				if (!this.testPaymentAvailable || !this.testPaymentSelected || this.testPaymentPending) return;
				const identity = this.testPaymentIdentity(), orderNo = this.orderId;
				const storageKey = 'marketplace-test-payment:v1:' + String(this.$store.state.app.uid) + ':' + orderNo;
				let paymentIdem;
				try {
					paymentIdem = uni.getStorageSync(storageKey);
					if (!paymentIdem) {
						paymentIdem = 'preview-payment:' + Date.now().toString(36) + ':' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
						uni.setStorageSync(storageKey, paymentIdem);
					}
					if (typeof paymentIdem !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/.test(paymentIdem) || uni.getStorageSync(storageKey) !== paymentIdem) throw new Error('测试支付请求未能保存，请保留原订单');
				} catch (error) { this.testPaymentError = error.message || '测试支付请求未能保存'; return; }
				this.testPaymentPending = true;
				this.testPaymentError = '';
				return payOrderWithTestDriver(orderNo, paymentIdem).then(response => {
					if (identity !== this.testPaymentIdentity()) return;
					const result = response.data;
					if (!result || result.schema !== 1 || result.scope !== 'testing_persisted_payment_v1' || result.order_no !== orderNo ||
						String(result.order_pk) !== this.testPaymentOrderPk || result.state !== 'applied') throw new Error('测试支付结果待确认，请查看原订单');
					uni.redirectTo({url: '/pages/goods/order_details/index?order_id=' + encodeURIComponent(orderNo)});
				}).catch(error => {
					if (identity === this.testPaymentIdentity()) this.testPaymentError = error.msg || error.message || String(error);
				}).finally(() => { this.testPaymentPending = false; });
			},
			getBasicConfig() {
				basicConfig().then(res => {
					//微信支付是否开启
					this.cartArr[0].payStatus = res.data.pay_weixin_open || 0
					//支付宝是否开启
					this.cartArr[1].payStatus = res.data.ali_pay_status || 0;
					//#ifdef MP
					this.cartArr[1].payStatus = 0;
					//#endif
					//余额支付是否开启
					this.cartArr[2].payStatus = res.data.yue_pay_status
					if (res.data.offline_pay_status) {
						this.cartArr[3].payStatus = 1
					} else {
						this.cartArr[3].payStatus = 0
					}
					//好友代付是否开启
					this.cartArr[4].payStatus = res.data.friend_pay_status || 0;
					this.getCashierOrder()
				}).catch(err => {
					uni.hideLoading();
					return this.$util.Tips({
						title: err
					})
				})
			},
			getCashierOrder() {
				uni.showLoading({
					title: this.$t(`创建订单中`)
				});
				getCashierOrder(this.orderId, this.fromType).then(res => {
					this.payPrice = this.payPriceShow = res.data.pay_price
					this.payPostage = res.data.pay_postage
					this.offlinePostage = res.data.offline_postage
					this.invalidTime = res.data.invalid_time
					this.cartArr[2].number = res.data.now_money;
					this.number = Number(res.data.now_money) || 0;
					this.oid = res.data.oid
					this.is_gift = res.data.is_gift
					uni.hideLoading();
				}).catch(err => {
					uni.hideLoading();
					return this.$util.Tips({
						title: err
					})
				})
			},
			payType(number, paytype, index) {
				if (this.marketplaceV1) {
					if (paytype !== 'marketplace_test' || !this.testPaymentAvailable || this.testPaymentPending) return;
					this.testPaymentSelected = true;
				}
				this.active = index;
				this.paytype = paytype;
				this.number = number;
				if (this.offlinePostage) {
					if (paytype == 'offline') {
						this.payPriceShow = this.$util.$h.Sub(this.payPrice, this.payPostage);
					} else {
						this.payPriceShow = this.payPrice;
					}

				}
			},
			formpost(url, postData) {
				let tempform = document.createElement("form");
				tempform.action = url;
				tempform.method = "post";
				tempform.target = "_self";
				tempform.style.display = "none";
				for (let x in postData) {
					let opt = document.createElement("input");
					opt.name = x;
					opt.value = postData[x];
					tempform.appendChild(opt);
				}
				document.body.appendChild(tempform);
				this.$nextTick(e => {
					tempform.submit();
				})
			},
			waitPay() {
				if (this.marketplaceV1) return uni.redirectTo({url: '/pages/goods/order_details/index?order_id=' + encodeURIComponent(this.orderId)});
				uni.reLaunch({
					url: '/pages/goods/order_pay_status/index?order_id=' + this.orderId + '&msg=取消支付&type=3' +
						'&status=2&totalPrice=' + this.payPriceShow
				})
			},
			goPay(number, paytype) {
				if (this.marketplaceV1) return this.payMarketplaceTest();
				let that = this;
				if (!that.orderId) return that.$util.Tips({
					title: that.$t(`请选择要支付的订单`)
				});
				if (paytype == 'yue' && parseFloat(number) < parseFloat(that.payPriceShow)) return that.$util.Tips({
					title: that.$t(`余额不足`)
				});
				uni.showLoading({
					title: that.$t(`支付中`)
				});
				if (paytype == 'friend' && that.orderId) {
					uni.hideLoading();
					return uni.navigateTo({
						url: '/pages/users/payment_on_behalf/index?oid=' + that.oid + '&spread=' +
							this.$store.state.app.uid,
						success: res => {},
						fail: () => {},
						complete: () => {}
					});
				}
				orderPay({
					uni: that.orderId,
					paytype: paytype,
					type: that.friendPay ? 1 : 0,
					// #ifdef H5
					quitUrl: location.port ? location.protocol + '//' + location.hostname + ':' + location
						.port +
						'/pages/goods/order_details/index?order_id=' + this.orderId : location.protocol +
						'//' + location.hostname +
						'/pages/goods/order_details/index?order_id=' + this.orderId
					// #endif
					// #ifdef APP-PLUS
					quitUrl: '/pages/goods/order_details/index?order_id=' + this.orderId
					// #endif
				}).then(res => {
					let goPage = '/pages/goods/order_pay_status/index?order_id=' + this.orderId + '&msg=' + res.msg + '&type=3' + '&totalPrice=' + this.payPriceShow
					if(this.is_gift) goPage += '&is_gift=1'
					let status = res.data.status,
						orderId = res.data.result.order_id,
						jsConfig = res.data.result.jsConfig,
						goPages = goPage,
						friendPay = '/pages/users/payment_on_behalf/index?order_id=' + this.orderId +
						'&spread=' +
						this
						.$store.state.app.uid
					switch (status) {
						case 'ORDER_EXIST':
						case 'EXTEND_ORDER':
							uni.hideLoading();
							return that.$util.Tips({
								title: res.msg
							}, {
								tab: 5,
								url: goPages
							});
						case 'ALLINPAY_PAY':
							uni.hideLoading();
							// #ifdef MP
							this.initIn = true
							wx.openEmbeddedMiniProgram({
								appId: 'wxef277996acc166c3',
								extraData: {
									cusid: jsConfig.cusid,
									appid: jsConfig.appid,
									version: jsConfig.version,
									trxamt: jsConfig.trxamt,
									reqsn: jsConfig.reqsn,
									notify_url: jsConfig.notify_url,
									body: jsConfig.body,
									remark: jsConfig.remark,
									validtime: jsConfig.validtime,
									randomstr: jsConfig.randomstr,
									paytype: jsConfig.paytype,
									sign: jsConfig.sign,
									signtype: jsConfig.signtype
								}
							})
							this.jumpData = {
								orderId: res.data.result.order_id,
								msg: res.msg,
							}
							// #endif
							// #ifdef APP-PLUS
							plus.runtime.openURL(jsConfig.payinfo);
							setTimeout(e => {
								uni.reLaunch({
									url: goPages
								})
							}, 1000)
							// #endif
							// #ifdef H5
							this.formpost(res.data.result.pay_url, jsConfig)
							// #endif
							break;
						case 'PAY_ERROR':
							uni.hideLoading();
							return that.$util.Tips({
								title: res.msg
							}, {
								tab: 5,
								url: goPages
							});
							break;
						case 'SUCCESS':
							uni.hideLoading();
							if (paytype !== 'friend') {
								return that.$util.Tips({
									title: res.msg,
									icon: 'success'
								}, {
									tab: 4,
									url: goPages
								});
							} else {
								return that.$util.Tips({
									title: res.msg,
									icon: 'success'
								}, {
									tab: 4,
									url: friendPay
								});
							}


							break;
						case 'WECHAT_PAY':
							that.toPay = true;
							// #ifdef MP
							/* that.toPay = true; */
							let mp_pay_name = ''
							if (uni.requestOrderPayment) {
								mp_pay_name = 'requestOrderPayment'
							} else {
								mp_pay_name = 'requestPayment'
							}
							uni[mp_pay_name]({
								timeStamp: jsConfig.timestamp,
								nonceStr: jsConfig.nonceStr,
								package: jsConfig.package,
								signType: jsConfig.signType,
								paySign: jsConfig.paySign,
								success: function(res) {
									uni.hideLoading();
									if (that.BargainId || that.combinationId || that.pinkId ||
										that
										.seckillId || that.discountId)
										return that.$util.Tips({
											title: that.$t(`支付成功`),
											icon: 'success'
										}, {
											tab: 4,
											url: goPages
										});
									return that.$util.Tips({
										title: that.$t(`支付成功`),
										icon: 'success'
									}, {
										tab: 5,
										url: goPages
									});
								},
								fail: function(e) {
									uni.hideLoading();
									return that.$util.Tips({
										title: that.$t(`取消支付`)
									}, {
										tab: 5,
										url: goPages + '&status=2'
									});
								},
								complete: function(e) {
									uni.hideLoading();
									//关闭当前页面跳转至订单状态
									if (res.errMsg == 'requestPayment:cancel' || e.errMsg ==
										'requestOrderPayment:cancel') return that.$util
										.Tips({
											title: that.$t(`取消支付`)
										}, {
											tab: 5,
											url: goPages + '&status=2'
										});
								},
							})
							// #endif
							// #ifdef H5
							this.$wechat.pay(res.data.result.jsConfig).then(res => {
								return that.$util.Tips({
									title: that.$t(`支付成功`),
									icon: 'success'
								}, {
									tab: 5,
									url: goPages
								});
							}).catch(res => {
								if (!this.$wechat.isWeixin()) {
									uni.redirectTo({
										url: goPages + '&msg=' + that.$t(`支付失败`) +
											'&status=2'
										// '&msg=支付失败&status=2'
									})
								}
								if (res.errMsg == 'chooseWXPay:cancel') return that.$util.Tips({
									title: that.$t(`取消支付`)
								}, {
									tab: 5,
									url: goPages + '&status=2'
								});
							})
							// #endif
							// #ifdef APP-PLUS
							uni.requestPayment({
								provider: 'wxpay',
								orderInfo: jsConfig,
								success: (e) => {
									let url = goPages;
									uni.showToast({
										title: that.$t(`支付成功`)
									})
									setTimeout(res => {
										uni.redirectTo({
											url: url
										})
									}, 2000)
								},
								fail: (e) => {
									let url = '/pages/goods/order_pay_status/index?order_id=' +
										orderId +
										'&msg=' + that.$t(`支付失败`);
									uni.showModal({
										content: that.$t(`支付失败`),
										showCancel: false,
										success: function(res) {
											if (res.confirm) {
												uni.redirectTo({
													url: url
												})
											} else if (res.cancel) {}
										}
									})
								},
								complete: () => {
									uni.hideLoading();
								},
							});
							// #endif
							break;
						case 'PAY_DEFICIENCY':
							uni.hideLoading();
							//余额不足
							return that.$util.Tips({
								title: res.msg
							}, {
								tab: 5,
								url: goPages + '&status=1'
							});
							break;

						case "WECHAT_H5_PAY":
							uni.hideLoading();
							that.$util.Tips({
								title: that.$t(`等待支付中`)
							}, {
								tab: 4,
								url: goPages + '&status=0'
							});
							setTimeout(() => {
								location.href = res.data.result.jsConfig.h5_url;
							}, 1500);
							break;

						case 'ALIPAY_PAY':
							//#ifdef H5
							uni.hideLoading();
							that.$util.Tips({
								title: that.$t(`等待支付中`)
							}, {
								tab: 4,
								url: goPages + '&status=0'
							});
							that.formContent = res.data.result.jsConfig;
							setTimeout(() => {
								document.getElementById('alipaysubmit').submit();
							}, 1500);
							//#endif
							// #ifdef MP
							uni.navigateTo({
								url: `/pages/users/alipay_invoke/index?id=${orderId}&link=${jsConfig.qrCode}`
							});
							// #endif
							// #ifdef APP-PLUS
							uni.requestPayment({
								provider: 'alipay',
								orderInfo: jsConfig,
								success: (e) => {
									uni.showToast({
										title: that.$t(`支付成功`)
									})
									let url = '/pages/goods/order_pay_status/index?order_id=' +
										orderId +
										'&msg=' + that.$t(`支付成功`);
									setTimeout(res => {
										uni.redirectTo({
											url: url
										})
									}, 2000)

								},
								fail: (e) => {
									let url = '/pages/goods/order_pay_status/index?order_id=' +
										orderId +
										'&msg=' + that.$t(`支付失败`);
									uni.showModal({
										content: that.$t(`支付失败`),
										showCancel: false,
										success: function(res) {
											if (res.confirm) {
												uni.redirectTo({
													url: url
												})
											} else if (res.cancel) {}
										}
									})
								},
								complete: () => {
									uni.hideLoading();
								},
							});
							// #endif
							break;
					}

				}).catch(err => {
					uni.hideLoading();
					return that.$util.Tips({
						title: err
					}, () => {
						that.$emit('onChangeFun', {
							action: 'pay_fail'
						});
					});
				})
			}
		}

	}
</script>

<style lang="scss" scoped>
	.page {
		.pay-price {
			display: flex;
			justify-content: center;
			flex-direction: column;
			align-items: center;
			padding: 50rpx 0 40rpx 0;

			.price {
				color: #E93323;
				margin-bottom: 20rpx;
				display: flex;
				align-items: flex-end;

				.unit {
					font-size: 34rpx;
					font-weight: 500;
					line-height: 41rpx;
				}

				.num {
					font-size: 50rpx;
					font-weight: 600;
				}
			}

			.count-down {
				display: flex;
				align-items: center;
				background-color: #fff;
				padding: 8rpx 28rpx;
				border-radius: 40rpx;
				font-size: 24rpx;
				color: #E93323;

				.time {
					margin-top: 0 !important;
				}

				::v-deep.red {
					margin: 0 !important;
				}
			}
		}

		.payment {
			width: 690rpx;
			border-radius: 14rpx 14rpx;
			background-color: #fff;
			z-index: 999;
			margin: 0 30rpx;

			.title {
				color: #666666;
				font-size: 26rpx;
				padding: 30rpx 0 0 30rpx;
			}

			.payMoney {
				font-size: 28rpx;
				color: #333333;
				text-align: center;
				margin-top: 50rpx;

				.font-color {
					margin-left: 10rpx;

					.money {
						font-size: 40rpx;
					}
				}
			}


		}

		.payment.on {
			transform: translate3d(0, 0, 0);
		}

		.icon-xuanzhong11 {
			color: #E93323 !important;
		}

		.payment .item {
			border-bottom: 1rpx solid #eee;
			height: 130rpx;
			margin-left: 30rpx;
			padding-right: 30rpx;
		}

		.payment .item:last-child {
			border-bottom: none;
		}

		.payment .item .left {
			flex: 1;
		}

		.payment .item .left .text {
			flex: 1;
		}

		.payment .item .left .text .name {
			font-size: 30rpx;
			color: #333;
		}

		.payment .item .left .text .info {
			font-size: 22rpx;
			color: #999;
		}

		.payment .item .left .text .info .money {
			color: #ff9900;
		}

		.payment .item .left .iconfont {
			font-size: 50rpx;
			color: #09bb07;
			margin-right: 28rpx;
		}

		.payment .item .left .iconfont.icon-zhifubao {
			color: #00aaea;
		}

		.payment .item .left .iconfont.icon-yuezhifu {
			color: #ff9900;
		}

		.payment .item .left .iconfont.icon-yuezhifu1 {
			color: #eb6623;
		}

		.payment .item .left .iconfont.icon-tonglianzhifu1 {
			color: #305fd8;
		}

		.payment .item .iconfont {
			font-size: 40rpx;
			color: #ccc;
		}

		.icon-haoyoudaizhifu {
			color: #F34C3E !important;
		}

		.btn {
			position: fixed;
			left: 30rpx;
			display: flex;
			flex-direction: column;
			align-items: center;
			bottom: 30rpx;
			bottom: calc(30rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
			bottom: calc(30rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		}

		.wait-pay {
			color: #aaa;
			font-size: 24rpx;
			padding-top: 20rpx;
		}

		.button {
			width: 690rpx;
			height: 90rpx;
			border-radius: 45rpx;
			color: #FFFFFF;
			background-color: #E93323;

		}

		.marketplace-test-amount { font-size: 50rpx; font-weight: 600; line-height: 1; }
		.marketplace-test-notice { padding: 24rpx 30rpx; color: #666; font-size: 26rpx; line-height: 1.6; overflow-wrap: anywhere; }
		.marketplace-test-button { box-sizing: border-box; min-width: 0; max-width: 100%; height: auto; min-height: 90rpx; margin: 0; padding: 16rpx 24rpx; font-size: 30rpx; line-height: 1.4; text-align: center; white-space: normal; }
		.marketplace-test-button::after { border: 0; }
		.marketplace-test-button[disabled] { background: #ddd; color: #777; }

		&.marketplace-cashier {
			box-sizing: border-box;
			width: 100%;
			max-width: 560px;
			margin: 0 auto;
			padding: 0 30rpx 120px;
			padding-bottom: calc(120px + constant(safe-area-inset-bottom));
			padding-bottom: calc(120px + env(safe-area-inset-bottom));

			.payment { width: 100%; margin: 0; }
			.payment .item { box-sizing: border-box; height: auto; min-height: 130rpx; padding-top: 24rpx; padding-bottom: 24rpx; }
			.payment .item .left, .payment .item .left .text { min-width: 0; }
			.payment .item .left .text { overflow-wrap: anywhere; }
			.payment .item > .iconfont { flex-shrink: 0; margin-left: 16rpx; }

			.btn { box-sizing: border-box; left: 0; right: 0; width: 100%; max-width: 560px; margin: 0 auto; padding: 0 30rpx; }
			.button { width: 100%; min-height: 44px; display: flex; align-items: center; justify-content: center; }
			.wait-pay { display: flex; align-items: center; justify-content: center; min-height: 44px; padding-top: 0; text-align: center; }
		}

	}
</style>
