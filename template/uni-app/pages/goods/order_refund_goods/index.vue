<template>
	<view :style="colorStyle">
		<form @submit="subRefund">
			<view class='apply-return'>
				<view class='goodsStyle acea-row row-between' v-for="(item,index) in orderInfo.cart_info" :key="index">
					<view class='pictrue'>
						<image :src='item.productInfo.attrInfo?item.productInfo.attrInfo.image:item.productInfo.image'>
						</image>
					</view>
					<view class='text acea-row row-between'>
						<view class='name line2'>{{item.productInfo.store_name}}</view>
						<view class='money'>
							<view>{{$t(`￥`)}}{{item.truePrice }}</view>
							<view class='num'>x{{item.cart_num }}</view>
						</view>
					</view>
				</view>
				<view class='list'>
					<view class='item acea-row row-between-wrapper' v-if="expressList.length">
						<view>{{$t(`快递公司`)}}</view>
						<picker class='num' @change="bindPickerChange" :value="seIndex" :range="expressList"
							range-key="name">
							<view class="picker acea-row row-between-wrapper">
								<view class='reason'>{{expressList[seIndex].name}}</view>
								<text class='iconfont icon-jiantou'></text>
							</view>
						</picker>
					</view>
					<view class='item acea-row row-between-wrapper'>
						<view>{{$t(`快递单号`)}}</view>
						<input class="input" type="text" :placeholder="$t(`填写快递单号`)" placeholder-class='placeholder'
							v-model="refundInfo.refund_express" />
					</view>
					<view class='item acea-row row-between-wrapper'>
						<view>{{$t(`联系电话`)}}</view>
						<input class="input" type="number" :placeholder="$t(`请输入手机号`)" placeholder-class='placeholder'
							v-model="refundInfo.refund_phone" />
					</view>
					<view class='item textarea acea-row row-between'>
						<view>{{$t(`寄回备注`)}}</view>
						<textarea :placeholder='$t(`填写备注信息，100字以内`)' class='num'
							v-model="refundInfo.refund_shipping_note" maxlength="100"></textarea>
					</view>
                    <view v-if="orderInfo.refund_explain" class="item">申请说明：{{orderInfo.refund_explain}}</view>
                    <view v-if="legacyMaterialProblem" class="item">{{legacyMaterialProblem}}</view>
                    <view v-if="legacyMaterialsActive && legacyApplicationIds.length" class="item">
                      <view>申请材料</view><private-evidence :key="'return-application-' + legacyMaterialEpoch" :files="legacyApplicationIds" :reader="readLegacyMaterial"></private-evidence>
                    </view>
                    <view v-if="legacyMaterialsActive && legacyShippingIds.length" class="item">
                      <view>已保存寄回材料</view><private-evidence :key="'return-shipping-' + legacyMaterialEpoch" :files="legacyShippingIds" :reader="readLegacyMaterial"></private-evidence>
                    </view>
                    <view v-if="orderInfo.refund_img && orderInfo.refund_img.length" class="item">
                      <view>原有材料</view><view v-for="(url,index) in orderInfo.refund_img" :key="'legacy-url-' + index" class="pictrue"><image :src="url"></image></view>
                    </view>
					<view class='item acea-row row-between'>
						<view class='title acea-row row-between-wrapper'>
							<view>{{$t(`上传图片`)}}</view>
							<view class='tip'>{{$t(`最多可上传3张`)}}</view>
						</view>
						<view class='upload acea-row row-middle'>
							<view class='pictrue' v-for="(item,index) in refund_reason_wap_img" :key="index">
								<image v-if="legacyDraftPreviews[item]" :src="legacyDraftPreviews[item]"></image><text v-else>原图待读取</text>
								<view class='iconfont icon-guanbi1 font-num' @tap='DelPic(index)'></view>
							</view>
							<view class='pictrue acea-row row-center-wrapper row-column' @tap='uploadpic'
								v-if="refund_reason_wap_img.length < 3 && !legacyDraftUnknown && !legacyDraftConfirmed && !legacyDraftBlocked">
								<text class='iconfont icon-icon25201'></text>
								<view>{{$t(`上传图片`)}}</view>
							</view>
						</view>
					</view>
				</view>
				<view v-if="legacyDraftError">{{legacyDraftError}}</view>
				<view v-if="legacyDraftReceipt" role="status"><view v-for="(line,index) in legacyDraftReceiptLines" :key="'receipt-' + index">{{line}}</view></view>
			<button v-if="legacyDraftUnknown" class="returnBnt" :disabled="legacyDraftBusy" @tap="readLegacyDraftResult">读取已保存结果</button>
			<button v-else-if="legacyDraftMissingPreviews" class="returnBnt" :disabled="legacyDraftBusy" @tap="reloadLegacyDraftPreviews">重新读取图片</button>
			<button class='returnBnt bg-color' form-type="submit" :disabled="legacyDraftBusy || !!legacyDraftUnknown || legacyDraftConfirmed || legacyDraftBlocked">{{$t(`提交`)}}</button>
			</view>
		</form>
	</view>
</template>
<script>
import {legacyRefundDraft, legacyRefundImages} from '@/api/legacy-refund-materials.js';
import PrivateEvidence from '@/components/marketplaceAftersaleEvidence/index.vue';
	import {
		refundOrderDetail
	} from '@/api/order.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	import colors from '@/mixins/color.js';
	export default {
		components: {PrivateEvidence},
		mixins: [colors, legacyRefundDraft, legacyRefundImages],
		data() {
			return {
				expressList: [],
				orderInfo: {},
				seIndex: 0,
				refund_reason_wap_img: [],
				refundInfo: {
					refund_express: '',
					refund_phone: '',
					refund_shipping_note: '',
					id: '',
					refund_express_name: '',
					refund_img: ''
				}
			};
		},
		computed: { ...mapGetters(['isLogin']), legacyDraftKind() { return 'shipping'; }, legacyDraftId() { return this.refundInfo.id; }, legacyRefundReader() { return 'customer'; }, legacyRefundSource() { return this.orderInfo && this.orderInfo.schema !== 2 ? this.orderInfo : null; }, legacyRefundVisible() { return this.legacyDraftActive; } },
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						this.getOrderInfo();
					}
				},
				deep: true
			}
		},
		onLoad(options) {
			this.orderId = options.orderId;
			if (this.isLogin) {
				this.getOrderInfo();
			} else {
				toLogin();
			}
		},
		methods: {
			/**
			 * 申请退货
			 */
			subRefund: function(e) {
				if(this.legacyDraftBusy || this.legacyDraftUnknown || this.legacyDraftConfirmed || this.legacyDraftBlocked)return;
				let that = this
				if (!that.refundInfo.refund_express) return this.$util.Tips({
					title: that.$t(`填写快递单号`)
				});
				if (!that.refundInfo.refund_phone) return this.$util.Tips({
					title: that.$t(`请输入手机号`)
				});
				if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.refundInfo.refund_phone)) return this.$util.Tips({
					title: that.$t(`请输入正确的手机号码`)
				});
				that.refundInfo.refund_express_name = that.expressList[that.seIndex].name;
				that.refundInfo.refund_img = that.refund_reason_wap_img.join(',');
				this.submitLegacyDraft({...that.refundInfo}).then(res => {
					return this.$util.Tips({
						title: res.msg,
						icon: 'success'
					}, {
						tab: 5,
						url: '/pages/users/user_return_list/index?isT=1'
					});
				}).catch(err => {
					return this.$util.Tips({
						title: err && err.message || err
					});
				})
			},
			/**
			 * 删除图片
			 * 
			 */
			DelPic: function(index) { this.removeLegacyDraft(index); },

			/**
			 * 上传文件
			 * 
			 */
			uploadpic: function() { return this.uploadLegacyDraft(); },

			/**
			 * 获取订单详情
			 * 
			 */
			getOrderInfo: function() {
				let that = this;
				refundOrderDetail(that.orderId).then(res => {
					that.$set(that, 'orderInfo', res.data);
					that.expressList = res.data.express_list;
					that.refundInfo.id = res.data.id;
					if(Number(res.data.refund_type)===5 && !that.legacyDraftUnknown){
						that.legacyDraftConfirmed=true;
						['refund_express','refund_phone','refund_express_name','refund_shipping_note'].forEach(key=>{that.refundInfo[key]=res.data[key]||'';});
					}
				});
			},
			bindPickerChange(e) {
				this.$set(this, 'seIndex', e.detail.value);
			}
		}
	}
</script>

<style scoped lang="scss">
	.apply-return .list {
		background-color: #fff;
		margin-top: 18rpx;
	}

	.apply-return .list .item {
		margin-left: 30rpx;
		padding-right: 30rpx;
		min-height: 90rpx;
		border-bottom: 1rpx solid #eee;
		font-size: 30rpx;
		color: #333;

		.input {
			text-align: right;
		}
	}

	.apply-return .list .item .num {
		color: #282828;
		width: 427rpx;
		text-align: right;
	}

	.apply-return .list .item .num .picker .reason {
		width: 385rpx;
	}

	.apply-return .list .item .num .picker .iconfont {
		color: #666;
		font-size: 30rpx;
		margin-top: 2rpx;
	}

	.apply-return .list .item.textarea {
		padding: 30rpx 30rpx 30rpx 0;
	}

	.apply-return .list .item textarea {
		height: 100rpx;
		font-size: 30rpx;
	}

	.apply-return .list .item .placeholder {
		color: #bbb;
		font-size: 30rpx;
		text-align: right;
	}

	.apply-return .list .item .title {
		height: 95rpx;
		width: 100%;
	}

	.apply-return .list .item .title .tip {
		font-size: 30rpx;
		color: #bbb;
	}

	.apply-return .list .item .upload {
		padding-bottom: 36rpx;
	}

	.apply-return .list .item .upload .pictrue {
		margin: 22rpx 23rpx 0 0;
		width: 156rpx;
		height: 156rpx;
		position: relative;
		font-size: 24rpx;
		color: #bbb;
	}

	.apply-return .list .item .upload .pictrue:nth-of-type(4n) {
		margin-right: 0;
	}

	.apply-return .list .item .upload .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 3rpx;
	}

	.apply-return .list .item .upload .pictrue .icon-guanbi1 {
		position: absolute;
		font-size: 45rpx;
		top: -10rpx;
		right: -10rpx;
	}

	.apply-return .list .item .upload .pictrue .icon-icon25201 {
		color: #bfbfbf;
		font-size: 50rpx;
	}

	.apply-return .list .item .upload .pictrue:nth-last-child(1) {
		border: 1rpx solid #ddd;
		box-sizing: border-box;
	}

	.apply-return .returnBnt {
		font-size: 32rpx;
		color: #fff;
		width: 690rpx;
		height: 86rpx;
		border-radius: 50rpx;
		text-align: center;
		line-height: 86rpx;
		margin: 43rpx auto;
	}

	.goodsStyle .text .name {
		align-self: flex-start;
	}

	.list ::v-deep .uni-input-input {
		text-align: right;
	}
</style>
