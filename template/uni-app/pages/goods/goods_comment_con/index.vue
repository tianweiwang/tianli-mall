<template>
	<view :style="colorStyle">
		<form @submit="formSubmit" report-submit='true'>
			<view class='evaluate-con'>
				<view class='goodsStyle acea-row row-between'>
					<view class='pictrue'>
						<image :src='productInfo.image'></image>
					</view>
					<view class='text acea-row row-between'>
						<view class='name line3'>{{productInfo.store_name}}</view>
						<view class='money'>
							<view>{{$t(`￥`)}}{{productInfo.attrInfo.price}}</view>
							<view class='num'>x{{cart_num}}</view>
						</view>
					</view>
				</view>
				<view class='score'>
					<view class='item acea-row row-middle' v-for="(item,indexw) in scoreList" :key="indexw">
						<view>{{item.name}}</view>
						<view class='starsList'>
							<text @click="stars(indexn, indexw)" v-for="(itemn, indexn) in item.stars" :key="indexn"
								class='iconfont'
								:class="item.index >= indexn? 'icon-shitixing font-num':'icon-kongxinxing'"></text>
						</view>
						<text class='evaluate'>{{item.index === -1 ? "" : item.index + 1 + $t(`星`)}}</text>
					</view>
					<view class='textarea'>
						<textarea v-model="commentDraft" :disabled="reviewLocked" :placeholder='$t(`商品满足你的期待么？说说你的想法，分享给想买的他们吧`)' name="comment"
							placeholder-class='placeholder'></textarea>
						<view class='list acea-row row-middle'>
							<view class='pictrue' v-for="(item,index) in pics" :key="index">
								<image :src='item'></image>
								<button class="review-remove" :disabled="reviewLocked" :aria-label="$t('删除图片') + (index + 1)" @click.stop="DelPic(index)"><text class="iconfont icon-guanbi1"></text></button>
							</view>
							<button class='pictrue review-upload' :disabled="reviewLocked" @click='uploadpic'
								v-if="pics.length < 8">
								<text class='iconfont icon-icon25201'></text>
								<view>{{ $t(uploadBusy ? '图片处理中' : '上传图片') }}</view>
							</button>
						</view>
					</view>
					<view v-if="reviewFeedback" class="review-feedback" role="status">{{ reviewFeedback }}</view>
					<button class='evaluateBnt bg-color' :disabled="reviewLocked" formType="submit">{{ $t(submitBusy ? '正在发布评论' : '立即评价') }}</button>
				</view>
			</view>
		</form>
		<!-- #ifdef MP -->
		<!-- <authorize @onLoadFun="onLoadFun" :isAuto="isAuto" :isShowAuth="isShowAuth" @authColse="authColse"></authorize> -->
		<!-- #endif -->
		<canvas canvas-id="canvas" v-if="canvasStatus"
			:style="{width: canvasWidth + 'px', height: canvasHeight + 'px',position: 'absolute',left:'-100000px',top:'-100000px'}"></canvas>
	</view>
</template>

<script>
	import {
		orderProduct
	} from '@/api/order.js';
	import request from '@/utils/request.js';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	// #ifdef MP
	import authorize from '@/components/Authorize';
	// #endif
	import colors from "@/mixins/color";
	export default {
		components: {
			// #ifdef MP
			authorize
			// #endif
		},
		mixins: [colors],
		data() {
			return {
				pics: [],
				reviewAssetIds: [],
				commentDraft: '',
				reviewPageActive: true,
				reviewGeneration: 0,
				reviewReadRevision: 0,
				reviewReady: false,
				reviewReadPending: false,
				uploadBusy: false,
				submitBusy: false,
				reviewBlocked: false,
				reviewSubmitted: false,
				reviewFeedback: '',
				reviewReceipt: null,
				scoreList: [{
						name: this.$t(`商品质量`),
						stars: ["", "", "", "", ""],
						index: -1
					},
					{
						name: this.$t(`服务态度`),
						stars: ["", "", "", "", ""],
						index: -1
					}
				],
				orderId: '',
				unique: '',
				productInfo: { attrInfo: {} },
				cart_num: 0,
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				canvasWidth: "",
				canvasHeight: "",
				canvasStatus: false
			};
		},
		computed: {
			...mapGetters(['isLogin']),
			reviewLocked() { return !this.isLogin || !this.reviewReady || this.uploadBusy || this.submitBusy || this.reviewBlocked || this.reviewSubmitted; }
		},
		watch: {
			'$store.state.app.authRevision'() {
				this.invalidateReview(true);
				if (this.isLogin && this.unique && this.reviewPageActive) this.getOrderProduct();
			},
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						this.getOrderProduct();
					}
				},
				deep: true
			}
		},
		onLoad(options) {
			if (!options.unique || !options.uni) return this.$util.Tips({
				title: this.$t(`缺少参数`)
			}, {
				tab: 3,
				url: 1
			});
			this.unique = options.unique;
			this.orderId = options.uni;
			if (this.isLogin) {
				this.getOrderProduct();
			} else {
				toLogin();
			}
		},
		onShow() {
			this.reviewPageActive = true;
			if (this.isLogin && this.unique && !this.reviewReady) this.getOrderProduct();
		},
		onHide() { this.reviewPageActive = false; this.invalidateReview(false); },
		onUnload() { this.reviewPageActive = false; this.invalidateReview(false); },
		methods: {
			reviewScope() {
				const state = this.$store.state.app;
				return { token: state.token, revision: state.authRevision, uid: state.verifiedUid, unique: this.unique, generation: this.reviewGeneration };
			},
			reviewCurrent(scope) {
				const state = this.$store.state.app;
				return this.reviewPageActive && this.isLogin && !!scope.token && scope.token === state.token &&
					scope.revision === state.authRevision && (!scope.uid || scope.uid === state.verifiedUid) &&
					scope.unique === this.unique && scope.generation === this.reviewGeneration;
			},
			invalidateReview(clear) {
				const pending = !!this._reviewUploadTask || this.submitBusy;
				this.reviewGeneration++; this.reviewReadRevision++; this.reviewReady = false; this.reviewReadPending = false;
				const task = this._reviewUploadTask; this._reviewUploadTask = null;
				if (task && typeof task.abort === 'function') task.abort();
				if (this._reviewNavigationTimer) clearTimeout(this._reviewNavigationTimer);
				this.uploadBusy = false; this.submitBusy = false; this.canvasStatus = false;
				if (clear) {
					this.pics = []; this.reviewAssetIds = []; this.commentDraft = ''; this.productInfo = { attrInfo: {} }; this.cart_num = 0;
					this.scoreList.forEach(item => { item.index = -1; });
					this.reviewBlocked = false; this.reviewSubmitted = false; this.reviewReceipt = null; this.reviewFeedback = '';
				} else if (pending) {
					this.reviewBlocked = true;
					this.reviewFeedback = this.$t('本次操作结果尚未确认，草稿已保留，请先查看订单评价记录，勿重复提交');
				}
			},
			readReviewReceipt(response) {
				const data = response && response.data;
				const keys = ['cleanup_complete', 'complete', 'database_committed', 'phase', 'physical_changed', 'physical_complete'];
				if (!data || typeof data !== 'object' || Array.isArray(data) || Object.keys(data).sort().join('|') !== keys.join('|') ||
					typeof data.complete !== 'boolean' || typeof data.physical_complete !== 'boolean' ||
					typeof data.phase !== 'string' || !/^[a-z][a-z0-9_:-]{0,63}$/.test(data.phase) ||
					![true, false, null].includes(data.physical_changed) || ![true, false, null].includes(data.database_committed) ||
					![true, false, null].includes(data.cleanup_complete)) return null;
				return keys.reduce((receipt, key) => { receipt[key] = data[key]; return receipt; }, {});
			},
			reviewFailure(response, upload) {
				const receipt = this.readReviewReceipt(response); this.reviewReceipt = receipt;
				this.reviewBlocked = !receipt || receipt.complete !== false || receipt.database_committed !== false ||
					receipt.physical_changed === null || receipt.cleanup_complete === false ||
					(receipt.physical_changed === true && receipt.cleanup_complete !== true);
				this.reviewFeedback = this.$t(this.reviewBlocked ?
					(receipt && receipt.database_committed === true ? '本次资料已写入，但结果尚未完整确认，请保留草稿并先核实，勿重复操作' :
					'本次操作结果尚未确认，草稿已保留，请先查看订单评价记录，勿重复操作') :
					(upload ? '本次图片未保存，评价草稿已保留，可重新选择图片' : '本次评价未保存，草稿已保留，可核对后重新提交'));
			},
			onLoadFun() {
				this.getOrderProduct();
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			/**
			 * 获取某个产品详情
			 * 
			 */
			async getOrderProduct() {
				if (!this.reviewPageActive || !this.isLogin || !this.unique || this.reviewReadPending) return;
				let scope = this.reviewScope(); const revision = ++this.reviewReadRevision;
				this.reviewReadPending = true; this.reviewReady = false;
				try {
					if (!scope.uid) await this.$store.dispatch('USERINFO', true);
					if (!this.reviewCurrent(scope) || revision !== this.reviewReadRevision) return;
					scope = this.reviewScope();
					if (!scope.uid) throw new Error(this.$t('请先确认当前登录用户'));
					const res = await orderProduct(this.unique);
					if (!this.reviewCurrent(scope) || revision !== this.reviewReadRevision) return;
					this.$set(this, 'productInfo', res.data.productInfo); this.cart_num = res.data.cart_num; this.reviewReady = true;
				} catch (error) {
					if (!this.reviewCurrent(scope) || revision !== this.reviewReadRevision) return;
					this.reviewFeedback = (error && (error.msg || error.message)) || this.$t('商品资料尚未读取，请重新进入评价页');
				} finally {
					if (this.reviewCurrent(scope) && revision === this.reviewReadRevision) this.reviewReadPending = false;
				}
			},
			stars: function(indexn, indexw) {
				if (this.reviewLocked) return;
				this.scoreList[indexw].index = indexn;
			},
			/**
			 * 删除图片
			 * 
			 */
			DelPic: function(index) {
				if (this.reviewLocked) return;
				this.pics.splice(index, 1);
				this.reviewAssetIds.splice(index, 1);
			},

			/**
			 * 上传文件
			 * 
			 */
			uploadpic: function() {
				if (this.reviewLocked || this.pics.length >= 8) return;
				const scope = this.reviewScope(), current = () => this.reviewCurrent(scope);
				let issued = false, outcome = false;
				this.uploadBusy = true; this.canvasStatus = true; this.reviewFeedback = ''; this.reviewReceipt = null;
				const finish = () => {
					if (!current()) return;
					if (issued && !outcome) this.reviewFailure(null, true);
					this._reviewUploadTask = null; this.uploadBusy = false; this.canvasStatus = false;
				};
				try {
					this.$util.uploadImageChange({ url: 'order/legacy/reviews/' + encodeURIComponent(this.unique) + '/files',
						count: 1, name: 'pics', onUploadTask: task => {
							issued = true;
							if (current()) this._reviewUploadTask = task;
							else if (task && typeof task.abort === 'function') task.abort();
						}
					}, response => {
						if (!current()) return;
						outcome = true; const data = response && response.data;
						if (!data || typeof data !== 'object' || Array.isArray(data) ||
							Object.keys(data).sort().join('|') !== 'attachment_ref|name|review_asset_id|url' ||
							!['name', 'url', 'attachment_ref'].every(key => typeof data[key] === 'string' && data[key] && !data[key].includes('\0')) ||
							typeof data.review_asset_id !== 'string' || !/^[1-9][0-9]{0,19}$/.test(data.review_asset_id) ||
							(data.review_asset_id.length === 20 && data.review_asset_id > '18446744073709551615') ||
							this.reviewAssetIds.includes(data.review_asset_id)) return this.reviewFailure(null, true);
						this.pics.push(data.url); this.reviewAssetIds.push(data.review_asset_id);
						this.reviewFeedback = this.$t('图片已上传，提交评价后生效');
					}, response => {
						if (!current()) return;
						outcome = true; this.reviewFailure(response, true);
					}, size => {
						if (!current()) return;
						this.canvasWidth = size.w; this.canvasHeight = size.h;
					}, finish, current);
				} catch (error) {
					if (!current()) return;
					if (!issued) this.reviewFeedback = this.$t('图片选择未完成，可重新选择');
					finish();
				}
			},

			/**
			 * 立即评价
			 */
			formSubmit(e) {
				if (this.reviewLocked) return;
				const scope = this.reviewScope();
				let value = Object.assign({}, e.detail.value),
					that = this,
					product_score = that.scoreList[0].index + 1 === 0 ? "" : that.scoreList[0].index + 1,
					service_score = that.scoreList[1].index + 1 === 0 ? "" : that.scoreList[1].index + 1;
				if (!value.comment) return that.$util.Tips({
					title: that.$t(`请填写你对宝贝的心得`)
				});
				value.product_score = product_score;
				value.service_score = service_score;
				if (that.pics.length !== that.reviewAssetIds.length) return that.reviewFailure(null, false);
				value.pics = that.pics.slice();
				value.unique = that.unique;
				if (that.reviewAssetIds.length) value.review_asset_ids = that.reviewAssetIds.slice();
				that.submitBusy = true; that.reviewFeedback = ''; that.reviewReceipt = null;
				uni.showLoading({
					title: that.$t(`正在发布评论`)
				});
				request.post('order/comment', value, { noVerify: true }).then(res => {
					if (!that.reviewCurrent(scope)) return;
					uni.hideLoading();
					if (!res || res.status !== 200 || !res.data || typeof res.data !== 'object' || Array.isArray(res.data)) {
						that.reviewFailure(res, false); return;
					}
					that.reviewSubmitted = true;
					if (res.data.to_lottery) {
						let jumpPath = '/pages/goods/goods_comment_con/lottery_comment?type=4&order_id=' + that
							.orderId + '&date=' + Date.parse(new Date())
						that.$util.Tips({
							title: that.$t(`感谢您的评价`),
							icon: 'success'
						}, jumpPath);
					} else {
						that.$util.Tips({
							title: that.$t(`感谢您的评价`),
							icon: 'success'
						})
						that._reviewNavigationTimer = setTimeout(e => {
							if (that.reviewCurrent(scope)) uni.navigateBack();
						}, 1500)
					}
				}).catch(err => {
					if (!that.reviewCurrent(scope)) return;
					uni.hideLoading();
					that.reviewFailure(err, false);
				}).finally(() => {
					if (that.reviewCurrent(scope)) that.submitBusy = false;
				});
			}
		}
	}
</script>

<style lang="scss" scoped>
	.evaluate-con .score {
		background-color: #fff;
		border-top: 1rpx solid #f5f5f5;
		font-size: 28rpx;
		color: #282828;
		padding: 48rpx 30rpx 65rpx 30rpx;
	}

	.evaluate-con .score .item~.item {
		margin-top: 30rpx;
	}

	.evaluate-con .score .item .starsList {
		padding: 0 35rpx 0 40rpx;
	}

	.evaluate-con .score .item .starsList .iconfont {
		font-size: 40rpx;
		color: #aaa;
	}

	.evaluate-con .score .item .starsList .iconfont~.iconfont {
		margin-left: 20rpx;
	}

	.evaluate-con .score .item .evaluate {
		color: #aaa;
		font-size: 24rpx;
	}

	.evaluate-con .score .textarea {
		width: 100%;
		box-sizing: border-box;
		background-color: #fafafa;
		border-radius: 10rpx;
		margin-top: 48rpx;
	}

	.evaluate-con .score .textarea textarea {
		font-size: 28rpx;
		padding: 20rpx 8rpx 0 8rpx;
		width: 100%;
		box-sizing: border-box;
		height: 160rpx;
	}

	.evaluate-con .score .textarea .placeholder {
		color: #bbb;
	}

	.evaluate-con .score .textarea .list {
		margin-top: 25rpx;
		padding-left: 5rpx;
	}

	.evaluate-con .score .textarea .list .pictrue {
		width: 140rpx;
		height: 140rpx;
		margin: 0 0 35rpx 25rpx;
		position: relative;
		font-size: 22rpx;
		color: #bbb;
	}

	.evaluate-con .score .textarea .list .review-upload {
		display: flex;
		flex-direction: column;
		justify-content: center;
		align-items: center;
		border: 1rpx solid #ddd;
		box-sizing: border-box;
		padding: 0;
		line-height: 1.5;
		background: transparent;
	}
	.review-upload::after, .review-remove::after { border: none; }

	.evaluate-con .score .textarea .list .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 3rpx;
	}

	.evaluate-con .score .textarea .list .review-remove {
		position: absolute;
		top: 0;
		right: 0;
		display: flex;
		justify-content: center;
		align-items: center;
		width: 44rpx;
		height: 44rpx;
		padding: 0;
		margin: 0;
		border-radius: 50%;
		background: rgba(255, 255, 255, 0.9);
		line-height: 1;
	}
	.review-remove .icon-guanbi1 { font-size: 36rpx; }
	.review-feedback { margin-top: 24rpx; color: #606266; font-size: 26rpx; line-height: 1.5; overflow-wrap: anywhere; }

	.evaluate-con .score .textarea .list .pictrue .icon-icon25201 {
		color: #bfbfbf;
		font-size: 50rpx;
	}

	.evaluate-con .score .evaluateBnt {
		display: flex;
		align-items: center;
		justify-content: center;
		box-sizing: border-box;
		font-size: 30rpx;
		color: #fff;
		width: 100%;
		height: 86rpx;
		border-radius: 43rpx;
		text-align: center;
		line-height: 86rpx;
		margin-top: 45rpx;
	}
</style>
