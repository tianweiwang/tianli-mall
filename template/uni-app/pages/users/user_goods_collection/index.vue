<template>
	<view :style="colorStyle">
		<view class='collectionGoods' v-if="collectProductList.length">
			<view class="title-admin">
				<view v-if="count !== null">{{$t(`当前共`)}} <text class="text"> {{count}} </text> {{$t(`件商品`)}}</view>
				<view v-else role="status" aria-live="polite">{{loading?$t(`正在更新收藏列表`):$t(`收藏已更新，列表待刷新`)}}</view>
				<view class="admin" @click="showRadio">{{checkbox_show?$t(`取消`):$t(`管理`)}}</view>
			</view>
			<checkbox-group @change.stop="checkboxChange">
				<view class='item acea-row' v-for="(item,index) in collectProductList" :key="index">
					<view class="left">
						<checkbox v-show="checkbox_show" :value="collectionKey(item)" :checked="item.checked" :disabled="collectionDeleting" />
						<view class='pictrue' @click="jump(item)">
							<image :src="item.image"></image>
							<view class="invalid acea-row row-center-wrapper" v-if="!item.is_show">
								已下架
							</view>
						</view>
					</view>
					<view class='text acea-row row-column-between' @click="jump(item)">
						<view class='name line2'>{{item.store_name}}</view>
						<view class='acea-row row-between-wrapper'>
							<view class='money font-color'>{{$t(`￥`)}}{{item.price}}</view>
							<!-- <view class='delete' @click.stop='delCollection(item.pid,index)'>删除</view> -->
						</view>
					</view>
				</view>
				<view class='loadingicon acea-row row-center-wrapper'>
					<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
				</view>
			</checkbox-group>
		</view>

		<view class='noCommodity' v-else-if="!collectProductList.length && page > 1">
			<view class='pictrue'>
				<image :src="imgHost + '/statics/images/noCollection.png'"></image>
			</view>
			<recommend :hostProduct="hostProduct"></recommend>
		</view>
		<view class='footer acea-row row-between-wrapper' v-if="checkbox_show && collectProductList.length">
			<view>
				<checkbox-group @change="checkboxAllChange">
					<checkbox value="all" :checked="!!isAllSelect" :disabled="collectionDeleting" />
					<text class='checkAll'>{{$t(`全选`)}}({{ids.length}})</text>
				</checkbox-group>
			</view>
			<view class='button acea-row row-middle'>
				<button class='bnt' formType="submit" :disabled="loading || collectionDeleting" @click="subDel">{{collectionDeleting?$t(`正在取关`):$t(`取关`)}}</button>
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
		getCollectUserList,
		getProductHot,
		collectDel
	} from '@/api/store.js';
	import {
		mapGetters
	} from "vuex";
	import {
		toLogin
	} from '@/libs/login.js';
	import recommend from '@/components/recommend';
	// #ifdef MP
	import authorize from '@/components/Authorize';
	// #endif
	import home from '@/components/home';
	import colors from '@/mixins/color.js';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		components: {
			recommend,
			// #ifdef MP
			authorize,
			// #endif
			home
		},
		mixins: [colors],
		data() {
			return {
				imgHost: HTTP_REQUEST_URL,
				ids: [],
				collectionRetryKeys: [],
				collectionDeleting: false,
				collectionReload: false,
				hostProduct: [],
				checkbox_show: false,
				loadTitle: this.$t(`加载更多`),
				loading: false,
				loadend: false,
				collectProductList: [],
				count: 0,
				limit: 15,
				page: 1,
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				hotScroll: false,
				hotPage: 1,
				hotLimit: 10,
				isAllSelect: false, //全选
			};
		},
		computed: mapGetters(['isLogin']),
		onLoad() {
			if (this.isLogin) {
				this.loadend = false;
				this.page = 1;
				this.collectProductList = [];
				this.getUserCollectProduct();
			} else {
				toLogin();
			}
		},
		onShow() {
			this.loadend = false;
			this.page = 1;
			this.$set(this, 'collectProductList', []);
			this.getUserCollectProduct();
		},
		/**
		 * 页面上拉触底事件的处理函数
		 */
		onReachBottom: function() {
			this.getUserCollectProduct();
		},
		methods: {
			showRadio() {
				if (this.collectionDeleting) return;
				this.checkbox_show = !this.checkbox_show
			},
			checkboxChange(e) {
				if (e.detail.value.length < this.ids.length) {
					this.$set(this, 'isAllSelect', false);
				} else if (e.detail.value.length === this.collectProductList.length) {
					this.$set(this, 'isAllSelect', true);
				}
				this.$set(this, 'ids', e.detail.value);
				this.collectionRetryKeys = e.detail.value.slice();
				const selected = new Set(e.detail.value);
				this.collectProductList.forEach(item => { item.checked = selected.has(this.collectionKey(item)); });
			},
			collectionKey(item) {
                return item.category + ':' + item.product_id;
            },
            async subDel() {
                if (this.loading || this.collectionDeleting) return;
                if (!this.ids.length) return this.$util.Tips({ title: this.$t(`请选择商品`) });
                const selected = new Set(this.ids);
                const groups = {};
                this.collectProductList.forEach(item => {
                    if (selected.has(this.collectionKey(item))) {
                        if (!groups[item.category]) groups[item.category] = [];
                        groups[item.category].push(item.product_id);
                    }
                });
                let removed = 0;
                this.collectionDeleting = true;
                try {
                    for (const category of Object.keys(groups)) {
                        await collectDel(groups[category], category);
                        const done = new Set(groups[category].map(id => category + ':' + id));
                        const before = this.collectProductList.length;
                        this.collectProductList = this.collectProductList.filter(item => !done.has(this.collectionKey(item)));
                        removed += before - this.collectProductList.length;
                        this.ids = this.ids.filter(key => !done.has(key));
                    }
                    this.$util.Tips({ title: this.$t(`取消收藏成功`) });
                } catch (error) {
                    this.$util.Tips({ title: removed ? this.$t(`部分已取消，其余请重试`) : (error.msg || error.message || this.$t(`取消失败，请重试`)) });
                } finally {
                    this.collectionDeleting = false;
                    this.isAllSelect = false;
                }
                if (removed) {
                    this.collectionRetryKeys = this.ids.slice();
                    this.page = 1;
                    this.loadend = false;
                    this.collectionReload = true;
                    this.count = null;
                    await this.getUserCollectProduct();
                }
            },
			checkboxAllChange(event) {
				let value = event.detail.value;
				if (value.length > 0) {
					this.setAllSelectValue(1)
				} else {
					this.setAllSelectValue(0)
				}
			},
			setAllSelectValue(status) {
				let that = this;
				let selectValue = [];
				let valid = that.collectProductList;
				if (valid.length > 0) {
					let newValid = valid.map(item => {
						if (status) {
							item.checked = true;
							selectValue.push(that.collectionKey(item));
							that.isAllSelect = true;
						} else {
							item.checked = false;
							that.isAllSelect = false;
						}
						return item;
					});
					that.$set(that, 'collectProductList', newValid);
					that.$set(that, 'ids', selectValue);
					that.collectionRetryKeys = selectValue.slice();
				}
			},
			jump(item) {
                const hasType = Object.prototype.hasOwnProperty.call(item, 'collection_type');
                const hasId = Object.prototype.hasOwnProperty.call(item, 'collection_id');
                const legacy = !hasType && !hasId;
                if (hasType !== hasId) return this.$util.Tips({ title: this.$t(`商品信息暂不可用`) });
                const type = legacy ? 0 : Number(item.collection_type);
                const id = Number(legacy ? item.pid : item.collection_id);
                const pages = { 0: '/pages/goods_details/index', 1: '/pages/activity/goods_seckill_details/index', 6: '/pages/activity/presell_details/index' };
                if (!item.is_show || !pages[type] || !Number.isSafeInteger(id) || id < 1) {
                    return this.$util.Tips({ title: this.$t(`该商品已下架`) });
                }
                let url = pages[type] + '?id=' + id;
                if (type === 1) {
                    const slot = Number(item.collection_time_id);
                    if (!Number.isSafeInteger(slot) || slot < 1) return this.$util.Tips({ title: this.$t(`活动暂不可用`) });
                    url += '&time_id=' + slot;
                }
                uni.navigateTo({ url });
            },
			/**
			 * 授权回调
			 */
			onLoadFun: function() {
				this.loadend = false;
				this.page = 1;
				this.$set(this, 'collectProductList', []);
				this.getUserCollectProduct();
				// this.get_host_product();
			},
			// 授权关闭
			authColse(e) {
				this.isShowAuth = e
			},
			/**
			 * 获取收藏产品
			 */
			getUserCollectProduct() {
                if (this.loading || this.collectionDeleting || this.loadend) return;
                const replace = this.collectionReload;
                const requestPage = replace ? 1 : this.page;
                this.loading = true;
                this.loadTitle = "";
                return getCollectUserList({ page: requestPage, limit: this.limit }).then(res => {
                    const retry = new Set(this.collectionRetryKeys);
                    const rows = res.data.list.map(item => {
                        item.checked = retry.has(this.collectionKey(item));
                        return item;
                    });
                    this.count = res.data.count;
                    this.collectProductList = replace ? rows : this.$util.SplitArray(rows, this.collectProductList);
                    this.$set(this, 'collectProductList', this.collectProductList);
                    this.ids = this.collectProductList.filter(item => item.checked).map(item => this.collectionKey(item));
                    this.isAllSelect = !!this.collectProductList.length && this.ids.length === this.collectProductList.length;
                    this.loadend = rows.length < this.limit;
                    this.loadTitle = this.loadend ? this.$t(`我也是有底线的`) : this.$t(`加载更多`);
                    this.collectionReload = false;
                    this.page = requestPage + 1;
                    if (!this.collectProductList.length && requestPage === 1) this.get_host_product();
                    this.loading = false;
                }).catch(error => {
                    this.loading = false;
                    this.loadTitle = this.collectionReload ? this.$t(`加载失败，请上拉重试`) : this.$t(`加载更多`);
                });
            },
			/**
			 * 获取我的推荐
			 */
			get_host_product() {
				let that = this;
				if (that.hotScroll) return
				getProductHot(
					that.hotPage,
					that.hotLimit,
				).then(res => {
					that.hotPage++
					that.hotScroll = res.data.length < that.hotLimit
					that.hostProduct = that.hostProduct.concat(res.data)
				});
			}
		},
		onReachBottom() {
			this.getUserCollectProduct();
		},
		// 滚动监听
		onPageScroll(e) {
			// 传入scrollTop值并触发所有easy-loadimage组件下的滚动监听事件
			uni.$emit('scroll');
		},
	}
</script>

<style scoped lang="scss">
	.collectionGoods {
		background-color: #fff;
		border-top: 1rpx solid #eee;
	}

	.collectionGoods .item {
		margin-left: 30rpx;
		border-bottom: 1rpx solid #eee;
		height: 180rpx;
		display: flex;
		align-items: center;
		flex-wrap: nowrap;
	}

	.left {
		display: flex;
		align-items: center;
		margin-right: 20rpx;
	}

	.collectionGoods .item .pictrue {
		width: 130rpx;
		height: 130rpx;
		margin-left: 20rpx;
		position: relative;

		.invalid {
			position: absolute;
			width: 100%;
			height: 100%;
			top: 0;
			left: 0;
			background-color: rgba(0, 0, 0, 0.4);
			backdrop-filter: blur(3px);
			color: #fff;
		}

	}

	.collectionGoods .item .pictrue image {
		width: 100%;
		height: 100%;
		border-radius: 6rpx;
	}

	.collectionGoods .item .text {
		height: 130rpx;
		flex: 1;
		font-size: 28rpx;
		color: #282828;
		padding-right: 20rpx;
	}

	.collectionGoods .item .text .name {
		width: max-contnet;
	}

	.collectionGoods .item .text .money {
		font-size: 26rpx;
	}

	.collectionGoods .item .text .delete {
		font-size: 26rpx;
		color: #282828;
		width: 144rpx;
		height: 46rpx;
		border: 1px solid #bbb;
		border-radius: 4rpx;
		text-align: center;
		line-height: 46rpx;
	}

	.noCommodity {
		background-color: #fff;
		padding-top: 1rpx;
		border-top: 0;
	}

	.title-admin {
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding: 20rpx;
		border-bottom: 1px solid #f2f2f2;

		.text {
			color: var(--view-theme);
		}

		.admin {
			color: var(--view-theme);
		}
	}

	.footer {
		z-index: 999;
		width: 100%;
		height: 96rpx;
		background-color: #fafafa;
		position: fixed;
		padding: 0 30rpx;
		box-sizing: border-box;
		border-top: 1rpx solid #eee;
		bottom: 0;
	}

	.footer.on {
		// #ifndef H5
		bottom: 0rpx;
		// #endif
		// #ifdef MP || APP-PLUS
		bottom: 100rpx;
		bottom: calc(100rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		bottom: calc(100rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		// #endif
	}

	.footer .checkAll {
		font-size: 28rpx;
		color: #282828;
		margin-left: 16rpx;
	}

	// .shoppingCart .footer checkbox .wx-checkbox-input{background-color:#fafafa;}
	.footer .money {
		font-size: 30rpx;
	}

	.footer .placeOrder {
		color: #fff;
		font-size: 30rpx;
		width: 226rpx;
		height: 70rpx;
		border-radius: 50rpx;
		text-align: center;
		line-height: 70rpx;
		margin-left: 22rpx;
	}

	.footer .button .bnt {
		font-size: 28rpx;
		color: #999;
		border-radius: 50rpx;
		border: 1px solid #999;
		width: 160rpx;
		height: 60rpx;
		text-align: center;
		line-height: 60rpx;
	}

	.footer .button form~form {
		margin-left: 17rpx;
	}
</style>
