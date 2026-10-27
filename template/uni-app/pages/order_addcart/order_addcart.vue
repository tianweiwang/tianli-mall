<template>
  <view :style="colorStyle">
    <view class="shoppingCart copy-data" v-if="canShow">
      <view class="labelNav acea-row row-around row-middle">
        <view class="item">
          <text class="iconfont icon-xuanzhong"></text>
          {{ $t(`100%正品保证`) }}
        </view>
        <view class="item">
          <text class="iconfont icon-xuanzhong"></text>
          {{ $t(`所有商品精挑细选`) }}
        </view>
        <view class="item">
          <text class="iconfont icon-xuanzhong"></text>
          {{ $t(`售后无忧`) }}
        </view>
      </view>
      <view class="nav acea-row row-between-wrapper">
        <view>
          {{ $t(`购物数量`) }}
          <text class="num font-num">{{ cartCount }}</text>
        </view>
        <view
          v-if="cartList.valid.length > 0 || cartList.invalid.length > 0"
          class="administrate acea-row row-center-wrapper"
          @click="manage"
        >
          {{ footerswitch ? $t(`管理`) : $t(`取消`) }}
        </view>
      </view>
      <view
        v-if="
          (cartList.valid.length > 0 || cartList.invalid.length > 0) && canShow
        "
      >
        <view class="list">
          <checkbox-group @change="checkboxChange">
            <view v-for="group in cartGroups" :key="String(group.mer_id)" class="store-group">
              <view class="store-header">
                <view class="store-title">
                  <text class="store-name">{{ group.store.name }}</text>
                  <text v-if="footerswitch && isCheckoutStore(group)" class="store-selected">{{ $t(`当前结算店铺`) }}</text>
                </view>
                <button
                  v-if="footerswitch && !isCheckoutStore(group)"
                  class="store-select"
                  :disabled="!canCheckoutStore(group)"
                  @click="selectCheckoutStore(group)"
                >{{ $t(`选择本店`) }}</button>
              </view>
              <block v-for="entry in group.entries" :key="String(entry.item.id)">
                <view class="item acea-row row-between-wrapper">
                  <!-- #ifndef MP -->
                  <checkbox
                    :value="entry.item.id.toString()"
                    :checked="entry.item.checked"
                    :disabled="!entry.item.attrStatus && footerswitch"
                  />
                  <!-- <checkbox :value="(entry.item.id).toString()" :checked="entry.item.checked" :disabled="entry.item.attrStatus?false:true" /> -->
                  <!-- #endif -->
                  <!-- #ifdef MP -->
                  <checkbox
                    :value="entry.item.id"
                    :checked="entry.item.checked"
                    :disabled="!entry.item.attrStatus && footerswitch"
                  />
                  <!-- #endif -->
                  <navigator
                    :url="'/pages/goods_details/index?id=' + entry.item.product_id"
                    hover-class="none"
                    class="picTxt acea-row row-between-wrapper"
                  >
                    <view class="pictrue">
                      <image
                        v-if="entry.item.productInfo.attrInfo"
                        :src="productImage(entry.item.productInfo.attrInfo.image)"
                      ></image>
                      <image v-else :src="productImage(entry.item.productInfo.image)"></image>
                    </view>
                    <view class="text">
                      <view
                        class="line2"
                        :class="entry.item.attrStatus ? '' : 'reColor'"
                      >
                        {{ entry.item.productInfo.store_name }}
                      </view>
                      <view class="infor line1" v-if="entry.item.productInfo.attrInfo"
                        >{{ $t(`属性`) }}：{{
                          entry.item.productInfo.attrInfo.suk
                        }}</view
                      >
                      <view class="money" v-if="entry.item.attrStatus"
                        >{{ $t(`￥`) }}{{ entry.item.truePrice }}</view
                      >
                      <view
                        class="reElection acea-row row-between-wrapper"
                        v-else
                      >
                        <view class="title">{{ $t(`请重新选择商品规格`) }}</view>
                        <view
                          class="reBnt cart-color acea-row row-center-wrapper"
                          @click.stop="reElection(entry.item)"
                          >{{ $t(`重选`) }}</view
                        >
                      </view>
                    </view>
                    <view
                      class="carnum acea-row row-center-wrapper"
                      v-if="entry.item.attrStatus"
                    >
                      <view class="reduce" @click.stop="subCart(entry.index)">-</view>
                      <!-- <view class='num'>{{entry.item.cart_num}}</view> -->
                      <view class="num">
                        <input
                          type="number"
                          v-model="entry.item.cart_num"
                          @click.stop
                          @input="iptCartNum(entry.index)"
                          @blur="blurInput(entry.index)"
                        />
                      </view>
                      <view
                        class="plus"
                        :class="entry.item.numAdd && !disabledChangeNumber ? 'on' : ''"
                        @click.stop="addCart(entry.index)"
                        >+</view
                      >
                    </view>
                  </navigator>
                </view>
              </block>
            </view>
          </checkbox-group>
        </view>
        <view class="invalidGoods" v-if="cartList.invalid.length > 0">
          <view class="goodsNav acea-row row-between-wrapper">
            <view @click="goodsOpen">
              <text
                class="iconfont"
                :class="
                  goodsHidden == true ? 'icon-xiangxia' : 'icon-xiangshang'
                "
              ></text>
              {{ $t(`失效商品`) }}
            </view>
            <view class="del" @click="unsetCart">
              <text class="iconfont icon-shanchu1"></text>
              {{ $t(`清空`) }}
            </view>
          </view>
          <view class="goodsList" :hidden="goodsHidden">
            <block v-for="(item, index) in cartList.invalid" :key="index">
              <view class="item acea-row row-between-wrapper">
                <view class="invalid">{{ $t(`失效`) }}</view>
                <view class="pictrue">
                  <image
                    v-if="item.productInfo && item.productInfo.attrInfo"
                    :src="productImage(item.productInfo.attrInfo.image)"
                  ></image>
                  <image v-else-if="item.productInfo && item.productInfo.image" :src="productImage(item.productInfo.image)"></image>
                </view>
                <view class="text acea-row row-column-between">
                  <view class="line1 name">{{
                    (item.productInfo && item.productInfo.store_name) || $t(`该商品已失效`)
                  }}</view>
                  <view class="infor line1" v-if="item.productInfo && item.productInfo.attrInfo"
                    >{{ $t(`属性`) }}：{{ item.productInfo.attrInfo.suk }}</view
                  >
                  <view class="acea-row row-between-wrapper">
                    <!-- <view>￥{{item.truePrice}}</view> -->
                    <view class="end">{{ $t(`该商品已失效`) }}</view>
                  </view>
                </view>
              </view>
            </block>
          </view>
        </view>
        <!-- <view class='loadingicon acea-row row-center-wrapper' v-if="cartList.valid.length&&!loadend">
					<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
				</view> -->
        <view
          class="loadingicon acea-row row-center-wrapper"
          v-if="cartList.invalid.length && loadend"
        >
          <text
            class="loading iconfont icon-jiazai"
            :hidden="loadingInvalid == false"
          ></text>
          {{ loadTitleInvalid }}
        </view>
      </view>
      <view
        class="noCart"
        v-if="
          cartList.valid.length == 0 && cartList.invalid.length == 0 && canShow
        "
      >
        <view class="emptyBox">
          <image :src="imgHost + '/statics/images/no-thing.png'"></image>
          <view class="tips">{{ $t(`暂无商品`) }}</view>
        </view>
        <recommend
          v-if="hostProduct.length"
          :hostProduct="hostProduct"
        ></recommend>
      </view>
      <view :style="[medHeight]"></view>
      <view
        class="footer acea-row row-between-wrapper"
        :style="[componentStyle]"
        v-if="cartList.valid.length > 0 && canShow"
        :class="is_diy && is_diy_set ? 'on' : ''"
      >
        <view>
          <checkbox-group @change="checkboxAllChange">
            <checkbox value="all" :checked="!!isAllSelect" />
            <text class="checkAll"
              >{{ footerswitch ? $t(`本店全选`) : $t(`全选`) }}({{ selectValue.length }})</text
            >
          </checkbox-group>
        </view>
        <view class="money acea-row row-middle" v-if="footerswitch == true">
          <text class="font-color">{{ $t(`￥`) }}{{ selectCountPrice }}</text>
          <form @submit="subOrder">
            <button class="placeOrder bg-color" formType="submit">
              {{ $t(`立即下单`) }}
            </button>
          </form>
        </view>
        <view class="button acea-row row-middle" v-else>
          <form @submit="subCollect">
            <button class="bnt" formType="submit">{{ $t(`收藏`) }}</button>
          </form>
          <form @submit="subDel">
            <button class="bnt cart-color" formType="submit">
              {{ $t(`删除`) }}
            </button>
          </form>
        </view>
      </view>
    </view>
    <productWindow
      :attr="attr"
      :isShow="1"
      :iSplus="1"
      :iScart="1"
      @myevent="onMyEvent"
      @ChangeAttr="ChangeAttr"
      @ChangeCartNum="ChangeCartNum"
      @attrVal="attrVal"
      @iptCartNum="iptWindowCartNum"
      @goCat="reGoCat"
      id="product-window"
    ></productWindow>
    <!-- #ifdef MP -->
    <!-- <authorize :isAuto="isAuto" :isShowAuth="isShowAuth" @authColse="authColse"></authorize> -->
    <!-- #endif -->
    <!-- <view class="uni-p-b-96"></view> -->
    <view class="uni-p-b-98"></view>
    <pageFooter @newDataStatus="newDataStatus"></pageFooter>
  </view>
</template>

<script>
// #ifdef APP-PLUS
let sysHeight = uni.getWindowInfo().statusBarHeight + "px";
// #endif
// #ifndef APP-PLUS
let sysHeight = 0;
// #endif
import {
  getCartList,
  getCartCounts,
  changeCartNum,
  cartDel,
  getResetCart,
} from "@/api/order.js";
import { getProductHot, collectAll, getProductDetail } from "@/api/store.js";
import { toLogin } from "@/libs/login.js";
import { mapGetters } from "vuex";
import recommend from "@/components/recommend";
import productWindow from "@/components/productWindow";
// #ifdef MP
import authorize from "@/components/Authorize";
// #endif
import pageFooter from "@/components/pageFooter/index.vue";
import colors from "@/mixins/color";
import { HTTP_REQUEST_URL, DEBOUNCETIME } from "@/config/app";
import { Throttle } from "@/utils/validate.js";
import { publicMediaUrl } from '@/utils/public-media.js';

export default {
  components: {
    pageFooter,
    recommend,
    productWindow,
    // #ifdef MP
    authorize,
    // #endif
  },
  mixins: [colors],
  data() {
    return {
      imgHost: HTTP_REQUEST_URL,
      is_diy: uni.getStorageSync("is_diy"),
      canShow: false,
      cartCount: 0,
      goodsHidden: true,
      footerswitch: true,
      hostProduct: [],
      cartList: {
        valid: [],
        invalid: [],
      },
      serverGroups: [],
      selectedMerId: null,
      isAllSelect: false, //全选
      selectValue: [], //选中的数据
      selectCountPrice: 0.0,
      isAuto: false, //没有授权的不会自动授权
      isShowAuth: false, //是否隐藏授权
      hotScroll: false,
      hotPage: 1,
      hotLimit: 10,
      loading: false,
      loadend: false,
      loadTitle: this.$t(`我也是有底线的`), //提示语
      page: 1,
      limit: 20,
      loadingInvalid: false,
      loadendInvalid: false,
      loadTitleInvalid: this.$t(`加载更多`), //提示语
      pageInvalid: 1,
      limitInvalid: 20,
      attr: {
        cartAttr: false,
        productAttr: [],
        productSelect: {},
      },
      productValue: [], //系统属性
      storeInfo: {},
      attrValue: "", //已选属性
      attrTxt: this.$t(`请选择`), //属性页面提示
      cartId: 0,
      product_id: 0,
      sysHeight: sysHeight,
      newData: {},
      activeRouter: "",
      is_diy_set: false,
      adding: false,
      disabledChangeNumber: false,
      isFooter: false,
      btmNum: 0,
      pdHeight: 0, //自定义底部导航上下边距和
    };
  },
  computed: {
    ...mapGetters(["isLogin"]),
    cartGroups() {
      const byReference = Object.create(null);
      this.cartList.valid.forEach((item, index) => {
        byReference[String(item.id)] = { item, index };
      });
      return this.serverGroups.map((group) => ({
        mer_id: group.mer_id,
        store: group.store,
        entries: group.cart_refs.map((reference) => byReference[String(reference)]).filter(Boolean),
      })).filter((group) => group.entries.length > 0);
    },
    checkoutGroup() {
      return this.cartGroups.find((group) => this.isCheckoutStore(group));
    },
    componentStyle() {
      if (this.btmNum) {
        let styleObject = {};
        styleObject["bottom"] = `${this.btmNum * 2 + 104}rpx`;
        return styleObject;
      }
    },
    medHeight() {
      let styleObject = {
        color: "#f5f5f5",
        height: "120rpx",
      };
      if (this.btmNum) {
        styleObject["height"] = `${this.btmNum * 2 + 120}rpx`;
      }
      return styleObject;
    },
  },
  onLoad(options) {
    uni.hideTabBar();
    let that = this;
    let routes = getCurrentPages(); // 获取当前打开过的页面路由数组
    let curRoute = routes[routes.length - 1].route; //获取当前页面路由
    this.activeRouter = "/" + curRoute;
  },
  onShow() {
    // #ifndef MP
    if (!this.isLogin) toLogin();
    // #endif

    this.canShow = false;
    if (this.isLogin == true) {
      this.hotPage = 1;
      this.hostProduct = [];
      this.hotScroll = false;
      this.getHostProduct();
      this.loadend = false;
      this.page = 1;
      this.cartList.valid = [];
      this.serverGroups = [];
      this.selectedMerId = null;
      this.getCartList(1);
      this.loadendInvalid = false;
      this.pageInvalid = 1;
      this.cartList.invalid = [];
      this.getInvalidList();
      // this.getCartNum();
      this.goodsHidden = true;
      this.footerswitch = true;
      this.hostProduct = [];
      this.hotScroll = false;
      this.hotPage = 1;
      this.hotLimit = 10;
      (this.cartList = {
        valid: [],
        invalid: [],
      }),
        (this.isAllSelect = false); //全选
      this.selectValue = []; //选中的数据
      this.selectCountPrice = 0.0;
      this.cartCount = 0;
      this.isShowAuth = false;
    } else {
      // #ifdef MP
      this.hotPage = 1;
      this.hostProduct = [];
      this.hotScroll = false;
      this.getHostProduct();
      this.loading = false;
      this.canShow = true;
      // #endif
    }
  },
  methods: {
    productImage(value) {
      // #ifdef MP
      return publicMediaUrl(value, HTTP_REQUEST_URL);
      // #endif
      // #ifndef MP
      return value;
      // #endif
    },
    // Only server cart references associate rows with a store; product/cache labels do not.
    mergeCartGroups(groups) {
      groups.forEach((incoming) => {
        let group = this.serverGroups.find((current) => String(current.mer_id) === String(incoming.mer_id));
        if (!group) {
          group = { mer_id: incoming.mer_id, store: incoming.store, cart_refs: [] };
          this.serverGroups.push(group);
        } else {
          group.store = incoming.store;
        }
        incoming.cart_refs.forEach((reference) => {
          if (!this.inArray(reference, group.cart_refs)) group.cart_refs.push(reference);
        });
      });
    },
    mergeInvalidRows(rows) {
      const invalid = this.cartList.invalid.slice();
      rows.forEach((row) => {
        if (!invalid.some((current) => String(current.id) === String(row.id))) invalid.push(row);
      });
      this.$set(this.cartList, "invalid", invalid);
    },
    resetLoadedCart() {
      this.cartList.valid = [];
      this.serverGroups = [];
      this.selectValue = [];
      this.isAllSelect = false;
      this.selectCountPrice = 0;
    },
    isCheckoutStore(group) {
      return this.selectedMerId !== null && String(group.mer_id) === String(this.selectedMerId);
    },
    canCheckoutStore(group) {
      return group.entries.some((entry) => entry.item.attrStatus);
    },
    selectCheckoutStore(group) {
      if (!this.footerswitch || !this.canCheckoutStore(group)) return;
      this.selectedMerId = group.mer_id;
      this.selectValue = group.entries.filter((entry) => entry.item.attrStatus).map((entry) => entry.item.id);
      this.switchSelect();
    },
    restoreCheckoutStore() {
      const group = this.cartGroups.find((candidate) => this.isCheckoutStore(candidate) && this.canCheckoutStore(candidate))
        || this.cartGroups.find((candidate) => this.canCheckoutStore(candidate));
      if (group) this.selectCheckoutStore(group);
      else {
        this.selectedMerId = null;
        this.selectValue = [];
        this.switchSelect();
      }
    },
    // 授权关闭
    authColse: function (e) {
      this.isShowAuth = e;
    },
    newDataStatus(val, num, btmNum) {
      this.isFooter = val ? true : false;
      this.pdHeight = num;
      this.btmNum = btmNum;
    },
    // 修改购物车
    reGoCat: function () {
      let that = this,
        productSelect = that.productValue[this.attrValue];
      //如果有属性,没有选择,提示用户选择
      if (that.attr.productAttr.length && productSelect === undefined)
        return that.$util.Tips({
          title: that.$t(`产品库存不足，请选择其它`),
        });

      let q = {
        id: that.cartId,
        product_id: that.product_id,
        num: that.attr.productSelect.cart_num,
        unique:
          that.attr.productSelect !== undefined
            ? that.attr.productSelect.unique
            : "",
      };
      getResetCart(q)
        .then(function (res) {
          that.attr.cartAttr = false;
          that.$util.Tips({
            title: that.$t(`添加购物车成功`),
            success: () => {
              that.loadend = false;
              that.page = 1;
              that.resetLoadedCart();
              that.getCartList();
              that.getCartNum();
            },
          });
        })
        .catch((res) => {
          return that.$util.Tips({
            title: res.msg,
          });
        });
    },
    onMyEvent: function () {
      this.$set(this.attr, "cartAttr", false);
    },
    reElection: function (item) {
      this.getGoodsDetails(item);
    },
    /**
     * 获取产品详情
     *
     */
    getGoodsDetails: function (item) {
      uni.showLoading({
        title: this.$t(`加载中`),
        mask: true,
      });
      let that = this;
      that.cartId = item.id;
      that.product_id = item.product_id;
      getProductDetail(item.product_id)
        .then((res) => {
          uni.hideLoading();
          that.attr.cartAttr = true;
          let storeInfo = res.data.storeInfo;
          that.$set(that, "storeInfo", storeInfo);
          that.$set(that.attr, "productAttr", res.data.productAttr);
          that.$set(that, "productValue", res.data.productValue);
          that.DefaultSelect();
        })
        .catch((err) => {
          uni.hideLoading();
        });
    },
    /**
     * 属性变动赋值
     *
     */
    ChangeAttr: function (res) {
      let productSelect = this.productValue[res];
      if (productSelect && productSelect.stock > 0) {
        this.$set(this.attr.productSelect, "image", productSelect.image);
        this.$set(this.attr.productSelect, "price", productSelect.price);
        this.$set(this.attr.productSelect, "stock", productSelect.stock);
        this.$set(this.attr.productSelect, "unique", productSelect.unique);
        this.$set(this.attr.productSelect, "cart_num", 1);
        this.$set(this, "attrValue", res);
        this.$set(this, "attrTxt", this.$t(`已选择`));
      } else {
        this.$set(this.attr.productSelect, "image", this.storeInfo.image);
        this.$set(this.attr.productSelect, "price", this.storeInfo.price);
        this.$set(this.attr.productSelect, "stock", 0);
        this.$set(this.attr.productSelect, "unique", "");
        this.$set(this.attr.productSelect, "cart_num", 0);
        this.$set(this, "attrValue", "");
        this.$set(this, "attrTxt", this.$t(`请选择`));
      }
    },
    /**
     * 默认选中属性
     *
     */
    DefaultSelect: function () {
      let productAttr = this.attr.productAttr;
      let value = [];
      for (var key in this.productValue) {
        if (this.productValue[key].stock > 0) {
          value = this.attr.productAttr.length ? key.split(",") : [];
          break;
        }
      }
      for (let i = 0; i < productAttr.length; i++) {
        this.$set(productAttr[i], "index", value[i]);
      }
      //sort();排序函数:数字-英文-汉字；
      let productSelect = this.productValue[value.sort().join(",")];
      if (productSelect && productAttr.length) {
        this.$set(
          this.attr.productSelect,
          "store_name",
          this.storeInfo.store_name
        );
        this.$set(this.attr.productSelect, "image", productSelect.image);
        this.$set(this.attr.productSelect, "price", productSelect.price);
        this.$set(this.attr.productSelect, "stock", productSelect.stock);
        this.$set(this.attr.productSelect, "unique", productSelect.unique);
        this.$set(this.attr.productSelect, "cart_num", 1);
        this.$set(this, "attrValue", value.sort().join(","));
        this.$set(this, "attrTxt", this.$t(`已选择`));
      } else if (!productSelect && productAttr.length) {
        this.$set(
          this.attr.productSelect,
          "store_name",
          this.storeInfo.store_name
        );
        this.$set(this.attr.productSelect, "image", this.storeInfo.image);
        this.$set(this.attr.productSelect, "price", this.storeInfo.price);
        this.$set(this.attr.productSelect, "stock", 0);
        this.$set(this.attr.productSelect, "unique", "");
        this.$set(this.attr.productSelect, "cart_num", 0);
        this.$set(this, "attrValue", "");
        this.$set(this, "attrTxt", this.$t(`请选择`));
      } else if (!productSelect && !productAttr.length) {
        this.$set(
          this.attr.productSelect,
          "store_name",
          this.storeInfo.store_name
        );
        this.$set(this.attr.productSelect, "image", this.storeInfo.image);
        this.$set(this.attr.productSelect, "price", this.storeInfo.price);
        this.$set(this.attr.productSelect, "stock", this.storeInfo.stock);
        this.$set(
          this.attr.productSelect,
          "unique",
          this.storeInfo.unique || ""
        );
        this.$set(this.attr.productSelect, "cart_num", 1);
        this.$set(this, "attrValue", "");
        this.$set(this, "attrTxt", this.$t(`请选择`));
      }
    },
    attrVal(val) {
      this.$set(
        this.attr.productAttr[val.indexw],
        "index",
        this.attr.productAttr[val.indexw].attr_values[val.indexn]
      );
    },
    /**
     * 购物车数量加和数量减
     *
     */
    ChangeCartNum: function (changeValue) {
      //changeValue:是否 加|减
      //获取当前变动属性
      let productSelect = this.productValue[this.attrValue];
      //如果没有属性,赋值给商品默认库存
      if (productSelect === undefined && !this.attr.productAttr.length)
        productSelect = this.attr.productSelect;
      //无属性值即库存为0；不存在加减；
      if (productSelect === undefined) return;
      let stock = productSelect.stock || 0;
      let num = this.attr.productSelect;
      if (changeValue) {
        num.cart_num++;
        if (num.cart_num > stock) {
          this.$set(this.attr.productSelect, "cart_num", stock ? stock : 1);
          this.$set(this, "cart_num", stock ? stock : 1);
        }
      } else {
        num.cart_num--;
        if (num.cart_num < 1) {
          this.$set(this.attr.productSelect, "cart_num", 1);
          this.$set(this, "cart_num", 1);
        }
      }
    },
    /**
     * 购物车手动填写
     *
     */
    iptWindowCartNum: function (e) {
      this.$set(this.attr.productSelect, "cart_num", e);
    },
    subDel: function (event) {
      let that = this,
        selectValue = that.selectValue;
      if (selectValue.length > 0)
        cartDel(selectValue).then((res) => {
          that.loadend = false;
          that.page = 1;
          that.resetLoadedCart();
          that.getCartList();
          that.getCartNum();
        });
      else
        return that.$util.Tips({
          title: that.$t(`请选择产品`),
        });
    },
    getSelectValueProductId: function () {
      let that = this;
      let validList = that.cartList.valid;
      let selectValue = that.selectValue;
      let productId = [];
      if (selectValue.length > 0) {
        for (let index in validList) {
          if (that.inArray(validList[index].id, selectValue)) {
            productId.push(validList[index].product_id);
          }
        }
      }
      return productId;
    },
    subCollect: function (event) {
      let that = this,
        selectValue = that.selectValue;
      if (selectValue.length > 0) {
        let selectValueProductId = that.getSelectValueProductId();
        collectAll(that.getSelectValueProductId().join(","))
          .then((res) => {
            return that.$util.Tips({
              title: res.msg,
              icon: "success",
            });
          })
          .catch((err) => {
            return that.$util.Tips({
              title: err,
            });
          });
      } else {
        return that.$util.Tips({
          title: that.$t(`请选择产品`),
        });
      }
    },
    subOrder(event) {
      this.switchSelect();
      if (this.footerswitch && this.checkoutGroup && this.selectValue.length > 0) {
        // This is the customer's store choice, not server-side ownership or trade authorization.
        uni.navigateTo({
          url: "/pages/goods/order_confirm/index?cartId=" + this.selectValue.join(",")
            + "&mer_id=" + encodeURIComponent(String(this.checkoutGroup.mer_id)),
        });
      } else {
        return this.$util.Tips({ title: this.$t(`请选择本店产品`) });
      }
    },
    checkboxAllChange: function (event) {
      this.setAllSelectValue(event.detail.value.length > 0);
    },
    setAllSelectValue: function (status) {
      const candidates = this.footerswitch
        ? (this.checkoutGroup ? this.checkoutGroup.entries.filter((entry) => entry.item.attrStatus).map((entry) => entry.item) : [])
        : this.cartList.valid;
      this.selectValue = status ? candidates.map((item) => item.id) : [];
      this.switchSelect();
    },
    checkboxChange: function (event) {
      let value = event.detail.value;
      if (this.footerswitch) {
        const added = value.find((reference) => !this.inArray(reference, this.selectValue));
        const nextGroup = this.cartGroups.find((group) => group.entries.some((entry) =>
          entry.item.attrStatus && String(entry.item.id) === String(added)));
        if (nextGroup) this.selectedMerId = nextGroup.mer_id;
        const references = this.checkoutGroup
          ? this.checkoutGroup.entries.filter((entry) => entry.item.attrStatus).map((entry) => entry.item.id) : [];
        value = value.filter((reference) => this.inArray(reference, references));
      }
      this.selectValue = value;
      this.switchSelect();
    },
    inArray: function (search, array) {
      return array.some((value) => String(value) === String(search));
    },
    switchSelect: function () {
      const candidates = this.footerswitch
        ? (this.checkoutGroup ? this.checkoutGroup.entries.filter((entry) => entry.item.attrStatus).map((entry) => entry.item) : [])
        : this.cartList.valid;
      const selected = candidates.filter((item) => this.inArray(item.id, this.selectValue));
      const references = selected.map((item) => item.id);
      this.cartList.valid.forEach((item) => {
        this.$set(item, "checked", this.inArray(item.id, references));
      });
      this.selectValue = references;
      this.isAllSelect = candidates.length > 0 && selected.length === candidates.length;
      this.selectCountPrice = selected.reduce((total, item) => this.$util.$h.Add(
        total, this.$util.$h.Mul(item.cart_num, item.truePrice)
      ), 0);
    },
    /**
     * 购物车手动填写
     *
     */
    iptCartNum: function (index) {
      let item = this.cartList.valid[index];
      if (item.cart_num) {
        this.setCartNum(item.id, item.cart_num);
      }
      if (!item.cart_num) {
        item.cart_num = 1;
      }
      this.switchSelect();
    },
    blurInput: function (index) {
      let item = this.cartList.valid[index];
      if (!item.cart_num) {
        item.cart_num = 1;
        this.$set(this.cartList, "valid", this.cartList.valid);
      }
    },
    subCart: function (index) {
      let that = this;
      if (this.disabledChangeNumber) return;
      let status = false;
      let item = that.cartList.valid[index];
      let cart_num = 0;
      cart_num = Number(item.cart_num) - 1;
      if (cart_num < 1 || cart_num < item.min_qty) {
        status = true;
        // 弹出确认框
        uni.showModal({
          title: "提示",
          content: "确定删除吗？",
          success: (res) => {
            if (res.confirm) {
              this.cartCount = this.cartCount - item.cart_num;
              cartDel([item.id]).then((res) => {
                that.loadend = false;
                const currentIndex = that.cartList.valid.findIndex((row) => String(row.id) === String(item.id));
                if (currentIndex >= 0) that.cartList.valid.splice(currentIndex, 1);
                that.selectValue = that.selectValue.filter((reference) => String(reference) !== String(item.id));
                if (that.footerswitch && (!that.checkoutGroup || !that.canCheckoutStore(that.checkoutGroup))) {
                  that.restoreCheckoutStore();
                } else that.switchSelect();
                this.$store.commit("indexData/setCartNum", that.cartCount);
              });
            }
          },
        });
      }

      if (false == status) {
        item.cart_num = cart_num;
        if (item.cart_num <= 1) {
          item.cart_num = 1;
          item.numSub = true;
        } else {
          item.numSub = false;
          item.numAdd = false;
        }
        that.setCartNum(
          item.id,
          item.cart_num,
          function (data) {
            that.cartList.valid[index] = item;
            that.getCartNum();
            that.switchSelect();
          },
          () => {
            item.cart_num = Number(item.cart_num) + 1;
          }
        );
      }
    },
    addCart: function (index) {
      let that = this;
      if (this.adding) return;
      if (this.disabledChangeNumber) return;
      let item = that.cartList.valid[index];
      item.cart_num = Number(item.cart_num) + 1;
      let productInfo = item.productInfo;
      if (
        productInfo.hasOwnProperty("attrInfo") &&
        item.cart_num >= item.productInfo.attrInfo.stock
      ) {
        item.cart_num = item.productInfo.attrInfo.stock;
        item.numAdd = true;
        item.numSub = false;
      } else {
        item.numAdd = false;
        item.numSub = false;
      }
      Throttle(
        that.setCartNum(
          item.id,
          item.cart_num,
          (data) => {
            that.cartList.valid[index] = item;
            that.getCartNum();
            that.switchSelect();
          },
          () => {
            item.cart_num = Number(item.cart_num) - 1;
          }
        ),
        3000
      );
    },
    setCartNum(cartId, cartNum, successCallback, errorCallback) {
      let that = this;
      if (this.disabledChangeNumber) return;
      this.disabledChangeNumber = true;
      changeCartNum(cartId, cartNum)
        .then((res) => {
          successCallback && successCallback(res.data);
        })
        .catch((err) => {
          errorCallback && errorCallback();
          return that.$util.Tips({
            title: err,
          });
        })
        .finally((e) => {
          setTimeout((e) => {
            this.disabledChangeNumber = false;
          }, DEBOUNCETIME);
        });
    },
    getCartNum: function () {
      let that = this;
      getCartCounts().then((res) => {
        that.cartCount = res.data.count;
        this.adding = false;
        this.$store.commit(
          "indexData/setCartNum",
          res.data.count > 99 ? ".." : res.data.count
        );
        if (res.data.count > 0) {
          wx.setTabBarBadge({
            index: 2,
            text: res.data.count + "",
          });
        } else {
          wx.hideTabBarRedDot({
            index: 2,
          });
        }
      });
    },
    getCartData(data) {
      return new Promise((resolve, reject) => {
        getCartList(data)
          .then((res) => {
            resolve(res.data);
          })
          .catch((err) => {
            this.loading = false;
            this.canShow = true;
            this.$util.Tips({
              title: err,
            });
          });
      });
    },
    async getCartList(init) {
      uni.showLoading({
        title: this.$t(`加载中`),
        mask: true,
      });
      let that = this;
      let data = {
        page: that.page,
        limit: that.limit,
        status: 1,
      };
      getCartCounts().then(async (c) => {
        that.cartCount = c.data.count;
        if (init) {
          this.adding = false;
          this.$store.commit(
            "indexData/setCartNum",
            c.data.count > 99 ? ".." : c.data.count
          );
          if (c.data.count > 0) {
            wx.setTabBarBadge({
              index: 2,
              text: c.data.count + "",
            });
          } else {
            wx.hideTabBarRedDot({
              index: 2,
            });
          }
        }
        for (let i = 0; i < Math.ceil(c.data.ids.length / that.limit); i++) {
          let cartList = await this.getCartData(data);
          data.page = data.page + 1;
          const validList = that.cartList.valid.slice();
          cartList.valid.forEach((item) => {
            if (validList.some((current) => String(current.id) === String(item.id))) return;
            item.numSub = item.cart_num == 1;
            const productInfo = item.productInfo;
            item.numAdd = productInfo.hasOwnProperty("attrInfo")
              ? item.cart_num == productInfo.attrInfo.stock : item.cart_num == productInfo.stock;
            item.checked = false;
            validList.push(item);
          });
          that.$set(that.cartList, "valid", validList);
          that.mergeCartGroups(cartList.groups);
          that.mergeInvalidRows(cartList.invalid);
        }
        if (that.footerswitch) that.restoreCheckoutStore();
        else that.switchSelect();
        that.loading = false;
        this.canShow = true;
        uni.hideLoading();
      });
    },
    getInvalidList: function () {
      let that = this;
      if (this.loadendInvalid) return false;
      if (this.loadingInvalid) return false;
      let data = {
        page: that.pageInvalid,
        limit: that.limitInvalid,
        status: 0,
      };
      getCartList(data)
        .then((res) => {
          let cartList = res.data,
            invalid = cartList.invalid,
            loadendInvalid = invalid.length < that.limitInvalid;
          that.mergeInvalidRows(invalid);
          that.loadendInvalid = loadendInvalid;
          that.loadTitleInvalid = loadendInvalid
            ? that.$t(`我也是有底线的`)
            : that.$t(`加载更多`);
          that.pageInvalid = that.pageInvalid + 1;
          that.loadingInvalid = false;
        })
        .catch((res) => {
          that.loadingInvalid = false;
          that.loadTitleInvalid = that.$t(`加载更多`);
        });
    },
    getHostProduct: function () {
      let that = this;
      if (that.hotScroll) return;
      getProductHot(that.hotPage, that.hotLimit).then((res) => {
        that.hotPage++;
        that.hotScroll = res.data.length < that.hotLimit;
        that.hostProduct = that.hostProduct.concat(res.data);
      });
    },
    goodsOpen: function () {
      let that = this;
      that.goodsHidden = !that.goodsHidden;
    },
    goRouter(item) {
      var pages = getCurrentPages();
      var page = pages[pages.length - 1].$page.fullPath;
      if (item.link == page) return;
      uni.switchTab({
        url: item.link,
        fail(err) {
          uni.redirectTo({
            url: item.link,
          });
        },
      });
    },
    manage: function () {
      this.footerswitch = !this.footerswitch;
      if (this.footerswitch) this.restoreCheckoutStore();
      else this.switchSelect();
    },
    unsetCart: function () {
      let that = this,
        ids = [];
      for (let i = 0, len = that.cartList.invalid.length; i < len; i++) {
        ids.push(that.cartList.invalid[i].id);
      }
      cartDel(ids)
        .then((res) => {
          that.$util.Tips({
            title: that.$t(`清除成功`),
          });
          that.$set(that.cartList, "invalid", []);
          that.getCartNum();
        })
        .catch((res) => {});
    },
  },
  onReachBottom() {
    let that = this;
    if (that.loadend) {
      that.getInvalidList();
    }
    if (that.cartList.valid.length == 0 && that.cartList.invalid.length == 0) {
      that.getHostProduct();
    }
  },
  // 滚动监听
  onPageScroll(e) {
    // 传入scrollTop值并触发所有easy-loadimage组件下的滚动监听事件
    uni.$emit("scroll");
  },
};
</script>

<style scoped lang="scss">
.shoppingCart {
  /* #ifdef H5 */
  // padding-bottom: 0;
  // padding-bottom: constant(safe-area-inset-bottom);
  // padding-bottom: env(safe-area-inset-bottom);
  /* #endif */
}

.shoppingCart .labelNav {
  height: 76rpx;
  padding: 0 30rpx;
  font-size: 22rpx;
  color: #8c8c8c;
  position: fixed;
  left: 0;
  width: 100%;
  box-sizing: border-box;
  background-color: #f5f5f5;
  z-index: 5;
  top: 0;
}

.shoppingCart .labelNav .item .iconfont {
  font-size: 25rpx;
  margin-right: 10rpx;
}

.shoppingCart .nav {
  width: 100%;
  height: 80rpx;
  background-color: #fff;
  padding: 0 30rpx;
  box-sizing: border-box;
  font-size: 28rpx;
  color: #282828;
  position: fixed;
  left: 0;
  z-index: 5;
  top: 76rpx;
}

.shoppingCart .nav .num {
  margin-left: 12rpx;
}

.shoppingCart .nav .administrate {
  font-size: 26rpx;
  color: #282828;
  width: 110rpx;
  height: 46rpx;
  border-radius: 6rpx;
  border: 1px solid #a4a4a4;
}

.shoppingCart .noCart {
  margin-top: 171rpx;
  background-color: #fff;
  padding-top: 0.1rpx;
}

.shoppingCart .noCart .pictrue {
  width: 414rpx;
  height: 336rpx;
  margin: 78rpx auto 56rpx auto;
}

.shoppingCart .noCart .pictrue image {
  width: 100%;
  height: 100%;
}

.shoppingCart .list {
  margin-top: 171rpx;
}

.shoppingCart .list .store-group {
  margin-bottom: 15rpx;
}

.shoppingCart .list .store-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16rpx;
  padding: 22rpx 30rpx;
  box-sizing: border-box;
  background: #fff;
  border-bottom: 1rpx solid #f5f5f5;
}

.shoppingCart .list .store-title {
  flex: 1;
  min-width: 0;
  font-size: 28rpx;
  color: #282828;
  overflow-wrap: anywhere;
}

.shoppingCart .list .store-selected {
  display: block;
  margin-top: 6rpx;
  font-size: 24rpx;
  color: #868686;
}

.shoppingCart .list .store-select {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 180rpx;
  width: 180rpx;
  max-width: 100%;
  min-width: 0;
  min-height: 58rpx;
  margin: 0;
  padding: 8rpx 16rpx;
  box-sizing: border-box;
  font-size: 24rpx;
  line-height: 1.4;
  text-align: center;
  border: 1rpx solid #a4a4a4;
  border-radius: 6rpx;
  background: #fff;
}

.shoppingCart .list .store-select::after {
  border: 0;
}

.shoppingCart .list .item {
  padding: 25rpx 30rpx;
  background-color: #fff;
  margin-bottom: 15rpx;
}

.shoppingCart .list .item .picTxt {
  width: 627rpx;
  position: relative;
}

.shoppingCart .list .item .picTxt .pictrue {
  width: 160rpx;
  height: 160rpx;
}

.shoppingCart .list .item .picTxt .pictrue image {
  width: 100%;
  height: 100%;
  border-radius: 6rpx;
}

.shoppingCart .list .item .picTxt .text {
  width: 444rpx;
  font-size: 28rpx;
  color: #282828;
}

.shoppingCart .list .item .picTxt .text .reColor {
  color: #999;
}

.shoppingCart .list .item .picTxt .text .reElection {
  margin-top: 20rpx;
}

.shoppingCart .list .item .picTxt .text .reElection .title {
  font-size: 24rpx;
}

.shoppingCart .list .item .picTxt .text .reElection .reBnt {
  // width: 120rpx;
  padding: 0 10rpx;
  // height: 46rpx;
  margin-top: 6rpx;
  border-radius: 23rpx;
  font-size: 26rpx;
}

.shoppingCart .list .item .picTxt .text .infor {
  font-size: 24rpx;
  color: #868686;
  margin-top: 16rpx;
}

.shoppingCart .list .item .picTxt .text .money {
  font-size: 32rpx;
  color: var(--view-theme);
  margin-top: 28rpx;
}

.shoppingCart .list .item .picTxt .carnum {
  height: 47rpx;
  position: absolute;
  bottom: 0rpx;
  right: 0;
}

.shoppingCart .list .item .picTxt .carnum view {
  border: 1rpx solid #a4a4a4;
  width: 66rpx;
  text-align: center;
  height: 100%;
  line-height: 40rpx;
  font-size: 28rpx;
  color: #a4a4a4;
}

.shoppingCart .list .item .picTxt .carnum .reduce {
  border-right: 0;
  border-radius: 3rpx 0 0 3rpx;
}

.shoppingCart .list .item .picTxt .carnum .reduce.on {
  border-color: #e3e3e3;
  color: #dedede;
}

.shoppingCart .list .item .picTxt .carnum .plus {
  border-left: 0;
  border-radius: 0 3rpx 3rpx 0;
}

.shoppingCart .list .item .picTxt .carnum .plus.on {
  border-color: #e3e3e3;
  color: #dedede;
}

.shoppingCart .list .item .picTxt .carnum .num {
  color: #282828;
}

.shoppingCart .invalidGoods {
  background-color: #fff;
}

.shoppingCart .invalidGoods .goodsNav {
  width: 100%;
  height: 66rpx;
  padding: 0 30rpx;
  box-sizing: border-box;
  font-size: 28rpx;
  color: #282828;
}

.shoppingCart .invalidGoods .goodsNav .iconfont {
  color: #424242;
  font-size: 28rpx;
  margin-right: 17rpx;
}

.shoppingCart .invalidGoods .goodsNav .del {
  font-size: 26rpx;
  color: #999;
}

.shoppingCart .invalidGoods .goodsNav .del .icon-shanchu1 {
  color: #999;
  font-size: 33rpx;
  vertical-align: -2rpx;
  margin-right: 8rpx;
}

.shoppingCart .invalidGoods .goodsList .item {
  padding: 20rpx 30rpx;
  border-top: 1rpx solid #f5f5f5;
}

.shoppingCart .invalidGoods .goodsList .item .invalid {
  font-size: 22rpx;
  color: #fff;
  width: 70rpx;
  height: 36rpx;
  background-color: #aaa;
  border-radius: 3rpx;
  text-align: center;
  line-height: 36rpx;
}

.shoppingCart .invalidGoods .goodsList .item .pictrue {
  width: 140rpx;
  height: 140rpx;
}

.shoppingCart .invalidGoods .goodsList .item .pictrue image {
  width: 100%;
  height: 100%;
  border-radius: 6rpx;
}

.shoppingCart .invalidGoods .goodsList .item .text {
  width: 433rpx;
  font-size: 28rpx;
  color: #999;
  height: 140rpx;
}

.shoppingCart .invalidGoods .goodsList .item .text .name {
  width: 100%;
}

.shoppingCart .invalidGoods .goodsList .item .text .infor {
  font-size: 24rpx;
}

.shoppingCart .invalidGoods .goodsList .item .text .end {
  font-size: 26rpx;
  color: #bbb;
}

.shoppingCart .footer {
  z-index: 999;
  width: 100%;
  height: 96rpx;
  background-color: rgba(255, 255, 255, 0.85);
  backdrop-filter: blur(10px);
  position: fixed;
  padding: 0 30rpx;
  box-sizing: border-box;
  border-top: 1rpx solid #eee;
  bottom: 98rpx;
  bottom: calc(98rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
  bottom: calc(98rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
}

.shoppingCart .footer.on {
  // #ifndef H5
  bottom: 0rpx;
  // #endif
}

.shoppingCart .footer .checkAll {
  font-size: 28rpx;
  color: #282828;
  margin-left: 16rpx;
}

// .shoppingCart .footer checkbox .wx-checkbox-input{background-color:#fafafa;}
.shoppingCart .footer .money {
  font-size: 30rpx;
}

.shoppingCart .footer .placeOrder {
  color: #fff;
  font-size: 30rpx;
  width: 226rpx;
  height: 70rpx;
  border-radius: 50rpx;
  text-align: center;
  line-height: 70rpx;
  margin-left: 22rpx;
}

.shoppingCart .footer .button .bnt {
  font-size: 28rpx;
  color: #999;
  border-radius: 50rpx;
  border: 1px solid #999;
  width: 160rpx;
  height: 60rpx;
  text-align: center;
  line-height: 60rpx;
}

.shoppingCart .footer .button form ~ form {
  margin-left: 17rpx;
}

.uni-p-b-96 {
  height: 96rpx;
}

.uni-p-b-98 {
  height: 100rpx;
  /* 兼容 IOS<11.2 */
  height: calc(100rpx + constant(safe-area-inset-bottom));
  /* 兼容 IOS>11.2 */
  height: calc(100rpx + env(safe-area-inset-bottom));
}

.emptyBox {
  text-align: center;
  padding: 80rpx 0;

  .tips {
    color: #aaa;
    font-size: 26rpx;
  }

  image {
    width: 414rpx;
    height: 304rpx;
  }
}
</style>
