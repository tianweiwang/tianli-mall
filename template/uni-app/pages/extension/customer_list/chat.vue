<template>
  <view class="chat-box" :style="colorStyle">
    <!-- #ifdef MP -->
    <!-- 		<view class="head-box">
			<view class="system-head" :style="{ height: sysHead }"></view>
			<view class="title-hd">
				<view class="iconfont icon-fanhui" @click="goBack"></view>
				<view>{{ titleName }}</view>
			</view>
		</view> -->
    <!-- #endif -->
    <view class="broadcast-details_order">
      <!-- 商品信息 -->
      <view class="broadcast-details_box" v-if="productId && productInfo.id">
        <view class="broadcast_details_img">
          <image class="goods-img" :src="productInfo.image" />
        </view>
        <view class="broadcast_details_picBox">
          <view
            class="broadcast_details_tit"
            v-text="productInfo.store_name"
          ></view>
          <view class="acea-row row-between">
            <view class="broadcast_details_pic">
              {{ $t(`￥`) }}{{ productInfo.price }}
              <text
                class="broadcast_details_pic_num"
                v-if="productInfo.ot_price"
                >{{ $t(`￥`) }}{{ productInfo.ot_price }}</text
              >
            </view>
            <view class="broadcast_details_btn" @click="sendProduct">{{
              $t(`发送客服`)
            }}</view>
          </view>
        </view>
      </view>
      <!-- 订单信息 -->
      <view class="broadcast_box" v-if="orderId && orderInfo.id">
        <view class="broadcast-details_num broadcast_num">
          <text>{{ $t(`订单号`) }}：{{ orderInfo.order_id }}</text>
          <text>{{ orderInfo.add_time_y }} {{ orderInfo.add_time_h }}</text>
        </view>
        <view class="broadcast-details_box">
          <view class="broadcast_details_img">
            <image
              class="goods-img"
              :src="orderInfo.cartInfo[0].productInfo.image"
            />
            <view class="broadcast_details_model">
              {{ orderInfo.cartInfo ? orderInfo.cartInfo.length : 0
              }}{{ $t(`件商品`) }}
            </view>
          </view>
          <view class="broadcast_details_picBox">
            <view class="broadcast_details_tit">{{
              orderInfo.cartInfo[0].productInfo.store_name
            }}</view>
            <view class="acea-row row-between">
              <view class="broadcast_details_pic">
                {{ $t(`￥`) }}{{ orderInfo.cartInfo[0].productInfo.price }}
                <text class="broadcast_details_pic_num"
                  v-if="orderInfo.cartInfo[0].costPrice !== undefined && orderInfo.cartInfo[0].costPrice !== null && orderInfo.cartInfo[0].costPrice !== ''"
                  >{{ $t(`￥`) }}{{ orderInfo.cartInfo[0].costPrice }}</text
                >
              </view>
              <view class="broadcast_details_btn" @click="sendOrder">{{
                $t(`发送客服`)
              }}</view>
            </view>
          </view>
        </view>
      </view>
    </view>
    <view class="chat-scroll-box">
      <scroll-view
        scroll-y="true"
        style="height: 100%"
        :scroll-top="scrollTop"
        @scrolltoupper="scrollToTop"
      >
        <Loading :loaded="status" :loading="loading"></Loading>
        <view id="box" class="chat" ref="chat">
          <view v-if="chatMaterialNotice" role="status">{{ chatMaterialNotice }}</view>
          <view
            v-for="(item, index) in records"
            :key="index"
            :id="`msg-${item.id}`"
          >
            <view class="day-box" v-if="item.show">{{ item._add_time }}</view>
            <view class="chat-item" :class="{ 'right-box': item.uid == myUid }">
              <image class="avatar" :src="item.avatar" mode=""></image>
              <!-- 消息 -->
              <view
                class="msg-box"
                v-if="item.msn_type <= 2"
                v-html="item.msn"
              ></view>
              <!-- 图片 -->
              <view class="img-box" v-if="item.msn_type == 3">
                <image
                  v-if="chatMaterialImage(item)"
                  :src="chatMaterialImage(item)"
                  mode="widthFix"
                  @tap="previewImage(item)"
                ></image>
                <text v-else role="status">原图尚未读取</text>
              </view>
              <!-- 商品 -->
              <view
                class="product-box"
                v-if="item.msn_type == 5"
                @click="goProduct(item)"
              >
                <image :src="item.productInfo.image" mode="widthFix"></image>
                <view class="info">
                  <view class="price">
                    <text>{{ $t(`￥`) }}</text>
                    {{ item.productInfo.price }}
                  </view>
                  <view class="name line2">{{
                    item.productInfo.store_name
                  }}</view>
                </view>
              </view>
              <!-- 订单 -->
              <view
                class="platform-order-summary"
                v-if="item.msn_type == 6 && userType && item.orderInfo && item.orderInfo.scope === 'platform_chat_order_card_v1' && item.orderInfo.marketplace_trade_version === 1"
              >
                <view class="summary-heading">{{ $t('咨询订单摘要') }}</view>
                <view>{{ $t('订单号') }}：{{ item.orderInfo.order_id }}</view>
                <view>{{ $t('商家') }}：{{ item.orderInfo.merchant_name_snapshot }}</view>
                <view>{{ $t('下单时间') }}：{{ item.orderInfo.add_time_y }} {{ item.orderInfo.add_time_h }}</view>
                <view v-for="product in item.orderInfo.cartInfo" :key="product.id" class="summary-product">
                  <image :src="product.productInfo.image" mode="aspectFill" class="summary-image" />
                  <view class="summary-product-info">
                    <view>{{ product.productInfo.store_name }}</view>
                    <view>{{ $t('规格') }}：{{ product.sku_name }}</view>
                    <view>{{ $t('数量') }}：{{ product.cart_num }}</view>
                    <view>{{ $t('下单单价') }}：{{ $t('￥') }}{{ product.productInfo.price }}</view>
                  </view>
                </view>
                <view class="summary-total">
                  <view>{{ $t('商品总数量') }}：{{ item.orderInfo.total_num }}</view>
                  <view>{{ $t(item.orderInfo.paid === 1 ? '实付金额' : '订单金额') }}：{{ $t('￥') }}{{ item.orderInfo.pay_price }}</view>
                </view>
                <view class="summary-note">{{ $t('仅供咨询查看') }}</view>
              </view>
              <view
                class="order-box"
                v-else-if="item.msn_type == 6 && item.orderInfo"
                @click="goOrder(item)"
              >
                <view class="title"
                  >{{ $t(`订单号`) }}: {{ item.orderInfo.order_id }}</view
                >
                <view class="info">
                  <image
                    :src="item.orderInfo.cartInfo[0].productInfo.image"
                  ></image>
                  <view class="product-info">
                    <view class="name line2"
                      >{{ item.orderInfo.cartInfo[0].productInfo.store_name }}
                    </view>
                    <view class="price">
                      {{ $t(`￥`)
                      }}{{ item.orderInfo.cartInfo[0].productInfo.price }}
                    </view>
                  </view>
                </view>
              </view>
            </view>
          </view>
        </view>
      </scroll-view>
    </view>
    <view class="footer-box">
      <view class="words" role="button" tabindex="0" :aria-disabled="chatMaterialSelecting || chatMaterialBusy || chatMaterialBlocked"
        @click="uploadImg" @keydown.enter.prevent="uploadImg" @keydown.space.prevent="uploadImg"
        ><text class="iconfont icon-tupian"></text
      ></view>
      <view class="input-box">
        <input
          type="text"
          :placeholder="$t(`请输入内容`)"
          v-model="con"
          confirm-type="send"
          @confirm="sendText"
        />
        <button
          class="chat-send-button"
          role="button"
          aria-label="发送"
          tabindex="0"
          @click="sendText"
          @keydown.enter.prevent="sendText"
          @keydown.space.prevent="sendText"
          :class="{ isSend: isSend }"
        ><text>{{ $t('发送') }}</text></button>
      </view>
      <view class="emoji" @click="isSwiper = !isSwiper"
        ><span class="iconfont icon-biaoqing"></span
      ></view>
    </view>
    <!-- 表情 -->
    <view class="banner slider-banner" v-if="isSwiper">
      <swiper
        class="swiper-wrapper"
        :autoplay="autoplay"
        :circular="circular"
        :interval="interval"
        :duration="duration"
        v-if="emojiGroup.length > 0"
      >
        <block v-for="(emojiList, index) in emojiGroup" :key="index">
          <swiper-item
            ><i
              class="em"
              :class="emoji"
              :style="'background-image:url(' + httpUrl + ')'"
              v-for="emoji in emojiList"
              :key="emoji"
              @click="addEmoji(emoji)"
            ></i
          ></swiper-item>
        </block>
      </swiper>
    </view>
    <canvas
      canvas-id="canvas"
      v-if="canvasStatus"
      :style="{
        width: canvasWidth + 'px',
        height: canvasHeight + 'px',
        position: 'absolute',
        left: '-100000px',
        top: '-100000px',
      }"
    ></canvas>
  </view>
</template>

<script>
const app = getApp();
import { getChatRecord } from "@/api/user";
import { getProductDetail } from "@/api/store";
import { getOrderDetail } from "@/api/order";
let statusBarHeight = uni.getWindowInfo().statusBarHeight + "px";
import Socket from "@/libs/new_chat";
const chunk = function (arr, num) {
  num = num * 1 || 1;
  var ret = [];
  arr.forEach(function (item, i) {
    if (i % num === 0) {
      ret.push([]);
    }
    ret[ret.length - 1].push(item);
  });
  return ret;
};
import emojiList from "@/utils/emoji";
import Loading from "@/components/Loading";
import colors from "@/mixins/color";
import { HTTP_REQUEST_URL } from "@/config/app.js";
import { createPlatformChatScope, isPlatformChatRef } from '@/api/platform-chat-materials';
export default {
  name: "adminChat_index",
  data() {
    return {
      status: false,
      chatMaterialNotice: '',
      chatMaterialBusy: false,
      chatMaterialBlocked: false,
      chatMaterialSelecting: false,
      chatMaterialVisible: true,
      loading: false,
      sysHead: statusBarHeight,
      isTool: false,
      isSwiper: false,
      isWords: false,
      autoplay: false,
      circular: true,
      interval: 3000,
      duration: 500,
      emojiGroup: chunk(emojiList, 21),
      wordsList: [],
      con: "",
      toUid: 0,
      limit: 15,
      upperId: 0,
      chatList: [],
      kefuInfo: {},
      scrollTop: 0,
      active: true,
      isScroll: true,
      oldHeight: 0,
      myUid: 0,
      productId: 0,
      productInfo: {},
      orderId: 0,
      page: 1,
      orderInfo: {},
      uidTo: 0,
      titleName: "",
      chatStatus: false,
      userType: 0,
      canvasWidth: "",
      canvasHeight: "",
      canvasStatus: false,
      httpUrl: "",
    };
  },
  mixins: [colors],
  components: {
    Loading,
  },
  computed: {
    isSend() {
      if (this.con.length == 0) {
        return false;
      } else {
        return true;
      }
    },
    records() {
      return this.chatList.map((item, index) => {
        if (index) {
          if (item.add_time - this.chatList[index - 1].add_time >= 300) {
            item.show = true;
          } else {
            item.show = false;
          }
        } else {
          item.show = true;
        }
        return item;
      });
    },
  },
  watch: {
    chatList() { this.chatMaterialRefresh(); },
    toUid() { this.releaseChatMaterialViews(); this.chatMaterialRefresh(); },
    '$store.state.app.token'() { this.releaseChatMaterialViews(); },
    '$store.state.app.authRevision'() { this.releaseChatMaterialViews(); },
  },
  onLoad(options) {
    this._chatMaterials = createPlatformChatScope(() => this.platformChatContext(), value => {
      this.chatMaterialNotice = value.notice; this.chatMaterialBusy = value.busy; this.chatMaterialBlocked = value.blocked;
      if (value.invalidated) { this.closeChatMaterialPreview(); this.chatList.forEach(row => this.$set(row, '_platformChatURL', '')); }
    });
    uni.showLoading({
      title: this.$t(`客服连接中`),
    });
    this.myUid = this.$store.state.app.uid;
    this.toUid = options.to_uid;
    this.productId = parseInt(options.productId) || 0;
    this.orderId = options.orderId || 0;
    this.userType = options.type;
    this.getproductInfo();
    this.getOrderInfo();
  },
  onUnload() {
    this.closeChatMaterialPreview();
    if (this._chatMaterials) this._chatMaterials.dispose();
    this.$socket.onClose();
    uni.$off();
  },
  onShow() { this.chatMaterialVisible = true; this.chatMaterialRefresh(); },
  onHide() { this.chatMaterialVisible = false; this.releaseChatMaterialViews(); },
  onReady() {
    this.httpUrl = `${HTTP_REQUEST_URL}/statics/images/look.png`;
    // #ifdef H5
    let dom = document.querySelector(".chat-box");
    dom.style.height = window.innerHeight + "px";
    // #endif
    let initSocket = async () => {
      let form_type;
      //#ifdef MP || APP-PLUS
      form_type = 2;
      //#endif
      //#ifdef H5
      form_type = this.$wechat.isWeixin() ? 1 : 3;
      //#endif
      await this.$socket.onStart();
      const identity = await this.$socket.authenticate('login', this.$store.state.app.token, form_type);
      this.myUid = identity.uid;
      app.globalData.isWsOpen = true;
      this.$socket.init();
      this.getChatList();
    };
    initSocket().catch(() => {
      uni.hideLoading();
      this.$util.Tips({ title: this.$t('客服连接未完成') });
    });
    // 初始化

    // 监听客服转接
    uni.$on("to_transfer", (data) => {
      this.toUid = data.toUid;
      if (!this.$socket.isAuthenticated('api')) return;
      this.$socket.send({
        data: {
          id: Number(this.toUid),
        },
        type: "to_chat",
      });
      this.chatList.forEach((el) => {
        if (el.uid != this.myUid) {
          el.avatar = data.avatar;
        }
      });
    });
    // 超时了
    uni.$once("timeout", () => {
      uni.showLoading({
        title: "重连中",
        mask: true,
      });
      this.chatList = [];
      this.$socket.onClose();
      app.globalData.isWsOpen = false;
      initSocket().catch(() => {
        uni.hideLoading();
        this.$util.Tips({ title: this.$t('客服连接未完成') });
      });
    });
    uni.$on('socket_close', () => { app.globalData.isWsOpen = false; if (this._chatMaterials) this._chatMaterials.uncertain(); });
    // 消息接收
    uni.$on(["reply", "chat"], (data) => {
      if (data.msn_type == 1) {
        data.msn = this.replace_em(data.msn);
      }
      data._add_time = data._add_time.substring(0, data._add_time.length - 3);
      this.chatList.push(data);
      this.$nextTick(() => {
        this.height();
      });
    });
    uni.$on("socket_error", () => {
      if (this._chatMaterials) this._chatMaterials.uncertain();
      this.$util.Tips({
        title: this.$t(`连接失败`),
      });
    });
    uni.$on("err_tip", (e) => {
      if (this._chatMaterials) this._chatMaterials.uncertain(e);
      this.$util.Tips({
        title: e.msg,
      });
    });
    uni.$on("online", (data) => {
      if (data.online == 0) {
        uni.showModal({
          title: this.$t(`提示`),
          content: this.$t(`客服已下线，是否需要反馈？`),
          success: function (res) {
            if (res.confirm) {
              uni.redirectTo({
                url: "/pages/columnGoods/HotNewGoods/feedback",
              });
            } else if (res.cancel) {
            }
          },
        });
      }
    });
    this.$nextTick(() => {
      this.height();
    });
  },
  methods: {
    platformChatContext() {
      const socket = this.$socket, identity = socket && socket.identity || {}, state = this.$store.state.app;
      return { kind: 'api', uid: Number(identity.uid), peer: Number(this.toUid), token: state.token, revision: state.authRevision,
        route: String(this.$page && this.$page.fullPath || '/pages/extension/customer_list/chat'),
        ready: this.chatMaterialVisible && !!socket && socket.isAuthenticated('api') && socket.authenticatedCredential === state.token };
    },
    chatMaterialImage(row) { return isPlatformChatRef(row.msn) ? row._platformChatURL || '' : row.msn; },
    releaseChatMaterialViews() {
      this.closeChatMaterialPreview();
      if (this._chatMaterials) this._chatMaterials.releaseViews();
      this.chatList.forEach(row => this.$set(row, '_platformChatURL', ''));
    },
    chatMaterialRefresh() {
      if (!this._chatMaterials || !this.chatMaterialVisible) return;
      this._chatMaterials.hydrate(this.chatList, (row, path) => this.$set(row, '_platformChatURL', path))
        .catch(() => { if (this.chatMaterialVisible) this.chatMaterialNotice = '部分私有原图尚未读取，请重新查看当前聊天'; });
    },
    closeChatMaterialPreview() {
      if (this._chatPrivatePreviewOpen && typeof uni.closePreviewImage === 'function') uni.closePreviewImage({ fail: () => { this.chatMaterialNotice = '原图预览关闭尚未确认'; } });
      this._chatPrivatePreviewOpen = false;
    },
    previewImage(row) {
      const n = this.chatMaterialImage(row);
      if (isPlatformChatRef(row.msn)) {
        if (!this._chatMaterials || !this._chatMaterials.owns(n) || typeof uni.closePreviewImage !== 'function') {
          this.chatMaterialNotice = '当前身份或原图预览关闭能力尚未确认'; return;
        }
        this._chatPrivatePreviewOpen = true;
      }
      uni.previewImage({
        urls: [n],
        success: () => { if (isPlatformChatRef(row.msn) && (!this._chatMaterials || !this._chatMaterials.owns(n))) { this._chatPrivatePreviewOpen = true; this.closeChatMaterialPreview(); } },
      });
    },
    // 返回
    goBack() {
      uni.navigateBack();
    },
    // 商品信息
    getproductInfo() {
      let that = this;
      if (!this.productId) return;
      getProductDetail(this.productId).then((res) => {
        that.productInfo = res.data.storeInfo;
      });
    },
    // 商品信息
    goProduct(item) {
      uni.navigateTo({
        url: `/pages/goods_details/index?id=${item.msn}`,
      });
    },
    // 订单详情
    goOrder(item) {
      if (this.userType && item.orderInfo && item.orderInfo.scope === 'platform_chat_order_card_v1' && item.orderInfo.marketplace_trade_version === 1) return;
      if (this.userType) {
        uni.navigateTo({
          url: `/pages/admin/orderDetail/index?id=${item.msn}`,
        });
      } else {
        uni.navigateTo({
          url: `/pages/goods/order_details/index?order_id=${item.msn}`,
        });
      }
    },
    // 订单消息
    getOrderInfo() {
      if (!this.orderId) return;
      getOrderDetail(this.orderId).then((res) => {
        this.orderInfo = res.data;
        if (this.orderInfo.add_time_h) {
          this.orderInfo.add_time_h = this.orderInfo.add_time_h.substring(
            0,
            this.orderInfo.add_time_h.lastIndexOf(":")
          );
        }
        if (this.orderInfo.cartInfo.length) {
          this.cartInfo = this.orderInfo.cartInfo[0];
        }
      });
    },
    // 表情点击
    addEmoji(item) {
      let val = `[${item}]`;
      this.con += val;
    },
    // 聊天表情转换
    replace_em(str) {
      str = str.replace(
        /\[([^\[\]]+)\]/g,
        "<span class='em $1' style='background-image:url(" +
          this.httpUrl +
          ")'></span>"
      );
      return str;
    },
    // 获取聊天列表
    getChatList() {
      let self = this;
      getChatRecord({
        limit: this.limit,
        uidTo: this.uidTo,
        toUid: Number(this.toUid),
      })
        .then((res) => {
          let selector = "";
          if (res.data.serviceList.length) {
            if (this.uidTo == 0) {
              selector = `#msg-${
                res.data.serviceList[res.data.serviceList.length - 1].id
              }`;
            } else {
              selector = `#msg-${
                this.chatList.length ? this.chatList[0].id : 0
              }`;
            }
          }
          let arr = [];
          var sH = 0;
          uni.hideLoading();
          uni.setNavigationBarTitle({
            title: res.data.nickname,
          });
          this.titleName = res.data.nickname;
          this.toUid = res.data.uid;
          res.data.serviceList.forEach((el) => {
            el._add_time = el._add_time.substring(0, el._add_time.length - 3);
            if (el.msn_type == 1 || el.msn_type == 2) {
              el.msn = this.replace_em(el.msn);
            }
          });
          this.loading = false;
          this.chatList = [...res.data.serviceList, ...this.chatList];

          this.$nextTick(() => {
            if (this.chatList.length) {
              this.setPageScrollTo(selector);
              this.isScroll = res.data.serviceList.length >= this.limit;
            }
          });
          if (!this.$socket.isAuthenticated('api')) return;
          this.$socket.send({
            data: {
              id: Number(this.toUid),
            },
            type: "to_chat",
          });
        })
        .catch((error) => {
          uni.hideLoading();
          this.$util.Tips({
            title: error,
          });
          this.loading = false;
          this.isScroll = false;
          uni.redirectTo({
            url: "/pages/columnGoods/HotNewGoods/feedback",
          });
        });
    },

    // 设置页面滚动位置
    setPageScrollTo(selector) {
      let view = uni.createSelectorQuery().in(this).select(selector);
      view
        .boundingClientRect((res) => {
          this.scrollTop = res ? parseFloat(res.top) - 60 : 0;
        })
        .exec();
    },

    // 发送消息
    sendText() {
      if (!this.isSend) {
        return this.$util.Tips({
          title: this.$t(`请输入内容`),
        });
      }
      this.sendMsg(this.con, 1);
      this.con = "";
    },
    // ws发送
    sendMsg(msn, type) {
      if (!this.$socket.isAuthenticated('api')) return this.$util.Tips({ title: this.$t('聊天室身份尚未确认') });
      return this.$socket.send({
        data: {
          msn,
          type,
          to_uid: Number(this.toUid),
        },
        //#ifdef MP || APP-PLUS
        form_type: 2,
        //#endif
        //#ifdef H5
        form_type: this.$wechat.isWeixin() ? 1 : 3,
        //#endif
        type: "chat",
      });
    },
    async uploadImg() {
      if (this.chatMaterialSelecting || this.chatMaterialBusy || this.chatMaterialBlocked || !this._chatMaterials) return;
      this.chatMaterialSelecting = true;
      try {
        const mode = await this._chatMaterials.selectionMode();
        if (!mode.current()) return;
        if (mode.private) {
          const filePath = await new Promise((resolve, reject) => uni.chooseImage({ count: 1, sizeType: ['compressed'], sourceType: ['album', 'camera'],
            success(result) { if (result.tempFilePaths && result.tempFilePaths[0]) resolve(result.tempFilePaths[0]); else reject(new Error('尚未选择图片')); },
            fail() { reject(new Error('尚未选择图片')); } }));
          if (!mode.current()) return;
          await this._chatMaterials.uploadAndSend(filePath, (ref, type) => this.sendMsg(ref, type));
          return;
        }
        // The explicitly advertised legacy mode keeps the original canvas/chooser/upload path.
        await this.$store.dispatch('USERINFO', true);
        if (!mode.current()) return;
      let self = this;
      self.canvasStatus = true;
      self.$util.uploadImageChange(
        "upload/image",
        function (res) {
          if (!mode.current()) return;
          if (res.status == 200) {
            self.sendMsg(res.data.url, 3);
          }
        },
        (res) => {
          this.canvasStatus = false;
        },
        (res) => {
          this.canvasWidth = res.w;
          this.canvasHeight = res.h;
        },
        () => { this.canvasStatus = false; },
        mode.current
      );
      } catch (error) { this.chatMaterialNotice = error.message || '图片操作尚未完成，请保留草稿'; }
      finally { this.chatMaterialSelecting = false; }
    },
    // 发送商品
    sendProduct() {
      this.sendMsg(this.productId, 5);
      this.productId = 0;
      this.productInfo = {};
    },
    // 发送订单
    sendOrder() {
      this.sendMsg(this.orderId, 6);
      this.orderId = 0;
      this.orderInfo = {};
    },
    // 滚动到底部
    height() {
      let self = this;
      var scrollTop = 0;
      let info = uni.createSelectorQuery().select(".chat");
      setTimeout((res) => {
        info
          .boundingClientRect(function (data) {
            //data - 各种参数
            scrollTop = data.height;
            if (self.active) {
              self.scrollTop = parseInt(scrollTop) + 500;
            } else {
              self.scrollTop = parseInt(scrollTop) + 100;
            }
          })
          .exec();
      }, 200);
    },
    // 滚动到顶部
    scrollToTop() {
      let self = this;
      if (this.isScroll) {
        this.loading = true;
        this.uidTo = this.chatList.length ? this.chatList[0].id : 0;
        this.isScroll = false;
        setTimeout((res) => {
          this.getChatList();
        }, 800);
      }
    },
  },
};
</script>
<style>
/* #ifdef MP || APP-PLUS || H5 */
page,
uni-page-body,
html,
body {
  height: 100%;
}

/* #endif */
</style>
<style lang="scss">
.chat-box {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #f0f1f2;
  /* #ifdef H5 */
  height: 100vh;

  /* #endif */
  .head-box {
    /* #ifdef H5 */
    height: 86rpx;
    /* #endif */
    background: linear-gradient(85deg, $kf-star 0%, $kf-end 100%);

    .title-hd {
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      height: 43px;
      padding: 0 30rpx;
      color: #fff;

      .icon-fanhui {
        position: absolute;
        left: 30rpx;
        top: 50%;
        transform: translateY(-50%);
      }

      .icon-gengduo2 {
        /* #ifdef MP */
        position: absolute;
        right: 210rpx;
        top: 50%;
        transform: translateY(-50%);
        /* #endif */
      }
    }
  }

  .scroll-box {
    flex: 1;
  }

  .footer-box {
    display: flex;
    align-items: center;
    box-sizing: border-box;
    flex-shrink: 0;
    height: auto;
    color: rgba(0, 0, 0, 0.8);
    background: #f7f7f7;
    /* #ifdef APP-PLUS */
    padding: 0 30rpx;
    padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
    padding-bottom: env(safe-area-inset-bottom); ///兼容 IOS>11.2/
    min-height: 70rpx;
    min-height: calc(70rpx + constant(safe-area-inset-bottom));
    min-height: calc(70rpx + env(safe-area-inset-bottom));
    /* #endif */

    /* #ifndef APP-PLUS */
    padding: 0 30rpx 15rpx 30rpx;
    min-height: 115rpx;

    /* #endif */
    .words .icon-tupian {
      font-size: 50rpx;
    }

    .input-box {
      display: flex;
      align-items: center;
      flex: 1;
      min-width: 0;
      min-height: 48px;
      height: auto;
      padding-right: 4px;
      margin-left: 18rpx;
      background-color: #fff;
      border-radius: 32rpx;

      input {
        flex: 1;
        min-width: 0;
        padding-left: 20rpx;
        height: 100%;
        font-size: 28rpx;
        font-weight: normal;
      }

      .chat-send-button {
        display: flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
        flex: 0 0 64px;
        width: 64px;
        height: 44px;
        margin: 0;
        padding: 0;
        border: 0;
        border-radius: 22px;
        background: transparent;
        color: #666;
        font-size: 14px;
        font-weight: normal;
        line-height: 1;
        text-align: center;
      }

      .chat-send-button::after {
        border: 0;
      }

      .chat-send-button:focus-visible {
        outline: 2px solid $kf-theme;
      }

      .chat-send-button.isSend {
        background: $kf-theme;
        color: #fff;
      }
    }

    .emoji .icon-biaoqing {
      margin-left: 18rpx;
      font-size: 50rpx;
    }

    .more .icon-gengduozhankai {
      margin-left: 18rpx;
      font-size: 50rpx;
    }
  }
}

.tool-wrapper {
  display: flex;
  justify-content: space-between;
  padding: 45rpx 60rpx;
  background: #fff;
  font-size: 24rpx;

  .tool-item {
    text-align: center;

    image {
      width: 104rpx;
      height: 104rpx;
    }
  }
}

.slider-banner {
  background: #fff;
  padding-bottom: 0rpx;
  padding-bottom: calc(constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
  padding-bottom: calc(env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
}

.words-mask {
  z-index: 50;
  position: fixed;
  left: 0;
  top: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);

  .content {
    position: absolute;
    left: 0;
    right: 0;
    top: 114rpx;
    bottom: 0;
    display: flex;
    flex-direction: column;
    padding: 0 30rpx;
    background: #fff;
    border-radius: 6rpx 6rpx 0px 0px;

    .title-box {
      position: relative;
      height: 125rpx;
      line-height: 125rpx;
      text-align: center;
      font-size: 32rpx;

      .icon-cha1 {
        position: absolute;
        right: 0;
        top: 50%;
        transform: translateY(-50%);
      }
    }

    .scroll-box {
      flex: 1;
      overflow: hidden;

      .msg-item {
        padding: 25rpx 0;
        border-bottom: 1px solid #eceff8;
      }
    }
  }
}

.chat-scroll-box {
  flex: 1;
  min-height: 0;
  padding: 30rpx 30rpx 0;
  overflow: hidden;

  .chat-item {
    display: flex;
    margin-bottom: 36rpx;
    -webkit-user-select: auto;

    .platform-order-summary {
      flex: 0 1 452rpx;
      width: 452rpx;
      max-width: calc(100% - 102rpx);
      box-sizing: border-box;
      margin-left: 22rpx;
      padding: 20rpx;
      border: 1rpx solid #e7e9ed;
      border-radius: 14rpx;
      background: #fff;
      color: #333;
      font-size: 24rpx;
      line-height: 1.5;
      overflow-wrap: anywhere;
      .summary-heading { margin-bottom: 12rpx; font-size: 26rpx; font-weight: 600; }
      .summary-product { display: flex; align-items: flex-start; margin-top: 20rpx; }
      .summary-image { flex: 0 0 80rpx; width: 80rpx; height: 80rpx; margin-right: 16rpx; border-radius: 8rpx; }
      .summary-product-info { flex: 1; min-width: 0; }
      .summary-total { margin-top: 20rpx; padding-top: 16rpx; border-top: 1rpx solid #eceff3; }
      .summary-note { margin-top: 12rpx; color: #606266; }
    }

    .avatar {
      width: 80rpx;
      height: 80rpx;
      border-radius: 50%;
    }

    .msg-box {
      display: flex;
      align-items: center;
      max-width: 452rpx;
      margin-left: 22rpx;
      padding: 10rpx 24rpx;
      background: #fff;
      border-radius: 14rpx;
      word-break: break-all;
      -webkit-user-select: auto;
    }

    .img-box {
      width: 270rpx;
      margin-left: 22rpx;

      image {
        width: 270rpx;
      }
    }

    .product-box {
      width: 452rpx;
      margin-left: 22rpx;
      background-color: #fff;
      border-radius: 14rpx;
      overflow: hidden;

      image {
        width: 452rpx;
      }

      .info {
        padding: 16rpx 26rpx;

        .price {
          font-size: 36rpx;
          color: var(--view-priceColor);

          text {
            font-size: 28rpx;
          }
        }
      }
    }

    .order-box {
      width: 452rpx;
      margin-left: 22rpx;
      background-color: #fff;
      border-radius: 14rpx;

      .title {
        padding: 15rpx 20rpx;
        font-size: 26rpx;
        color: #282828;
        border-bottom: 1px solid #eceff8;
      }

      .info {
        display: flex;
        padding: 20rpx;

        image {
          width: 124rpx;
          height: 124rpx;
          border-radius: 6rpx;
        }

        .product-info {
          flex: 1;
          display: flex;
          flex-direction: column;
          justify-content: space-between;
          margin-left: 16rpx;

          .name {
            font-size: 26rpx;
          }

          .price {
            font-size: 30rpx;
            color: var(--view-priceColor);
          }
        }
      }
    }

    &.right-box {
      flex-direction: row-reverse;

      .platform-order-summary {
        margin-left: 0;
        margin-right: 22rpx;
      }

      .msg-box {
        margin-left: 0;
        margin-right: 22rpx;
        background-color: #9cec60;
      }

      .img-box {
        margin-left: 0;
        margin-right: 22rpx;
      }

      .product-box {
        margin-left: 0;
        margin-right: 22rpx;
      }

      .order-box {
        margin-left: 0;
        margin-right: 22rpx;
      }
    }

    .em {
      margin: 0;
    }
  }
}

.broadcast-details_box {
  display: flex;
  background: #fff;
  border-radius: 6px;
  padding: 24rpx;
}

.broadcast_details_img {
  width: 140rpx;
  height: 140rpx;
  border-radius: 8px;
  overflow: hidden;
  position: relative;
}

.broadcast_details_img .goods-img {
  width: 100%;
  height: 100%;
}

.broadcast_details_picBox {
  width: 75%;
  margin-left: 24rpx;
}

.broadcast_details_tit {
  font-size: 28rpx;
  color: #333333;
  height: 85rpx;
  font-weight: 800;
  line-height: 40rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  text-align: left !important;
}

.broadcast_details_pic {
  font-size: 36rpx;
  color: var(--view-priceColor);
  text-align: left;
}

.broadcast_details_pic_num {
  text-decoration: line-through;
  font-size: 28rpx;
  color: rgba(0, 0, 0, 0.5);
  margin-left: 0.1rem;
}

.broadcast_details_btn {
  width: 130rpx;
  height: 50rpx;
  background: var(--view-theme);
  opacity: 1;
  border-radius: 125rpx;
  color: #fff;
  font-size: 24rpx;
  text-align: center;
  line-height: 50rpx;
}

.broadcast-details_num {
  width: 100%;
  height: 80rpx;
  line-height: 80rpx;
  color: #000000;
  font-size: 26rpx;
  display: flex;
  justify-content: space-between;
  background: #fff;
  border-bottom: 1px dashed rgba(0, 0, 0, 0.2);
  padding: 0 24rpx;
}

.day-box {
  font-size: 24rpx;
  color: #999;
  text-align: center;
  margin-bottom: 36rpx;
}
</style>
<style>
.em {
  display: inline-block;
  width: 50rpx;
  height: 50rpx;
  margin: 40rpx 0 0 50rpx;
}

.emoji-outer {
  position: absolute;
  right: 50rpx;
  bottom: 30rpx;
  width: 50rpx;
  height: 50rpx;
}
</style>
