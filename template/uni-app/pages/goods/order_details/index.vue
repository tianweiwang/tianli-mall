<template>
	<view :style="colorStyle">
		<marketplace-fulfillment v-if="!isReturn && fulfillmentKind !== 'root' && (fulfillmentKind !== 'legacy' || fulfillmentLoading || fulfillmentError)"
			:view="fulfillmentView" :loading="fulfillmentLoading" :form-images-active="privateFormImagesVisible" :error="fulfillmentError" :can-view-package="true"
			@page-change="changeFulfillmentPage" @retry="retryFulfillment" @view-package="viewFulfillmentPackage"></marketplace-fulfillment>
		<marketplace-receipt v-if="!isReturn && fulfillmentKind === 'child' && receipt" :receipt="receipt"
			:pending="!!receiptPending" :sending="receiptSending" :reading="fulfillmentLoading"
			:disabled="!isLogin || !!fulfillmentError || receiptPreparing || receiptNeedsRefresh"
			:error="receiptError" :feedback="receiptFeedback" @submit="confirmReceipt" @retry="sendReceipt"></marketplace-receipt>
		<view v-if="!isReturn && (fulfillmentKind === 'root' || fulfillmentKind === 'child') && fulfillmentView && fulfillmentView.order.paid === 1" class="marketplace-aftersale-navigation">
			<button class="marketplace-aftersale-navigation-button" :disabled="fulfillmentLoading || !!fulfillmentError || receiptSending" @tap="openMarketplaceAftersale">售后服务（申请及记录）</button>
			<button class="marketplace-aftersale-navigation-button marketplace-review-navigation-button" :disabled="!isLogin || fulfillmentLoading || !!fulfillmentError || receiptSending" @tap="openMarketplaceReview">{{ fulfillmentKind === 'root' ? '商品评价（资格及记录）' : '查看原订单商品评价' }}</button>
		</view>
		<view class="order-details" v-if="fulfillmentKind === 'legacy' || fulfillmentKind === 'root'">
			<!-- 给header上与data上加on为退款订单-->
			<view class="header bg-color acea-row row-middle" :class="isGoodsReturn ? 'on' : ''">
				<view class="pictrue" v-if="isGoodsReturn == false">
					<image :src="orderInfo.status_pic"></image>
				</view>
				<view class="data" :class="isGoodsReturn ? 'on' : ''">
					<view v-if="fulfillmentKind === 'root' && orderInfo.schema === 2" class="state">{{ rootOrderStatusText(fulfillmentView.order) }}</view>
					<view v-else class="state">{{ orderInfo._status._msg }}</view>
					<view>
						{{ orderInfo.add_time_y }}
						<text class="time">{{ orderInfo.add_time_h }}</text>
					</view>
				</view>
			</view>
			<marketplace-fulfillment v-if="fulfillmentKind === 'root'" :view="fulfillmentView" :loading="fulfillmentLoading" :form-images-active="privateFormImagesVisible"
				:error="fulfillmentError" :can-view-package="true" @page-change="changeFulfillmentPage"
				@retry="retryFulfillment" @view-package="viewFulfillmentPackage"></marketplace-fulfillment>
			<view class="refund-msg" v-if="orderInfo.schema !== 2 && [4, 5].includes(orderInfo.refund_type)">
				<view v-if="orderInfo._status.refund_name != ''">
					<view class="refund-msg-user">
						<text class="name">{{ orderInfo._status.refund_name }}</text>
						<text>{{ orderInfo._status.refund_phone }}</text>
						<!-- #ifndef H5 -->
						<text class="copy-refund-msg" @click="copyAddress()">{{ $t(`复制`) }}</text>
						<!-- #endif -->
						<!-- #ifdef H5 -->
						<text class="copy-refund-msg" :data-clipboard-text="orderInfo._status.refund_name + orderInfo._status.refund_phone + orderInfo._status.refund_address">
							{{ $t(`复制`) }}
						</text>
						<!-- #endif -->
					</view>
					<view class="refund-address">
						{{ orderInfo._status.refund_address }}
					</view>
					<view class="refund-tip">
						<text class="iconfont icon-zhuyi-copy"></text>
						{{ $t(`请按以上退货信息将商品退回`) }}
					</view>
				</view>
				<view v-else>
					<view class="refund-tip1">
						<text class="iconfont icon-zhuyi-copy"></text>
						{{ $t(`请联系管理员获取退货地址`) }}
					</view>
				</view>
			</view>
			<view class="line" v-if="orderInfo.schema !== 2 && [4, 5].includes(orderInfo.refund_type)">
				<image src="@/static/images/line.jpg"></image>
			</view>
			<view class="mb-16" v-if="isGoodsReturn == false">
				<view class="nav" v-if="fulfillmentKind === 'legacy' && is_gift != 2">
					<view class="navCon acea-row row-between-wrapper" v-if="!is_gift">
						<view :class="status.type == 0 || status.type == -9 ? 'on' : ''">{{ $t(`待付款`) }}</view>
						<view :class="status.type == 1 || status.type == 5 ? 'on' : ''">
							{{ orderInfo.shipping_type == 1 ? $t(`待发货`) : $t(`待核销`) }}
						</view>
						<view :class="status.type == 2 ? 'on' : ''" v-if="orderInfo.shipping_type == 1">{{ $t(`待收货`) }}</view>
						<view :class="status.type == 3 ? 'on' : ''">{{ $t(`待评价`) }}</view>
						<view :class="status.type == 4 ? 'on' : ''">{{ $t(`已完成`) }}</view>
					</view>
					<view class="navCon acea-row row-between-wrapper" v-else-if="is_gift !== 2">
						<view :class="status.type == 0 || status.type == -9 || orderInfo.paid == 1 ? 'on' : ''">{{ $t(`待付款`) }}</view>
						<view :class="orderInfo.paid == 1 ? 'on' : ''">{{ $t(`待领取`) }}</view>
						<view :class="orderInfo.gift_uid ? 'on' : ''">{{ $t(`已领取`) }}</view>
						<view :class="status.type == 4 ? 'on' : ''">{{ $t(`已完成`) }}</view>
					</view>
					<view class="progress acea-row row-between-wrapper" v-if="!is_gift">
						<view class="iconfont" :class="(status.type == 0 || status.type == -9 ? 'icon-webicon318' : 'icon-yuandianxiao') + ' ' + (status.type >= 0 ? 'font-num' : '')"></view>
						<view class="line" :class="status.type > 0 ? 'bg-color' : ''"></view>
						<view
							class="iconfont"
							:class="(status.type == 1 || status.type == 5 ? 'icon-webicon318' : 'icon-yuandianxiao') + ' ' + (status.type >= 1 ? 'font-num' : '')"
							v-if="orderInfo.shipping_type != 4"
						></view>
						<view class="line" :class="status.type > 1 && status.type != 5 ? 'bg-color' : ''" v-if="orderInfo.shipping_type == 1"></view>
						<view
							class="iconfont"
							:class="(status.type == 2 ? 'icon-webicon318' : 'icon-yuandianxiao') + ' ' + (status.type >= 2 ? 'font-num' : '')"
							v-if="orderInfo.shipping_type == 1"
						></view>
						<view class="line" :class="status.type > 2 && status.type != 5 ? 'bg-color' : ''"></view>
						<view class="iconfont" :class="(status.type == 3 ? 'icon-webicon318' : 'icon-yuandianxiao') + ' ' + (status.type >= 3 && status.type != 5 ? 'font-num' : '')"></view>
						<view class="line" :class="status.type > 3 && status.type != 5 ? 'bg-color' : ''"></view>
						<view class="iconfont" :class="(status.type == 4 ? 'icon-webicon318' : 'icon-yuandianxiao') + ' ' + (status.type >= 4 && status.type != 5 ? 'font-num' : '')"></view>
					</view>
					<view class="progress acea-row row-between-wrapper" v-else-if="is_gift !== 2">
						<view
							class="iconfont"
							:class="(status.type == 0 || status.type == -9 || orderInfo.paid == 1 ? 'icon-webicon318' : 'icon-yuandianxiao') + ' ' + (status.type >= 0 ? 'font-num' : '')"
						></view>
						<view class="line" :class="orderInfo.paid == 1 ? 'bg-color' : ''"></view>
						<view
							class="iconfont"
							:class="
								(orderInfo.gift_uid == 0 || orderInfo.paid == 1 ? 'icon-webicon318' : 'icon-yuandianxiao') +
								' ' +
								((status.type >= 4 && status.type != 5) || orderInfo.gift_uid == 0 || orderInfo.paid == 1 ? 'font-num' : '')
							"
						></view>
						<view class="line" :class="orderInfo.gift_uid ? 'bg-color' : ''"></view>
						<view
							class="iconfont"
							:class="(orderInfo.gift_uid ? 'icon-webicon318' : 'icon-yuandianxiao') + ' ' + ((status.type >= 4 && status.type != 5) || orderInfo.gift_uid ? 'font-num' : '')"
						></view>
						<view class="line" :class="status.type > 3 && status.type != 5 ? 'bg-color' : ''"></view>
						<view class="iconfont" :class="(status.type == 4 ? 'icon-webicon318' : 'icon-yuandianxiao') + ' ' + (status.type >= 4 && status.type != 5 ? 'font-num' : '')"></view>
					</view>
				</view>
				<view v-if="giftData && is_gift == 2" class="gift-box">
					<view class="acea-row row-middle user-msg">
						<image class="avatar mr-12" :src="giftData.avatar" mode=""></image>
						<text class="nickname">{{ giftData.nickname }} 赠您一份礼物，请查收！</text>
					</view>
					<view class="line"></view>
					<view class="gift-mark" v-if="giftData.gift_mark">
						{{ giftData.gift_mark }}
					</view>
				</view>
				<!-- <view class="writeOff" v-if="orderInfo.shipping_type == 2 && orderInfo.paid"> -->
				<view class="writeOff" v-if="orderInfo.verify_code && orderInfo.paid == 1">
					<view class="title">{{ $t(`核销信息`) }}</view>
					<view class="grayBg">
						<view class="written" v-if="orderInfo.status == 2">
							<image src="../static/written.png"></image>
						</view>
						<view class="pictrue">
							<image :src="codeSrc" mode=""></image>
							<zb-code
								ref="qrcode"
								:show="codeShow"
								:cid="cid"
								:val="val"
								:size="size"
								:unit="unit"
								:background="background"
								:foreground="foreground"
								:pdground="pdground"
								:icon="icon"
								:iconSize="iconsize"
								:onval="onval"
								:loadMake="loadMake"
								@result="qrR"
							/>
						</view>
					</view>
					<view class="gear">
						<image src="../static/writeOff.jpg"></image>
					</view>
					<view class="num">{{ orderInfo._verify_code }}</view>
					<view class="rules">
						<view class="item" v-if="orderInfo.shipping_type == 2">
							<view class="rulesTitle acea-row row-middle">
								<text class="iconfont icon-shijian"></text>
								{{ $t(`营业时间`) }}
							</view>
							<view class="info">
								{{ $t(`每日`) }}：
								<text class="time">{{ orderInfo.system_store.day_time }}</text>
							</view>
						</view>
						<view class="item">
							<view class="rulesTitle acea-row row-middle">
								<text class="iconfont icon-shuoming1"></text>
								{{ $t(`使用说明`) }}
							</view>
							<view class="info">
								{{ orderInfo.shipping_type == 2 ? $t(`可将二维码出示给店员扫描或提供数字核销码`) : $t(`可将二维码出示给配送员进行核销`) }}
							</view>
						</view>
					</view>
				</view>
				<view class="map acea-row row-between-wrapper" v-if="orderInfo.shipping_type == 2">
					<view>{{ $t(`地址信息`) }}</view>
					<view class="place cart-color acea-row row-center-wrapper" @tap="showMaoLocation">
						<text class="iconfont icon-weizhi"></text>
						{{ $t(`查看位置`) }}
					</view>
				</view>
				<view class="mt-12" v-if="orderInfo.virtual_type == 0 && (is_gift == 0 || is_gift == 2)">
					<view class="address" v-if="orderInfo.shipping_type === 1">
						<view class="name">
							{{ orderInfo.real_name }}
							<text class="phone">{{ orderInfo.user_phone }}</text>
						</view>
						<view>{{ orderInfo.user_address }}</view>
					</view>
					<view v-else class="address acea-row row-between-wrapper">
						<view class="address-box">
							<view class="name" @tap="makePhone">
								{{ orderInfo.system_store.name }}
								<text class="phone">{{ orderInfo.system_store.phone }}</text>
							</view>
							<view>{{ orderInfo.system_store.detailed_address }}</view>
						</view>
						<view class="icon acea-row row-middle">
							<view class="iconfont icon-dianhua" @click.stop="makePhone"></view>
							<view class="iconfont icon-dingwei2" @click.stop="showMaoLocation(system_store)"></view>
						</view>
					</view>
					<view class="line" v-if="orderInfo.shipping_type === 1 && !is_gift">
						<image src="@/static/images/line.jpg"></image>
					</view>
				</view>
				<view v-if="orderInfo.virtual_type != 0" style="paddingtop: 6px"></view>
			</view>
			<view v-else>
				<!-- 拒绝退款 -->
				<view class="refund" v-if="orderInfo.refund_type == 3">
					<view class="title">
						<image src="../static/shuoming.png" mode=""></image>
						{{ $t(`拒绝退款`) }}
					</view>
					<view class="con">{{ $t(`拒绝原因`) }}：{{ orderInfo.refuse_reason || '' }}</view>
				</view>
			</view>
			<orderGoods
				v-if="fulfillmentKind === 'legacy'"
				v-for="(item, index) in split"
				:key="item.id"
				:evaluate="item._status._type == 3 ? 3 : 0"
				:orderId="item.order_id"
				:cartInfo="item.cartInfo"
				:jump="false"
				:jumpDetail="true"
				:pid="item.pid"
				:split="true"
				:status_type="item._status._type"
				:index="index"
				:refund_status="item.refund_status"
				:delivery_type="item.delivery_type"
				:is_refund_available="orderInfo.is_refund_available"
				:is_gift="is_gift"
				:gift_uid="orderInfo.gift_uid"
				@confirmOrder="confirmOrder"
				@openSubcribe="openSubcribe"
			></orderGoods>
			<orderGoods
				:read-only="fulfillmentKind === 'root'"
				:evaluate="evaluate"
				:deliveryType="orderInfo.shipping_type"
				:statusType="status.type"
				:sendType="orderInfo.delivery_type"
				:orderId="order_id"
				:oid="orderInfo.id"
				:cartInfo="cartInfo"
				:pid="pid"
				:jump="true"
				:refund_status="orderInfo.refund_status"
				:paid="orderInfo.paid"
				:virtualType="orderInfo.virtual_type"
				:is_refund_available="orderInfo.is_refund_available"
				:is_gift="is_gift"
				:gift_uid="orderInfo.gift_uid"
				@openSubcribe="openSubcribe"
			></orderGoods>
			<!-- #ifdef H5 || APP-PLUS -->
			<div class="goodCall" @click="goGoodCall">
				<span class="iconfont icon-kefu"></span>
				<span>{{ $t(`联系客服`) }}</span>
			</div>
			<!-- #endif -->
			<!-- #ifdef MP -->
			<div class="goodCall" @click="goGoodCall" v-if="routineContact == 0">
				<button hover-class="none">
					<span class="iconfont icon-kefu"></span>
					<span>{{ $t(`联系客服`) }}</span>
				</button>
			</div>
			<div class="goodCall" v-else>
				<button hover-class="none" open-type="contact">
					<span class="iconfont icon-kefu"></span>
					<span>{{ $t(`联系客服`) }}</span>
				</button>
			</div>
			<!-- #endif -->
			<view class="wrapper" v-if="isReturn == 1 && (is_gift == 0 || is_gift == 1)">
				<view class="item acea-row row-between">
					<view>{{ $t(`申请理由`) }}：</view>
					<view class="conter">{{ orderInfo.refund_reason }}</view>
				</view>
				<view class="item acea-row row-between">
					<view>{{ $t(`用户备注`) }}：</view>
					<view class="conter">{{ orderInfo.refund_explain }}</view>
				</view>
				<view v-if="legacyRefundVisible && legacyMaterialProblem" class="item">{{legacyMaterialProblem}}</view>
				<view v-if="legacyMaterialsActive && legacyApplicationIds.length" class="item">
				  <view>申请材料</view>
				  <private-evidence :key="'legacy-application-' + legacyMaterialEpoch" :files="legacyApplicationIds" :reader="readLegacyMaterial"></private-evidence>
				</view>
				<view v-if="legacyMaterialsActive && legacyShippingIds.length" class="item">
				  <view>寄回材料</view>
				  <private-evidence :key="'legacy-shipping-' + legacyMaterialEpoch" :files="legacyShippingIds" :reader="readLegacyMaterial"></private-evidence>
				</view>
				<view v-if="legacyRefundVisible && legacyRefundSource && legacyRefundSource.refund_shipping_note" class="item">寄回备注：{{legacyRefundSource.refund_shipping_note}}</view>
				<view class="item acea-row row-between" v-if="orderInfo.refund_img && orderInfo.refund_img.length">
					<view>{{ $t(`申请图片`) }}：</view>
					<view class="upload acea-row row-middle">
						<view class="conter">
							<view class="pictrue" v-for="(item, index) in orderInfo.refund_img" :key="index">
								<image :src="item"></image>
							</view>
						</view>
					</view>
				</view>
			</view>
			<view class="wrapper" v-if="is_gift == 0 || is_gift == 1">
				<view class="item acea-row row-between">
					<view>{{ $t(`订单号`) }}：</view>
					<view class="conter acea-row row-middle row-right">
						<text>{{ orderInfo.order_id }}</text>
						<!-- #ifndef H5 -->
						<text class="copy" @tap="copy(orderInfo.order_id)">{{ $t(`复制`) }}</text>
						<!-- #endif -->
						<!-- #ifdef H5 -->
						<text class="copy copy-data" :data-clipboard-text="orderInfo.order_id">{{ $t(`复制`) }}</text>
						<!-- #endif -->
					</view>
				</view>
				<view class="item acea-row row-between">
					<view>{{ $t(`下单时间`) }}：</view>
					<view class="conter">{{ (orderInfo.add_time_y || '') + ' ' + (orderInfo.add_time_h || 0) }}</view>
				</view>
				<view class="item acea-row row-between">
					<view>{{ $t(`支付状态`) }}：</view>
					<view class="conter" v-if="orderInfo.paid">{{ $t(savedTestPayment ? '已支付（测试）' : '已支付') }}</view>
					<view class="conter" v-else>{{ $t(`未支付`) }}</view>
				</view>
				<view class="item acea-row row-between" v-if="orderInfo.paid">
					<view>{{ $t(`支付方式`) }}：</view>
					<view class="conter">{{ $t(orderInfo._status._payType) }}</view>
				</view>
				<view class="item acea-row row-between" v-if="orderInfo.mark && isReturn != 1">
					<view v-if="orderInfo.pid">{{ $t(`买家备注`) }}：</view>
					<view v-else>{{ $t(`买家留言`) }}：</view>
					<view class="conter">{{ orderInfo.mark }}</view>
				</view>
				<view class="item acea-row row-between" v-if="orderInfo.remark">
					<view>{{ $t(`商家备注`) }}：</view>
					<view class="conter">
						{{ orderInfo.remark }}
						<!-- #ifndef H5 -->
						<view v-if="orderInfo.virtual_type == 1" class="copy" @tap="copy(orderInfo.remark)">{{ $t(`复制`) }}</view>
						<!-- #endif -->
						<!-- #ifdef H5 -->
						<view v-if="orderInfo.virtual_type == 1" class="copy copy-data" :data-clipboard-text="orderInfo.remark">
							{{ $t(`复制`) }}
						</view>
						<!-- #endif -->
					</view>
				</view>
			</view>
			<view class="wrapper" v-if="savedMarketplaceFormsV1 && savedMarketplaceFormGroups.length">
				<view class="marketplace-saved-form-product" v-for="group in savedMarketplaceFormGroups"
					:key="String(group.product_id) + ':' + group.definition_sha256">
					<view class="item marketplace-saved-form-product-name">{{group.product_name}}</view>
					<view class="item" v-for="field in group.fields" :key="field.field_index">
						<view>{{field.field_index + 1}}. {{field.title}}：</view>
						<view v-if="field.label === 'img'" class="upload">
							<view class="pictrue" v-for="(image,imageIndex) in field.value" :key="imageIndex">
								<image :src="orderFormImageSource(group,field,imageIndex)" mode="aspectFit" @click="previewOrderFormImages(group,field,imageIndex)"></image>
							</view>
						</view>
						<view v-else class="marketplace-saved-form-value">{{field.value}}</view>
					</view>
				</view>
				<button class="marketplace-saved-form-copy" @tap="copyText()">{{$t(`复制表单`)}}</button>
			</view>
			<view class="wrapper" v-if="!savedMarketplaceFormsV1 && customForm && customForm.length">
				<view class="item acea-row row-between" v-for="(item, index) in customForm" :key="index">
					<view class="upload" v-if="item.label == 'img'">
						<view class="diy-from-title">{{ item.title }}：</view>
						<view class="pictrue legacy-form-picture" v-for="(img, imageIndex) in item.value" :key="imageIndex">
							<image v-if="legacyFormImageSource(item,imageIndex)" :src="legacyFormImageSource(item,imageIndex)" @click="previewLegacyFormImages(item,imageIndex)" @error="legacyFormImageFailed(item,imageIndex)"></image>
							<text v-else class="legacy-form-image-note">{{legacyFormImageMessage(item,imageIndex)}}</text>
						</view>
					</view>
					<view v-if="item.label !== 'img'" class="diy-from-title">{{ item.title }}：</view>
					<view v-if="item.label !== 'img'" class="conter">{{ item.value }}</view>
				</view>
				<view class="copy-text" @click="copyText()">{{ $t(`复制`) }}</view>
			</view>
			<!-- 退款订单详情 -->
			<view class="wrapper" v-if="isGoodsReturn && orderInfo.cartInfo[0].productInfo.virtual_type != 3 && (is_gift == 0 || is_gift == 1)">
				<view class="item acea-row row-between">
					<view>{{ $t(`收货人`) }}：</view>
					<view class="conter">{{ orderInfo.real_name }}</view>
				</view>
				<view class="item acea-row row-between">
					<view>{{ $t(`联系电话`) }}：</view>
					<view class="conter">{{ orderInfo.user_phone }}</view>
				</view>
				<view class="item acea-row row-between" v-if="orderInfo.shipping_type && orderInfo.shipping_type == 1">
					<view>{{ $t(`收货地址`) }}：</view>
					<view class="conter">{{ orderInfo.user_address }}</view>
				</view>
			</view>
			<view v-if="orderInfo.status != 0 && (is_gift == 0 || is_gift == 1)">
				<view class="wrapper" v-if="orderInfo.delivery_type == 'express'">
					<view class="item acea-row row-between">
						<view>{{ $t(`配送方式`) }}：</view>
						<view class="conter">{{ $t(`发货`) }}</view>
					</view>
					<view class="item acea-row row-between">
						<view>{{ $t(`快递公司`) }}：</view>
						<view class="conter">{{ orderInfo.delivery_name || '' }}</view>
					</view>
					<view class="item acea-row row-between">
						<view>{{ $t(`快递单号`) }}：</view>
						<view class="conter">{{ orderInfo.delivery_id || '' }}</view>
					</view>
				</view>
				<view class="wrapper" v-else-if="orderInfo.delivery_type == 'send'">
					<view class="item acea-row row-between">
						<view>{{ $t(`配送方式`) }}：</view>
						<view class="conter">{{ $t(`送货`) }}</view>
					</view>
					<view class="item acea-row row-between">
						<view>{{ $t(`配送人姓名`) }}：</view>
						<view class="conter">{{ orderInfo.delivery_name || '' }}</view>
					</view>
					<view class="item acea-row row-between">
						<view>{{ $t(`送货人电话`) }}：</view>
						<view class="conter acea-row row-middle row-right">
							{{ orderInfo.delivery_id || '' }}
							<text class="copy" @tap="goTel">{{ $t(`拨打`) }}</text>
						</view>
					</view>
				</view>
				<view class="wrapper" v-else-if="orderInfo.delivery_type == 'fictitious'">
					<view class="item acea-row row-between">
						<view>{{ $t(`虚拟发货`) }}：</view>
						<view class="conter">{{ $t(`已发货，请注意查收`) }}</view>
					</view>

					<view class="item acea-row row-between" v-if="orderInfo.fictitious_content">
						<view>{{ $t(`虚拟备注`) }}：</view>
						<view class="conter acea-row row-middle row-right">
							<text>{{ orderInfo.fictitious_content }}</text>
							<view class="copy" @click="copyText(orderInfo.fictitious_content)">{{ $t(`复制`) }}</view>
						</view>
					</view>
				</view>
			</view>
			<view class="wrapper" v-if="orderInfo.total_price && (is_gift == 0 || is_gift == 1)">
				<view class="item acea-row row-between">
					<view>{{ $t(`商品总价`) }}：</view>
					<view class="conter">{{ $t(`￥`) }}{{ savedMarketplaceFormsV1 ? addSavedMoney(orderInfo.total_price, orderInfo.vip_true_price) : (parseFloat(orderInfo.total_price) + parseFloat(orderInfo.vip_true_price)).toFixed(2) }}</view>
				</view>
				<view class="item acea-row row-between" v-if="orderInfo.pay_postage > 0">
					<view>{{ $t(`配送运费`) }}：</view>
					<view class="conter">{{ $t(`￥`) }}{{ savedMarketplaceFormsV1 ? strictSavedMoney(orderInfo.pay_postage) : parseFloat(orderInfo.pay_postage).toFixed(2) }}</view>
				</view>
				<view v-if="orderInfo.levelPrice > 0" class="item acea-row row-between">
					<view>{{ $t(`用户等级优惠`) }}：</view>
					<view class="conter">-{{ $t(`￥`) }}{{ savedMarketplaceFormsV1 ? strictSavedMoney(orderInfo.levelPrice) : parseFloat(orderInfo.levelPrice).toFixed(2) }}</view>
				</view>
				<view v-if="orderInfo.memberPrice > 0" class="item acea-row row-between">
					<view>{{ $t(`付费会员优惠`) }}：</view>
					<view class="conter">-{{ $t(`￥`) }}{{ savedMarketplaceFormsV1 ? strictSavedMoney(orderInfo.memberPrice) : parseFloat(orderInfo.memberPrice).toFixed(2) }}</view>
				</view>
				<view v-if="orderInfo.gift_price > 0" class="item acea-row row-between">
					<view>{{ $t(`礼品附加费用`) }}：</view>
					<view class="conter">-{{ $t(`￥`) }}{{ savedMarketplaceFormsV1 ? strictSavedMoney(orderInfo.gift_price) : parseFloat(orderInfo.gift_price).toFixed(2) }}</view>
				</view>
				<view class="item acea-row row-between" v-if="orderInfo.coupon_price > 0">
					<view>{{ $t(`优惠券抵扣`) }}：</view>
					<view class="conter">-{{ $t(`￥`) }}{{ savedMarketplaceFormsV1 ? strictSavedMoney(orderInfo.coupon_price) : parseFloat(orderInfo.coupon_price).toFixed(2) }}</view>
				</view>
				<view class="item acea-row row-between" v-if="orderInfo.use_integral > 0">
					<view>{{ $t(`积分抵扣`) }}：</view>
					<view class="conter">-{{ $t(`￥`) }}{{ savedMarketplaceFormsV1 ? strictSavedMoney(orderInfo.deduction_price) : parseFloat(orderInfo.deduction_price).toFixed(2) }}</view>
				</view>
				<view class="actualPay acea-row row-right" v-if="!orderInfo.help_info.help_status">
					{{ $t(savedMarketplaceFormsV1 && [0, '0'].includes(orderInfo.paid) ? '订单金额' : savedTestPayment ? '测试支付金额' : '实付款') }}：
					<text class="money font-color">{{ $t(`￥`) }}{{ savedMarketplaceFormsV1 ? strictSavedMoney(orderInfo.pay_price) : parseFloat(orderInfo.pay_price).toFixed(2) }}</text>
				</view>
				<view class="actualPay acea-row row-right" v-else>
					<view class="pay-people">
						<image :src="orderInfo.help_info.pay_avatar" mode="代付头像"></image>
						<view class="pay-nickname">
							{{ orderInfo.help_info.pay_nickname || '' }}
						</view>
					</view>
					{{ $t(`总代付`) }}：
					<text class="money font-color">{{ $t(`￥`) }}{{ savedMarketplaceFormsV1 ? strictSavedMoney(orderInfo.pay_price) : parseFloat(orderInfo.pay_price).toFixed(2) }}</text>
				</view>
			</view>
			<view style="height: 120rpx"></view>
			<view v-if="fulfillmentKind === 'root' && marketplaceCancelFeedback" class="marketplace-cancel-feedback" role="status">{{ marketplaceCancelFeedback }}</view>
			<view v-if="fulfillmentKind === 'root' && [0, '0'].includes(orderInfo.paid) && [0, '0'].includes(orderInfo.is_cancel)" class="marketplace-aftersale-navigation">
				<button class="marketplace-aftersale-navigation-button" :disabled="!isLogin || fulfillmentLoading || !!fulfillmentError || marketplaceCancelPending" @tap="pay_open">{{$t(`选择付款方式`)}}</button>
				<button class="marketplace-aftersale-navigation-button marketplace-order-cancel-button" :disabled="!isLogin || fulfillmentLoading || !!fulfillmentError || marketplaceCancelPending" @tap="cancelMarketplaceOrder">{{ marketplaceCancelPending ? $t('正在处理取消请求') : $t('取消订单') }}</button>
			</view>
			<view class="wrapper" v-if="fulfillmentKind === 'root' && orderInfo.paid == 1 && !is_gift && isReturn != 1">
				<button v-if="invoiceData" class="fulfillment-again" :disabled="fulfillmentLoading || !!fulfillmentError" @tap="aleartStatusChange">{{ $t('查看发票') }}</button>
					<button class="marketplace-again-button fulfillment-again" v-if="orderInfo.paid == 1 && !is_gift && isReturn != 1"
						:disabled="fulfillmentLoading || !!fulfillmentError || againPending || againNavigating || againGroups.length > 0" @tap="goOrderConfirm">
						{{ againPending ? $t(`正在准备`) : againNavigating ? $t(`正在打开`) : $t(`再次购买`) }}
					</button>
			</view>
			<view class="footer acea-row row-right row-middle" v-if="fulfillmentKind === 'legacy' && (isGoodsReturn == false || status.type == 9 || orderInfo.refund_type || orderInfo.is_apply_refund)">
				<view class="more" v-if="(invoice_func || invoiceData) && orderInfo.paid && !orderInfo.refund_status" @click="more">
					{{ $t(`更多`) }}
					<span class="iconfont icon-xiangshang"></span>
				</view>
				<view class="" v-else></view>
				<view class="more-box" v-if="moreBtn">
					<view class="more-btn" v-if="invoice_func && !invoiceData" @click="invoiceApply">{{ $t(`申请开票`) }}</view>
					<view class="more-btn" v-if="invoiceData" @click="aleartStatusChange">{{ $t(`查看发票`) }}</view>
				</view>
				<view class="right-btn">
					<view class="qs-btn" v-if="status.type == 0 || status.type == -9" @click.stop="cancelOrder">
						{{ $t(`取消订单`) }}
					</view>
					<view class="bnt bg-color" v-if="status.type == 0" @tap="pay_open(orderInfo.order_id)">{{ $t(`立即付款`) }}</view>
					<view
						@click="openSubcribe(`/pages/goods/${cartInfo.length > 1 ? 'goods_return_list' : 'goods_return'}/index?orderId=` + orderInfo.order_id + '&id=' + orderInfo.id)"
						class="bnt cancel"
						v-else-if="orderInfo.is_apply_refund && orderInfo.refund_status == 0 && cartInfo.length > 1 && !orderInfo.virtual_type && orderInfo.is_refund_available"
					>
						{{ cartInfo.length > 1 ? $t(`批量退款`) : $t(`申请退款`) }}
					</view>
					<navigator
						class="bnt cancel"
						v-if="orderInfo.delivery_type == 'express' && status.class_status == 3 && status.type == 2 && !split.length"
						hover-class="none"
						:url="'/pages/goods/goods_logistics/index?orderId=' + orderInfo.order_id"
					>
						{{ $t(`查看物流`) }}
					</navigator>
					<view class="bnt bg-color" v-if="orderInfo.type == 3 && orderInfo.refund_type == 0 && orderInfo.paid" @tap="goJoinPink">
						{{ $t(`查看拼团`) }}
					</view>
					<view class="bnt bg-color" v-if="status.class_status == 3 && !split.length" @click="confirmOrder()">
						{{ $t(`确认收货`) }}
					</view>
					<button class="bnt bg-color marketplace-again-button" v-if="orderInfo.paid == 1 && !is_gift && isReturn != 1"
						:disabled="againPending || againNavigating || againGroups.length > 0" @tap="goOrderConfirm">
						{{ againPending ? $t(`正在准备`) : againNavigating ? $t(`正在打开`) : $t(`再次购买`) }}
					</button>
					<view class="bnt bg-color" v-if="orderInfo.paid == 1 && is_gift != 0 && orderInfo.gift_uid == 0" @tap="giftModalShow = true">{{ $t(`送给好友`) }}</view>
					<view
						class="bnt bg-color"
						v-if="[1, 2, 4].includes(orderInfo.refund_type) && !orderInfo.is_cancel && orderInfo.type != 3 && orderInfo.refund_status != 2"
						@tap="cancelRefundOrder"
					>
						{{ $t(`取消申请`) }}
					</view>
					<view class="bnt bg-color refundBnt" v-if="orderInfo.refund_type == 4" @tap="refundInput">
						{{ $t(`填写退货信息`) }}
					</view>
					<navigator
						class="bnt cancel refundBnt"
						v-if="orderInfo.refund_type == 5"
						hover-class="none"
						:url="'/pages/goods/goods_logistics/index?orderId=' + orderInfo.order_id + '&type=refund'"
					>
						{{ $t(`查看退货物流`) }}
					</navigator>
					<view class="bnt cancel" v-if="(status.type == 4 && !split.length) || status.type == -2" @tap="delOrder">
						{{ $t(`删除订单`) }}
					</view>
				</view>
			</view>
		</view>
		<home v-show="!aleartStatus && !invShow"></home>
		<view class="mask" v-if="refund_close" @click="refund_close = false"></view>
		<!-- 		<view class="refund-input" :class="refund_close ? 'on' : ''">
			<view class="input-msg">
				<text class='iconfont icon-guanbi5' @tap='refund_close = false'></text>
				<view class="refund-input-title">填写物流单号
				</view>
				<view class="refund-input-sty">
					<input type="text" v-model="express_num" placeholder="请输入物流单号" />
				</view>
				<view class="refund-bth">
					<view class="submit-refund" @click="refundSubmit()">提交</view>
				</view>
			</view>
		</view> -->
		<!-- #ifdef MP -->
		<!-- <authorize @onLoadFun="onLoadFun" :isAuto="isAuto" :isShowAuth="isShowAuth" @authColse="authColse"></authorize> -->
		<!-- #endif -->
		<invoiceModal :aleartStatus="aleartStatus" :invoiceData="invoiceData" @close="aleartStatus = false"></invoiceModal>
		<view class="mask invoice-mask" v-if="aleartStatus || giftModalShow" @click="aleartStatus = false"></view>
		<view class="mask more-mask" v-if="moreBtn" @click="moreBtn = false"></view>
		<giftModal :aleartStatus="giftModalShow" :giftData="giftModalData" @shareH5="shareH5" @close="giftModalShow = false"></giftModal>
		<canvas class="canvas" canvas-id="posterCanvas"></canvas>
		<view class="share-box" v-if="H5ShareBox">
			<image :src="imgHost + '/statics/images/share-info.png'" @click="H5ShareBox = false"></image>
		</view>
		<invoice-picker
			:inv-show="invShow"
			:is-special="special_invoice"
			:url-query="urlQuery"
			:inv-checked="invChecked"
			:order-id="order_id"
			:inv-list="invList"
			:is-order="1"
			@inv-close="invClose"
			@inv-change="invSub"
			@inv-cancel="invCancel"
		></invoice-picker>
		<marketplace-again-store-picker :visible="againGroups.length > 1" :groups="againGroups"
			:disabled="againNavigating" @select="selectAgainStore" @cancel="cancelAgainStore"></marketplace-again-store-picker>
	</view>
</template>

<script>
import {legacyRefundImages} from '@/api/legacy-refund-materials.js';
import PrivateEvidence from '@/components/marketplaceAftersaleEvidence/index.vue';

import { getCustomerFulfillmentPackage, receiveCustomerFulfillmentPackage, getSavedOrderFulfillment, refundOrderDetail, orderAgain, orderTake, orderDel, refundOrderDel, orderCancel, refundExpress, cancelRefundOrder } from '@/api/order.js';
import { openOrderRefundSubscribe } from '@/utils/SubscribeMessage.js';
import { getCustomerType } from '@/api/api.js';
import { getCustomer } from '@/utils/index.js';
import { getUserInfo, invoiceList, makeUpinvoice } from '@/api/user.js';
import home from '@/components/home';
import marketplaceAgainStorePicker from '@/components/marketplaceAgainStorePicker/index.vue';
import { parseAgainPurchase, againConfirmationUrl } from '@/utils/marketplace-again-purchase.js';
import { storefrontMessage } from '@/utils/public-store.js';
import orderGoods from '@/components/orderGoods';
import marketplaceFulfillment from '@/components/marketplaceFulfillment/index.vue';
import { readOrderFulfillment, readCustomerReceipt, readReceiptResult, receiptResultFeedback, rootOrderStatusText } from '@/utils/marketplace-fulfillment.js';
import { prepareReceiptIntent } from '@/utils/marketplace-fulfillment-submit.js';
import marketplaceReceipt from '@/components/marketplaceReceipt/index.vue';
import ClipboardJS from '@/plugin/clipboard/clipboard.js';
import { toLogin } from '@/libs/login.js';
import { mapGetters } from 'vuex';
// #ifdef MP
import authorize from '@/components/Authorize';
// #endif
import orderFormImages from '@/mixins/marketplace-order-form-images.js';
import legacyOrderFormImages from '@/mixins/legacy-order-form-images.js';
import colors from '@/mixins/color';
import invoicePicker from '../components/invoicePicker/index.vue';
import invoiceModal from '../components/invoiceModal/index.vue';
import giftModal from '../order_pay_status/components/giftModal.vue';
import zbCode from '@/components/zb-code/zb-code.vue';
import { HTTP_REQUEST_URL } from '@/config/app.js';
import { userShare } from '@/api/user.js';

function strictSavedMoney(value) {
	if (typeof value !== 'string' || value.trim() !== value || !/^(0|[1-9][0-9]*)\.[0-9]{2}$/.test(value)) {
		throw new Error('订单金额不可用');
	}
	return value;
}

function addSavedMoney(left, right) {
	left = strictSavedMoney(left).replace('.', '');
	right = strictSavedMoney(right).replace('.', '');
	let i = left.length - 1;
	let j = right.length - 1;
	let carry = 0;
	let digits = '';
	while (i >= 0 || j >= 0 || carry) {
		const sum = (i >= 0 ? left.charCodeAt(i--) - 48 : 0) + (j >= 0 ? right.charCodeAt(j--) - 48 : 0) + carry;
		digits = String.fromCharCode(48 + sum % 10) + digits;
		carry = sum > 9 ? 1 : 0;
	}
	digits = (digits.replace(/^0+/, '') || '0').padStart(3, '0');
	return digits.slice(0, -2) + '.' + digits.slice(-2);
}

export default {
	components: { PrivateEvidence,
		home,
		marketplaceAgainStorePicker,
		invoicePicker,
		invoiceModal,
		orderGoods,
		marketplaceFulfillment,
		marketplaceReceipt,
		giftModal,
		zbCode,
		// #ifdef MP
		authorize
		// #endif
	},
	mixins: [colors, orderFormImages, legacyRefundImages, legacyOrderFormImages],
	data() {
		return {
			imgHost: HTTP_REQUEST_URL,
			customForm: '', //自定义留言
			privateFormImagesVisible: true,
			savedMarketplaceFormGroups: [],
			againPending: false,
			againNavigating: false,
			againGroups: [],
			againGeneration: 0,
			fulfillmentView: null,
			fulfillmentKind: '',
			fulfillmentLoading: false,
			fulfillmentError: '',
			fulfillmentPendingPage: 1,
			fulfillmentRevision: 0,
			fulfillmentIdentity: '',
			marketplaceCancelPending: false,
			marketplaceCancelFeedback: '',
			packageEntry: false,
			receipt: null,
			receiptActive: true,
			receiptReadIdentity: '',
			receiptPending: null,
			receiptPendingIdentity: '',
			receiptSending: false,
			receiptPreparing: false,
			receiptNeedsRefresh: false,
			receiptError: '',
			receiptFeedback: '',
			receiptGeneration: 0,
			//二维码参数
			codeShow: false,
			cid: '1',
			ifShow: true,
			val: '', // 要生成的二维码值
			size: 200, // 二维码大小
			unit: 'upx', // 单位
			background: '#FFF', // 背景色
			foreground: '#000', // 前景色
			pdground: '#000', // 角标色
			icon: '', // 二维码图标
			iconsize: 40, // 二维码图标大小
			lv: 3, // 二维码容错级别 ， 一般不用设置，默认就行
			onval: true, // val值变化时自动重新生成二维码
			loadMake: true, // 组件加载完成后自动生成二维码
			src: '', // 二维码生成后的图片地址或base64
			codeSrc: '',
			wd: 0,
			hg: 0,
			mpUrl: '',

			order_id: '',
			evaluate: 0,
			cartInfo: [], //购物车产品
			pid: 0, //上级订单ID
			split: [], //分单商品
			orderInfo: {
				help_info: {},
				system_store: {},
				_status: {}
			}, //订单详情
			system_store: {},
			isGoodsReturn: false, //是否为退款订单
			status: {}, //订单底部按钮状态
			refund_close: false,
			isClose: false,
			H5ShareBox: false,
			giftModalShow: false,
			payMode: [
				{
					name: this.$t(`微信支付`),
					icon: 'icon-weixinzhifu',
					value: 'weixin',
					title: this.$t(`使用微信快捷支付`),
					payStatus: true
				},
				// #ifdef H5 || APP-PLUS
				{
					name: this.$t(`支付宝支付`),
					icon: 'icon-zhifubao',
					value: 'alipay',
					title: this.$t(`使用支付宝支付`),
					payStatus: true
				},
				// #endif
				{
					name: this.$t(`余额支付`),
					icon: 'icon-yuezhifu',
					value: 'yue',
					title: this.$t(`可用余额`),
					number: 0,
					payStatus: true
				},
				{
					name: this.$t(`好友代付`),
					icon: 'icon-haoyoudaizhifu',
					value: 'friend',
					title: this.$t(`找微信好友支付`),
					payStatus: 1
				},
				{
					name: this.$t(`通联支付`),
					icon: 'icon-tonglianzhifu1',
					value: 'allinpay',
					title: this.$t(`使用通联支付付款`),
					payStatus: 1
				}
			],
			pay_close: false,
			pay_order_id: '',
			totalPrice: '0',
			isAuto: false, //没有授权的不会自动授权
			isShowAuth: false, //是否隐藏授权
			routineContact: 0,
			express_num: '',
			invoice_func: false,
			invoiceData: {},
			invoice_id: 0,
			invChecked: '',
			moreBtn: false,
			invShow: false,
			aleartStatus: false, //发票弹窗
			special_invoice: false,
			invList: [],
			customerInfo: {},
			userInfo: {},
			isReturn: '',
			urlQuery: '',
			is_gift: 0, // 0正常商品 || 无人领取   1 购买者   2领取人
			giftData: null,
			giftModalData: null,
			mpGiftImg: HTTP_REQUEST_URL + '/statics/images/gift_share.jpg'
		};
	},
	computed: {
		legacyFormContext() { return !this.savedMarketplaceFormsV1 && this.orderInfo.id ? {kind: 'customer', orderId: this.orderInfo.id, route: this.order_id} : null; },
		legacyFormFields() { return !this.savedMarketplaceFormsV1 ? this.orderInfo.custom_form : []; },
		legacyFormActive() { return this.privateFormImagesVisible && !this.fulfillmentLoading && !this.fulfillmentError && !!this.legacyFormContext; },
    legacyRefundReader() { return 'customer'; },
    legacyRefundSource() { return this.isReturn && this.orderInfo && this.orderInfo.schema !== 2 ? this.orderInfo : null; },
    legacyRefundVisible() { return this.privateFormImagesVisible && !this.fulfillmentLoading && !this.fulfillmentError && !!this.isReturn; },
		...mapGetters(['isLogin']),
		orderFormImageContext() { return this.fulfillmentKind === 'root' && this.fulfillmentView ? {kind: 'customer_order', rootId: this.fulfillmentView.order.id, route: this.order_id} : null; },
		orderFormImageGroups() { return this.savedMarketplaceFormGroups; },
		orderFormImagesActive() { return this.privateFormImagesVisible && !this.fulfillmentLoading && !this.fulfillmentError; },
		fulfillmentActor() { return String(this.$store.state.app.uid); },
		receiptSessionRevision() { return this.$store.state.app.authRevision; },
		savedMarketplaceFormsV1() {
			const version = this.orderInfo.marketplace_trade_version;
			return version === 1 || version === '1';
		},
		savedTestPayment() {
			return this.savedMarketplaceFormsV1 && this.orderInfo.pay_type === 'marketplace_test';
		}
	},
	watch: {
		fulfillmentActor() {
			this.clearSavedFulfillment();
			if (this.isLogin && this.order_id) this.getOrderInfo(1);
		},
		receiptSessionRevision() {
			if (!this.packageEntry && this.fulfillmentKind !== 'child' && !this.receiptPending && !this.marketplaceCancelPending && !this.marketplaceCancelFeedback) return;
			this.clearSavedFulfillment();
			if (this.isLogin && this.order_id) this.getOrderInfo(1);
		},
		isLogin(value) { if (!value) this.clearSavedFulfillment(); }
	},
	onLoad: function (options) {
		this.packageEntry = options.fulfillment_package === '1';
		if (options.order_id) {
			this.$set(this, 'order_id', options.order_id);
			this.isReturn = options.isReturn;
		}
		if (options.invoice_id) {
			this.invoice_id = options.invoice_id;
		}
	},

	onShow() {
		this.privateFormImagesVisible = true;
		this.againNavigating = false;
		if (this.isLogin) {
			this.getOrderInfo();
			this.getUserInfo();
			this.getCustomerType();
			// #ifdef MP-WEIXIN
			let opt = wx.getEnterOptionsSync();
			if (opt.scene == '1038' && opt.referrerInfo.appId == 'wxef277996acc166c3') {
				// 代表从收银台小程序返回
				let extraData = opt.referrerInfo.extraData;
				if (!extraData) {
					// "当前通过物理按键返回，未接收到返参，建议自行查询交易结果";
					this.getOrderInfo();
				} else {
					if (extraData.code == 'success') {
						// "支付成功";
						this.getOrderInfo();
					} else if (extraData.code == 'cancel') {
						// "支付已取消";
						this.$util.Tips({
							title: this.$t(`支付已取消`)
						});
					} else {
						// "支付失败：" + extraData.errmsg;
						this.$util.Tips({
							title: this.$t(`支付失败：${extraData.errmsg}`)
						});
					}
				}
			}
			// #endif
		} else {
			toLogin();
		}
	},
	onHide: function () {
		this.privateFormImagesVisible = false; this.closeOrderFormImages();
		uni.hideLoading();
		this.fulfillmentRevision++;
		this.fulfillmentLoading = false;
		this.isClose = true;
		this.againGeneration++;
		this.againPending = false;
		this.againNavigating = false;
		this.againGroups = [];
	},
	onUnload() {
		this.privateFormImagesVisible = false; this.closeOrderFormImages();
		this.receiptActive = false; this.receiptGeneration++; this.receiptPending = null;
		uni.hideLoading();
		this.fulfillmentRevision++;
		this.fulfillmentLoading = false;
		this.againGeneration++;
		this.againPending = false;
		this.againNavigating = false;
		this.againGroups = [];
	},
	onReady: function () {
		// #ifdef H5
		this.$nextTick(function () {
			const clipboard = new ClipboardJS('.copy-data');
			clipboard.on('success', () => {
				this.$util.Tips({
					title: this.$t(`复制成功`)
				});
			});
			const address = new ClipboardJS('.copy-refund-msg');
			address.on('success', () => {
				this.$util.Tips({
					title: this.$t(`复制成功`)
				});
			});
		});

		// #endif
	},
	/**
	 * 用户点击右上角分享
	 */
	// #ifdef MP
	onShareAppMessage: function () {
		let that = this;
		userShare();
		return {
			title: that.giftModalData.gift_mark || '',
			imageUrl: that.mpGiftImg || '',
			path: '/pages/goods/receive_gift/index?id=' + this.giftModalData.id + '&spid=' + this.$store.state.app.uid
		};
	},
	onShareTimeline() {
		let that = this;
		userShare();
		return {
			title: that.giftModalData.gift_mark,
			query: {
				id: that.id,
				spid: that.uid || 0
			},
			path: '/pages/goods/receive_gift/index?id=' + this.giftModalData.id + '&spid=' + this.$store.state.app.uid,
			imageUrl: that.mpGiftImg
		};
	},
	// #endif
	methods: {
		rootOrderStatusText,
		strictSavedMoney,
		addSavedMoney,
		// #ifdef H5
		// 微信分享；
		setOpenShare: function () {
			let that = this;
			if (that.$wechat.isWeixin()) {
				let configAppMessage = {
					desc: that.giftModalData.gift_mark,
					title: that.giftModalData.title,
					link: window.location.protocol + '//' + window.location.host + '/pages/goods/receive_gift/index?id=' + that.giftModalData.id + '&spid=' + that.$store.state.app.uid,
					imgUrl: that.mpGiftImg
				};
				that.$wechat.wechatEvevt(['updateAppMessageShareData', 'updateTimelineShareData', 'onMenuShareAppMessage', 'onMenuShareTimeline'], configAppMessage);
			}
		},
		// #endif
		qrR(res) {
			this.codeSrc = res;
		},
		shareH5() {
			this.H5ShareBox = true;
		},
		cancelRefundOrder(orderId) {
			let that = this;
			uni.showModal({
				title: that.$t(`取消申请`),
				content: that.$t(`您确认放弃此次申请吗`),
				success: (res) => {
					if (res.confirm) {
						cancelRefundOrder(that.order_id)
							.then((res) => {
								return that.$util.Tips(
									{
										title: that.$t(`操作成功`),
										icon: 'success'
									},
									{
										tab: 4,
										url: '/pages/users/user_return_list/index'
									}
								);
							})
							.catch((err) => {
								return that.$util.Tips({
									title: err
								});
							});
					}
				}
			});
		},
		refundInput() {
			uni.navigateTo({
				url: `/pages/goods/order_refund_goods/index?orderId=` + this.order_id
			});
		},
		getCustomerType() {
			getCustomerType()
				.then((res) => {
					this.customerInfo = res.data;
				})
				.catch((err) => {
					this.$util.Tips({
						title: err
					});
				});
		},
		goGoodCall() {
			getCustomer(`/pages/extension/customer_list/chat?orderId=${this.order_id}&isReturn=${this.isReturn}`);
		},
		openSubcribe(e) {
			let page = e;
			// #ifndef MP
			uni.navigateTo({
				url: page
			});
			// #endif
			// #ifdef MP
			uni.showLoading({
				title: this.$t(`正在加载中`)
			});
			openOrderRefundSubscribe()
				.then((res) => {
					uni.hideLoading();
					uni.navigateTo({
						url: page
					});
				})
				.catch((err) => {
					uni.hideLoading();
				});
			// #endif
		},
		goReturnGoods() {},
		/**
		 * 拨打电话
		 */
		makePhone: function () {
			uni.makePhoneCall({
				phoneNumber: this.system_store.phone
			});
		},
		/**
		 * 打开地图
		 *
		 */
		showMaoLocation: function () {
			if (!this.system_store.latitude || !this.system_store.longitude)
				return this.$util.Tips({
					title: this.$t(`缺少经纬度信息无法查看地图`)
				});
			uni.openLocation({
				latitude: parseFloat(this.system_store.latitude),
				longitude: parseFloat(this.system_store.longitude),
				scale: 8,
				name: this.system_store.name,
				address: this.system_store.address + this.system_store.detailed_address,
				success: function () {}
			});
		},
		/**
		 * 打开支付组件
		 *
		 */
		pay_open: function () {
			uni.navigateTo({
				url: `/pages/goods/cashier/index?order_id=${encodeURIComponent(this.orderInfo.order_id)}&from_type=order${this.savedMarketplaceFormsV1 ? '&marketplace=1' : ''}`
			});
			// this.pay_close = true;
			// this.pay_order_id = this.orderInfo.order_id;
			// this.totalPrice = this.orderInfo.pay_price;
		},
		/**
		 * 支付失败回调
		 *
		 */
		pay_fail: function () {
			this.pay_close = false;
			this.pay_order_id = '';
		},
		/**
		 * 登录授权回调
		 *
		 */
		onLoadFun: function () {
			this.getOrderInfo();
			this.getUserInfo();
		},
		/**
		 * 获取用户信息
		 *
		 */
		getUserInfo: function () {
			let that = this;
			const actor = this.$store.state.app.uid;
			getUserInfo().then((res) => {
				if (actor !== this.$store.state.app.uid || !this.isLogin) return;
				that.userInfo = res.data;
				// #ifdef H5
				that.payMode[2].number = res.data.now_money;
				// #endif
				// #ifdef APP-PLUS
				that.payMode[2].number = res.data.now_money;
				// #endif
				// #ifdef MP
				that.payMode[1].number = res.data.now_money;
				// #endif
				that.$set(that, 'payMode', that.payMode);
			});
		},
		/**
		 * 获取订单详细信息
		 *
		 */
		clearSavedFulfillment() {
			this.marketplaceCancelPending = false; this.marketplaceCancelFeedback = '';
			this.receiptGeneration++; this.receipt = null; this.receiptReadIdentity = '';
			this.receiptPending = null; this.receiptPendingIdentity = ''; this.receiptSending = false;
			this.receiptPreparing = false; this.receiptNeedsRefresh = false; this.receiptError = ''; this.receiptFeedback = '';
			uni.hideLoading();
			this.fulfillmentRevision++;
			this.fulfillmentIdentity = '';
			this.fulfillmentView = null;
			this.fulfillmentKind = '';
			this.fulfillmentLoading = false;
			this.fulfillmentError = '';
			this.fulfillmentPendingPage = 1;
			this.orderInfo = { help_info: {}, system_store: {}, _status: {} };
			this.cartInfo = [];
			this.status = {};
			this.savedMarketplaceFormGroups = [];
			this.customForm = [];
			this.againGeneration++;
			this.againPending = false;
			this.againNavigating = false;
			this.againGroups = [];
			this.invoiceData = null;
			this.invoice_func = false;
			this.special_invoice = false;
			this.aleartStatus = false;
			this.invShow = false;
			this.moreBtn = false;
			this.giftModalShow = false;
			this.giftData = null;
			this.giftModalData = null;
			this.userInfo = {};
			this.is_gift = 0;
			this.isGoodsReturn = false;
		},
		receiptIdentity() { return String(this.$store.state.app.uid) + ':' + String(this.$store.state.app.authRevision) + ':' + this.order_id; },
		receiptCurrent(identity) { return this.receiptActive && this.isLogin && identity === this.receiptIdentity(); },
		async getReceiptInfo() {
			if (!this.receiptActive || this.isReturn || !this.isLogin) return false;
			const identity = this.receiptIdentity(); const revision = ++this.fulfillmentRevision;
			this.fulfillmentLoading = true; this.fulfillmentError = '';
			uni.showLoading({ title: this.$t('正在加载中') });
			try {
				const response = await getCustomerFulfillmentPackage(this.order_id);
				if (revision !== this.fulfillmentRevision || !this.receiptCurrent(identity)) return false;
				const data = readCustomerReceipt(response.data, this.order_id); const child = data.package;
				this.receipt = data; this.receiptReadIdentity = identity;
				if (this.receiptNeedsRefresh && this.receiptFeedback) this.receiptFeedback = this.receiptFeedback.replace(/，最新资料尚未读回，请重新加载$/, '');
				this.receiptNeedsRefresh = false;
				this.fulfillmentView = { kind: 'child', order: child, package: child }; this.fulfillmentKind = 'child';
				this.orderInfo = child; this.cartInfo = []; this.pid = child.root_order_id; this.status = {}; this.system_store = {};
				this.invoiceData = null; this.savedMarketplaceFormGroups = []; this.customForm = []; this.isGoodsReturn = false;
				return true;
			} catch (error) {
				if (revision === this.fulfillmentRevision && this.receiptCurrent(identity))
					this.fulfillmentError = typeof error === 'string' ? error : (error && (error.message || error.msg) || '包裹读取失败，请重试');
				return false;
			} finally {
				if (revision === this.fulfillmentRevision && this.receiptCurrent(identity)) { this.fulfillmentLoading = false; uni.hideLoading(); }
			}
		},
		confirmReceipt(draft) {
			if (this.receiptPending || this.receiptSending || this.receiptPreparing || this.fulfillmentLoading || this.fulfillmentError
				|| this.receiptNeedsRefresh || this.receiptReadIdentity !== this.receiptIdentity() || !this.isLogin) return;
			const identity = this.receiptIdentity(); const generation = this.receiptGeneration; const receipt = this.receipt;
			let intent;
			try { intent = prepareReceiptIntent(this.receipt, draft); }
			catch (error) { this.receiptError = error && error.message || '请核对本次收货选择'; return; }
			this.receiptPreparing = true; this.receiptError = '';
			uni.showModal({
				title: '确认本次收货',
				content: '包裹 ' + intent.childNo + '，本次确认 ' + intent.quantity + ' 件。请确认这些商品已实际收到。',
				confirmText: '确认提交', cancelText: '返回核对',
				success: result => {
					if (generation !== this.receiptGeneration || !this.receiptCurrent(identity)) return;
					this.receiptPreparing = false;
					if (!result.confirm) return;
					if (this.receipt !== receipt || this.fulfillmentLoading || this.fulfillmentError || this.receiptReadIdentity !== identity) {
						this.receiptError = '收货资料已刷新，请重新选择本次范围'; return;
					}
					this.receiptPending = intent; this.receiptPendingIdentity = identity; this.receiptFeedback = '';
					this.sendReceipt();
				},
				fail: () => {
					if (generation === this.receiptGeneration && this.receiptCurrent(identity)) {
						this.receiptPreparing = false; this.receiptError = '确认窗口未完成，请重新核对';
					}
				}
			});
		},
		async sendReceipt() {
			const intent = this.receiptPending;
			if (!intent || this.receiptPendingIdentity !== this.receiptIdentity() || !this.receiptActive || !this.isLogin
				|| this.receiptSending || this.fulfillmentLoading) return;
			const identity = this.receiptIdentity(); const generation = ++this.receiptGeneration;
			this.receiptSending = true; this.receiptError = '';
			try {
				if (intent.childNo !== this.order_id || intent.wire !== JSON.stringify(intent.body)) throw new Error('本次请求保存内容不一致');
				const response = await receiveCustomerFulfillmentPackage(intent.childNo, intent.body);
				if (generation !== this.receiptGeneration || !this.receiptCurrent(identity) || this.receiptPending !== intent) return;
				const result = readReceiptResult(response.data, intent);
				this.receiptPending = null; this.receiptNeedsRefresh = true;
				this.receiptFeedback = receiptResultFeedback(result);
				const refreshed = await this.getReceiptInfo();
				if (!refreshed && this.receiptNeedsRefresh && generation === this.receiptGeneration && this.receiptCurrent(identity))
					this.receiptFeedback += '，最新资料尚未读回，请重新加载';
			} catch (error) {
				if (generation === this.receiptGeneration && this.receiptCurrent(identity) && this.receiptPending === intent)
					this.receiptError = '本次提交结果尚未确认。' + (typeof error === 'string' ? error : (error && (error.message || error.msg) || '请重试本次请求'));
			} finally { if (generation === this.receiptGeneration && this.receiptCurrent(identity)) this.receiptSending = false; }
		},
		changeFulfillmentPage(page) {
			if (this.fulfillmentKind === 'root' && !this.fulfillmentLoading) this.getOrderInfo(page);
		},
		openMarketplaceAftersale() {
			if (this.fulfillmentLoading || this.fulfillmentError || this.receiptSending || !this.fulfillmentView || !['root', 'child'].includes(this.fulfillmentKind)) return;
			const id = this.fulfillmentKind === 'root' ? this.fulfillmentView.order.id : this.fulfillmentView.package.root_order_id;
			if (!Number.isSafeInteger(id) || id < 1 || id > 2147483647) return;
			uni.navigateTo({ url: '/pages/goods/marketplace_aftersale/index?root_id=' + id });
		},
		openMarketplaceReview() {
			if (!this.isLogin || this.fulfillmentLoading || this.fulfillmentError || this.receiptSending || !this.fulfillmentView || !['root', 'child'].includes(this.fulfillmentKind)) return;
			if (this.fulfillmentKind === 'child') {
				const number = this.fulfillmentView.package.root_order_no;
				if (typeof number !== 'string' || !number) return;
				uni.navigateTo({ url: '/pages/goods/order_details/index?order_id=' + encodeURIComponent(number) });
				return;
			}
			const id = this.fulfillmentView.order.id;
			if (!Number.isSafeInteger(id) || id < 1 || id > 2147483647) return;
			uni.navigateTo({ url: '/pages/goods/marketplace_review/index?root_id=' + id });
		},
		retryFulfillment() { this.getOrderInfo(this.fulfillmentPendingPage); },
		viewFulfillmentPackage(child) {
			if (this.fulfillmentLoading || this.fulfillmentError || this.fulfillmentKind !== 'root' || !this.fulfillmentView.children ||
				!this.fulfillmentView.children.list.some(row => row.id === child.id && row.order_id === child.order_id)) return;
			uni.navigateTo({ url: '/pages/goods/order_details/index?fulfillment_package=1&order_id=' + encodeURIComponent(child.order_id) });
		},
		getOrderInfo: function (page) {
			if (!this.isReturn && (this.packageEntry || this.fulfillmentKind === 'child')) return this.getReceiptInfo();
			let that = this;
			const identity = String(this.$store.state.app.uid) + ':' + this.order_id;
			if (identity !== this.fulfillmentIdentity) {
				this.fulfillmentView = null;
				this.fulfillmentKind = '';
				this.fulfillmentIdentity = identity;
				this.marketplaceCancelPending = false;
				this.marketplaceCancelFeedback = '';
			}
			const revision = ++this.fulfillmentRevision;
			const requestedPage = page === undefined ? (this.fulfillmentView && this.fulfillmentView.children ? this.fulfillmentView.children.page : 1) : page;
			this.fulfillmentPendingPage = requestedPage;
			this.fulfillmentLoading = true;
			this.fulfillmentError = '';
			uni.showLoading({
				title: this.$t(`正在加载中`)
			});
			let obj = '';
			if (that.isReturn) {
				obj = refundOrderDetail(this.order_id);
			} else {
				obj = getSavedOrderFulfillment(this.order_id, requestedPage, 20);
			}
			obj
				.then((res) => {
					if (revision !== this.fulfillmentRevision || identity !== String(this.$store.state.app.uid) + ':' + this.order_id || !this.isLogin) return;
					const view = that.isReturn ? { kind: 'legacy', order: res.data } : readOrderFulfillment(res.data, true);
					if (view.kind === 'child') { this.packageEntry = true; return this.getReceiptInfo(); }
					if (view.kind !== 'legacy') {
						if ((view.kind === 'root' && view.order.order_id !== this.order_id) || (view.kind === 'child' && view.package.order_id !== this.order_id)) throw new Error('订单身份不一致，请重新加载');
						if (view.kind === 'root') this.applySavedCustomForm(view.order);
						this.orderInfo = view.order;
						this.cartInfo = view.kind === 'root' ? view.order.cartInfo : [];
						this.pid = view.kind === 'root' ? view.order.pid : view.package.root_order_id;
						this.evaluate = 0;
						this.system_store = view.kind === 'root' ? view.order.system_store : {};
						this.invoiceData = view.kind === 'root' ? view.order.invoice : null;
						this.isGoodsReturn = false;
						this.status = {};
						this.fulfillmentView = view;
						this.fulfillmentKind = view.kind;
						this.fulfillmentLoading = false;
						uni.hideLoading();
						return;
					}
					this.fulfillmentKind = 'legacy';
					this.fulfillmentView = view;
					this.fulfillmentLoading = false;
					if (res.data.pid && res.data.pid == -1) {
						that.$util.Tips(
							{
								title: this.$t(`订单信息不存在`)
							},
							'/pages/goods/order_list/index'
						);
					}
					let _type = res.data._status._type;
					uni.hideLoading();
					that.$set(that, 'orderInfo', res.data);
					that.applySavedCustomForm(that.orderInfo);
					that.$set(that, 'cartInfo', res.data.cartInfo);
					that.$set(that, 'pid', res.data.pid);
					that.$set(that, 'split', res.data.split);
					that.$set(that, 'evaluate', _type == 3 ? 3 : 0);
					that.$set(that, 'system_store', res.data.system_store);
					that.$set(that, 'invoiceData', res.data.invoice);
					if (res.data.is_gift) {
						let giftStatus = res.data.gift_uid === this.$store.state.app.uid;
						that.$set(that, 'is_gift', giftStatus ? 2 : 1);
						uni.setNavigationBarTitle({
							title: '礼物详情'
						});
						this.giftData = {
							avatar: res.data.avatar,
							gift_mark: res.data.gift_mark,
							nickname: res.data.nickname
						};
						this.giftModalData = {
							image: res.data.cartInfo[0].productInfo.image,
							title: res.data.cartInfo[0].productInfo.store_name,
							message: res.data.gift_mark,
							id: res.data.id,
							avatar: res.data.avatar,
							nickname: res.data.nickname,
							gift_mark: res.data.gift_mark,
							code: res.data.gift_code
						};
						//#ifdef H5
						that.setOpenShare();
						//#endif
					} 
					if(!res.data.is_gift || this.is_gift == 2) {
						uni.hideShareMenu();
					}
					if (that.invoiceData) {
						that.invoiceData.pay_price = res.data.pay_price;
					}
					that.$set(that, 'invoice_func', res.data.invoice_func);
					that.$set(that, 'special_invoice', res.data.special_invoice);
					that.$set(that, 'routineContact', Number(res.data.routine_contact_type));
					// #ifdef H5 || APP-PLUS
					this.$nextTick(() => {
						that.val = HTTP_REQUEST_URL + '/pages/admin/order_cancellation/index?verify_code=' + that.orderInfo.verify_code;
					});
					// #endif
					// #ifdef MP
					if (!that.orderInfo.code) {
						this.$nextTick(() => {
							that.val = HTTP_REQUEST_URL + '/pages/admin/order_cancellation/index?verify_code=' + that.orderInfo.verify_code;
						});
					} else {
						this.codeSrc = that.orderInfo.code || '';
					}
					// #endif
					if (this.orderInfo.refund_status != 0) {
						this.isGoodsReturn = true;
					} else {
						this.isReturn = 0;
					}
					if (that.invoice_id && !that.invoiceData) {
						that.invChecked = that.invoice_id || '';
						this.invoiceApply();
					}
					that.payMode.map((item) => {
						if (item.value == 'weixin') {
							item.payStatus = res.data.pay_weixin_open ? true : false;
						}
						if (item.value == 'alipay') {
							item.payStatus = res.data.ali_pay_status ? true : false;
						}
						if (item.value == 'yue') {
							item.payStatus = res.data.yue_pay_status == 1 ? true : false;
						}
						if (item.value == 'friend') {
							item.payStatus = res.data.friend_pay_status == 1 ? true : false;
						}
						if (item.value == 'allinpay') {
							item.payStatus = res.data.pay_allin_open == 1 ? true : false;
						}
					});

					that.getOrderStatus();
				})
				.catch((err) => {
					if (revision !== this.fulfillmentRevision || identity !== String(this.$store.state.app.uid) + ':' + this.order_id || !this.isLogin) return;
					this.fulfillmentLoading = false;
					this.fulfillmentError = typeof err === 'string' ? err : (err && err.message || '订单读取失败，请重试');
					uni.hideLoading();
					if (!that.isReturn) return;
					that.$util.Tips(
						{
							title: err
						},
						'/pages/goods/order_list/index'
					);
				});
		},
		// 不开发票
		invCancel() {
			this.invChecked = '';
			this.invTitle = this.$t(`不开发票`);
			this.invShow = false;
		},
		// 选择发票
		invSub(id) {
			this.invChecked = id;
			let data = {
				order_id: this.order_id,
				invoice_id: this.invChecked
			};
			makeUpinvoice(data)
				.then((res) => {
					uni.showToast({
						title: this.$t(`申请成功`),
						icon: 'success'
					});
					this.invShow = false;
					this.aleartStatus = true;
					this.getOrderInfo();
				})
				.catch((err) => {
					uni.showToast({
						title: err,
						icon: 'none'
					});
				});
		},
		// 关闭发票
		invClose() {
			this.invShow = false;
			this.getInvoiceList();
		},
		//申请开票
		invoiceApply() {
			this.urlQuery = `&specialInvoice=${this.userInfo.special_invoice}`;
			this.getInvoiceList();
			this.moreBtn = false;
			this.invShow = true;
		},
		aleartStatusChange() {
			this.moreBtn = false;
			this.aleartStatus = true;
		},
		getInvoiceList() {
			uni.showLoading({
				title: this.$t(`正在加载中`)
			});
			invoiceList()
				.then((res) => {
					uni.hideLoading();
					this.invList = res.data.map((item) => {
						item.id = item.id.toString();
						return item;
					});
					const result = this.invList.find((item) => item.id == this.invChecked);
					if (result) {
						let name = '';
						name += result.header_type === 1 ? this.$t(`个人`) : this.$t(`企业`);
						name += result.type === 1 ? this.$t(`普通`) : this.$t(`专用`);
						name += this.$t(`发票`);
						this.invTitle = name;
					}
				})
				.catch((err) => {
					uni.showToast({
						title: err,
						icon: 'none'
					});
				});
		},
		more() {
			this.moreBtn = !this.moreBtn;
		},
		/**
		 *
		 * 剪切订单号
		 */
		// #ifndef H5
		copy: function (text) {
			let that = this;
			uni.setClipboardData({
				data: text
			});
		},
		// #endif
		// #ifndef H5
		copyAddress() {
			uni.setClipboardData({
				data: this.orderInfo._status.refund_name + this.orderInfo._status.refund_phone + this.orderInfo._status.refund_address,
				success() {
					uni.Tips({
						title: this.$t(`复制成功`),
						icon: 'success'
					});
				}
			});
		},
		// #endif
		applySavedCustomForm(order) {
			this.customForm = [];
			this.savedMarketplaceFormGroups = [];
			const version = order.marketplace_trade_version;
			if (version === 1 || version === '1') {
				// This is a saved public snapshot, not editable definitions. Keep its original hash and indexes.
				const labels = ['text', 'number', 'email', 'data', 'time', 'id', 'phone', 'img'];
				if (!Array.isArray(order.custom_form)) throw new Error(this.$t(`订单表单信息不完整`));
				this.savedMarketplaceFormGroups = order.custom_form.map(group => {
					if (!group || !/^[1-9][0-9]*$/.test(String(group.product_id)) || typeof group.product_name !== 'string' ||
						typeof group.definition_sha256 !== 'string' || !/^[0-9a-f]{64}$/.test(group.definition_sha256) ||
						!Array.isArray(group.fields)) throw new Error(this.$t(`订单表单信息不完整`));
					const fields = group.fields.map(field => {
						if (!field || typeof field.field_index !== 'number' || field.field_index % 1 !== 0 ||
							field.field_index < 0 || field.field_index > 9 || typeof field.title !== 'string' ||
							!labels.includes(field.label) || typeof field.status !== 'boolean' ||
							(field.label === 'img' ? !Array.isArray(field.value) || field.value.some(image => typeof image !== 'string') :
								typeof field.value !== 'string')) throw new Error(this.$t(`订单表单信息不完整`));
						return {field_index: field.field_index, title: field.title, label: field.label, status: field.status,
							value: Array.isArray(field.value) ? field.value.slice() : field.value};
					}).filter(field => field.value !== '' && !(Array.isArray(field.value) && field.value.length === 0));
					return {product_id: group.product_id, product_name: group.product_name,
						definition_sha256: group.definition_sha256, fields};
				}).filter(group => group.fields.length);
				return;
			}
			if (version !== undefined && version !== null && version !== 0 && version !== '0') {
				throw new Error(this.$t(`订单表单版本暂不可用`));
			}
			// Retain the historical flat display rule and copy path.
			let arr = [];
			order.custom_form.map((i) => {
				if (i.value != '') arr.push(i);
			});
			this.customForm = arr;
		},
		savedMarketplaceFormText() {
			return this.savedMarketplaceFormGroups.map(group => group.product_name + '\n' + group.fields.map(field =>
				(field.field_index + 1) + '. ' + field.title + '：' +
				(Array.isArray(field.value) ? field.value.join('\n') : field.value)).join('\n')).join('\n\n');
		},
		copyText(text) {
			let str = '';
			if (text) {
				str = text;
			} else if (this.savedMarketplaceFormsV1) {
				str = this.savedMarketplaceFormText();
			} else {
				this.customForm.map((e) => {
					if (e.label !== 'img') {
						str += e.title + e.value;
					}
				});
			}
			uni.setClipboardData({
				data: str
			});
		},
		// #ifdef H5
		copyAddress() {
			// let msg =
			// return msg
		},
		// #endif
		/**
		 * 打电话
		 */
		goTel: function () {
			uni.makePhoneCall({
				phoneNumber: this.orderInfo.delivery_id
			});
		},
		/**
		 * 设置底部按钮
		 *
		 */
		getOrderStatus: function () {
			let orderInfo = this.orderInfo || {},
				_status = orderInfo._status || {
					_type: 0
				},
				status = {};
			let type = parseInt(_status._type),
				delivery_type = orderInfo.delivery_type,
				seckill_id = orderInfo.seckill_id ? parseInt(orderInfo.seckill_id) : 0,
				bargain_id = orderInfo.bargain_id ? parseInt(orderInfo.bargain_id) : 0,
				discount_id = orderInfo.discount_id ? parseInt(orderInfo.discount_id) : 0,
				combination_id = orderInfo.combination_id ? parseInt(orderInfo.combination_id) : 0;
			status = {
				type: type == 9 ? -9 : type,
				class_status: 0
			};
			if (type == 1 && combination_id > 0) status.class_status = 1; //查看拼团
			if (type == 2 && delivery_type == 'express') status.class_status = 2; //查看物流
			if (type == 2) status.class_status = 3; //确认收货
			if (type == 4 || type == 0) status.class_status = 4; //删除订单
			if (!seckill_id && !bargain_id && !combination_id && !discount_id && !orderInfo.type && (type == 3 || type == 4)) status.class_status = 5; //再次购买
			this.$set(this, 'status', status);
		},
		/**
		 * 去拼团详情
		 *
		 */
		goJoinPink: function () {
			uni.navigateTo({
				url: '/pages/activity/goods_combination_status/index?id=' + this.orderInfo.pink_id
			});
		},
		/**
		 * 再此购买
		 *
		 */
		goOrderConfirm: function () {
			if (this.againPending || this.againNavigating || this.againGroups.length) return;
			this.againPending = true;
			const generation = this.againGeneration;
			orderAgain(this.orderInfo.order_id).then(res => {
				if (generation !== this.againGeneration) return;
				this.againPending = false;
				const purchase = parseAgainPurchase(res.data);
				if (purchase.legacyUrl) return this.openAgainConfirmation(purchase.legacyUrl);
				if (purchase.groups.length === 1) return this.openAgainConfirmation(againConfirmationUrl(purchase.groups[0]));
				this.againGroups = purchase.groups;
			}).catch(error => {
				if (generation !== this.againGeneration) return;
				this.againPending = false;
				if (generation === this.againGeneration) this.$util.Tips({title: storefrontMessage(error)});
			});
		},
		selectAgainStore(merId) {
			if (this.againPending || this.againNavigating) return;
			const group = this.againGroups.find(row => row.mer_id === merId);
			if (!group) return;
			const url = againConfirmationUrl(group);
			this.againGroups = [];
			this.openAgainConfirmation(url);
		},
		cancelAgainStore() {
			this.againGroups = [];
		},
		openAgainConfirmation(url) {
			if (this.againNavigating) return;
			this.againNavigating = true;
			uni.navigateTo({
				url,
				fail: () => {
					this.againNavigating = false;
					this.$util.Tips({title: this.$t(`页面暂未打开，请稍后再试`)});
				}
			});
		},
		confirmOrder(orderId) {
			if (this.fulfillmentKind !== 'legacy' || this.savedMarketplaceFormsV1) return;
			let that = this;
			// #ifdef MP
			if (wx.openBusinessView && this.orderInfo.order_shipping_open && this.orderInfo.trade_no && this.orderInfo.uid == this.orderInfo.pay_uid) {
				uni.showLoading({
					title: this.$t(`加载中`)
				});
				wx.openBusinessView({
					businessType: 'weappOrderConfirm',
					extraData: {
						transaction_id: this.orderInfo.trade_no
					},
					success() {},
					fail(err) {
						uni.hideLoading();
						return that.$util.Tips({
							title: err.errMsg
						});
					},
					complete() {
						uni.hideLoading();
					}
				});
			} else {
				this.defaultTake(orderId);
			}
			// #endif
			// #ifndef MP
			this.defaultTake(orderId);
			// #endif
		},
		defaultTake(orderId) {
			if (this.fulfillmentKind !== 'legacy' || this.savedMarketplaceFormsV1) return;
			let that = this;
			uni.showModal({
				title: that.$t(`确认收货`),
				content: that.$t(`为保障权益，请收到货确认无误后，再确认收货`),
				success: (res) => {
					if (res.confirm) {
						orderTake(orderId ? orderId : that.order_id)
							.then((res) => {
								return that.$util.Tips(
									{
										title: that.$t(`操作成功`),
										icon: 'success'
									},
									function () {
										that.getOrderInfo();
									}
								);
							})
							.catch((err) => {
								return that.$util.Tips({
									title: err
								});
							});
					}
				}
			});
		},
		/**
		 *
		 * 删除订单
		 */
		delOrder() {
			let that = this;
			uni.showModal({
				title: this.$t(`删除订单`),
				content: this.$t(`确定删除该订单`),
				success: (res) => {
					if (res.confirm) {
						(that.isReturn ? refundOrderDel : orderDel)(that.order_id)
							.then((res) => {
								if (that.status.type == -2) {
									return that.$util.Tips(
										{
											title: that.$t(`删除成功`),
											icon: 'success'
										},
										{
											tab: 5,
											url: '/pages/users/user_return_list/index'
										}
									);
								} else {
									return that.$util.Tips(
										{
											title: that.$t(`删除成功`),
											icon: 'success'
										},
										{
											tab: 5,
											url: '/pages/goods/order_list/index'
										}
									);
								}
							})
							.catch((err) => {
								return that.$util.Tips({
									title: err
								});
							});
					} else if (res.cancel) {
						return that.$util.Tips({
							title: that.$t(`已取消`)
						});
					}
				}
			});
		},
		cancelMarketplaceOrder() {
			if (!this.isLogin || this.isReturn == 1 || this.fulfillmentKind !== 'root' || this.fulfillmentLoading || this.fulfillmentError ||
				this.marketplaceCancelPending || ![0, '0'].includes(this.orderInfo.paid) || ![0, '0'].includes(this.orderInfo.is_cancel)) return;
			const orderNo = this.orderInfo.order_id;
			if (typeof orderNo !== 'string' || !orderNo) return;
			const identity = this.receiptIdentity();
			const sameOrder = () => this.receiptCurrent(identity) && this.order_id === orderNo;
			this.marketplaceCancelPending = true;
			this.marketplaceCancelFeedback = '';
			uni.showModal({
				title: this.$t('取消订单'),
				content: this.$t('确认取消这张未付款订单？取消后将释放本单占用的库存。'),
				confirmText: this.$t('确认取消'),
				cancelText: this.$t('保留订单'),
				success: async (res) => {
					if (!sameOrder()) return;
					if (!res.confirm) { this.marketplaceCancelPending = false; return; }
					if (this.isReturn == 1 || this.fulfillmentKind !== 'root' || this.fulfillmentLoading || this.fulfillmentError ||
						![0, '0'].includes(this.orderInfo.paid) || ![0, '0'].includes(this.orderInfo.is_cancel)) {
						this.marketplaceCancelPending = false; this.getOrderInfo(); return;
					}
					try {
						await orderCancel(orderNo);
						if (sameOrder()) this.marketplaceCancelFeedback = this.$t('取消请求已处理，请以最新订单状态为准。');
					} catch (error) {
						if (sameOrder()) this.marketplaceCancelFeedback = typeof error === 'string' ? error :
							(error && (error.msg || error.message)) || this.$t('取消结果未确认，请重新读取订单状态。');
					} finally {
						if (sameOrder()) { this.marketplaceCancelPending = false; this.getOrderInfo(); }
					}
				},
				fail: () => {
					if (sameOrder()) {
						this.marketplaceCancelPending = false;
						this.marketplaceCancelFeedback = this.$t('未打开取消确认，请重试。');
					}
				}
			});
		},
		cancelOrder() {
			let self = this;
			uni.showModal({
				title: this.$t(`提示`),
				content: this.$t(`确认取消该订单`),
				success: function (res) {
					if (res.confirm) {
						orderCancel(self.orderInfo.order_id)
							.then((data) => {
								// #ifndef MP
								self.$util.Tips(
									{
										title: data.msg
									},
									{
										tab: 3
									}
								);
								// #endif
								// #ifdef MP
								self.$util.Tips(
									{
										title: data.msg
									},
									'/pages/goods/order_list/index'
								);
								// #endif
							})
							.catch(() => {
								self.getOrderInfo();
							});
					} else if (res.cancel) {
					}
				}
			});
		}
	}
};
</script>
<style scoped lang="scss">
.fulfillment-again { display: flex; align-items: center; justify-content: center; box-sizing: border-box; width: 100%; max-width: 100%; min-width: 0; min-height: 44px; height: auto; margin: 0; padding: 12px 18px; font-size: 28rpx; line-height: 1.4; text-align: center; white-space: normal; }
.refund-tip {
	font-size: 24rpx;
	margin-top: 10rpx;
	color: var(--view-theme);

	.iconfont {
		font-size: 24rpx;
		margin-right: 6rpx;
	}
}

.refund-tip1 {
	font-size: 24rpx;
	color: var(--view-theme);

	.iconfont {
		font-size: 24rpx;
		margin-right: 6rpx;
	}
}

.qs-btn {
	width: auto;
	height: 60rpx;
	text-align: center;
	line-height: 60rpx;
	border-radius: 50rpx;
	font-size: 27rpx;
	white-space: nowrap;
	padding: 0 26rpx;
	color: #666;
	border: 1px solid #ccc;
	margin-right: 20rpx;
}

.refund-input {
	position: fixed;
	bottom: 0;
	left: 0;
	width: 100%;
	border-radius: 16rpx 16rpx 0 0;
	background-color: #fff;
	z-index: 99;
	padding: 40rpx 0 70rpx 0;
	transition: all 0.3s cubic-bezier(0.25, 0.5, 0.5, 0.9);
	transform: translate3d(0, 100%, 0);

	.refund-input-title {
		font-size: 32rpx;
		margin-bottom: 60rpx;
		color: #282828;
	}

	.refund-input-sty {
		border: 1px solid #ddd;
		padding: 20rpx 20rpx;
		border-radius: 40rpx;
		width: 100%;
		margin: 20rpx 65rpx;
	}

	.input-msg {
		display: flex;
		flex-direction: column;
		justify-content: center;
		align-items: center;
		position: relative;
		margin: 0 65rpx;

		.iconfont {
			position: absolute;
			font-size: 32rpx;
			color: #282828;
			top: 8rpx;
			right: -30rpx;
		}
	}

	.refund-bth {
		display: flex;
		margin: 0 65rpx;
		margin-top: 20rpx;
		justify-content: space-around;
		width: 100%;

		.close-refund {
			padding: 24rpx 80rpx;
			border-radius: 80rpx;
			color: #fff;
			background-color: #ccc;
		}

		.submit-refund {
			width: 100%;
			padding: 24rpx 0rpx;
			text-align: center;
			border-radius: 80rpx;
			color: #fff;
			background-color: var(--view-theme);
		}
	}
}

.refund-input.on {
	transform: translate3d(0, 0, 0);
}

.goodCall {
	color: var(--view-theme);
	text-align: center;
	width: 100%;
	height: 86rpx;
	padding: 0 30rpx;
	border-top: 1rpx solid #eee;
	font-size: 30rpx;
	line-height: 86rpx;
	background: #fff;

	.icon-kefu {
		font-size: 36rpx;
		margin-right: 15rpx;
	}

	/* #ifdef MP */
	button {
		display: flex;
		align-items: center;
		justify-content: center;
		height: 86rpx;
		font-size: 30rpx;
		color: var(--view-theme);
	}

	/* #endif */
}

.order-details {
	padding-bottom: calc(15rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
	padding-bottom: calc(15rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
}

.order-details .header {
	padding: 0 30rpx;
	height: 150rpx;
	display: flex;
	align-items: center;
	flex-wrap: nowrap;
}

.order-details .header.on {
	background-color: #666 !important;
}

.order-details .header .pictrue {
	width: 110rpx;
	height: 110rpx;
}

.order-details .header .pictrue image {
	width: 100%;
	height: 100%;
}

.order-details .header .data {
	color: rgba(255, 255, 255, 0.8);
	font-size: 24rpx;
	margin-left: 27rpx;
}

.order-details .header .data.on {
	margin-left: 0;
}

.order-details .header .data .state {
	font-size: 30rpx;
	font-weight: bold;
	color: #fff;
	margin-bottom: 7rpx;
}

.order-details .header .data .time {
	margin-left: 3rpx;
}

.order-details .nav {
	background-color: #fff;
	font-size: 26rpx;
	color: #282828;
	padding: 25rpx 0;
}

.order-details .nav .navCon {
	padding: 0 40rpx;
}

.order-details .nav .on {
	color: var(--view-theme);
}

.order-details .nav .progress {
	padding: 0 65rpx;
	margin-top: 10rpx;
}

.order-details .nav .progress .line {
	width: 100rpx;
	height: 2rpx;
	background-color: #939390;
}

.order-details .nav .progress .iconfont {
	font-size: 25rpx;
	color: #939390;
	margin-top: -2rpx;
}

.order-details .address {
	font-size: 26rpx;
	color: #868686;
	background-color: #fff;
	padding: 35rpx 30rpx;
	margin-bottom: 12rpx;
	.icon {
		.iconfont {
			width: 44rpx;
			height: 44rpx;
			background: var(--view-minorColorT);
			font-size: 20rpx;
			border-radius: 50%;
			text-align: center;
			line-height: 44rpx;
			color: var(--view-theme);
			margin-left: 26rpx;
		}
	}
}

.order-details .address .name {
	font-size: 30rpx;
	color: #282828;
	margin-bottom: 15rpx;
}

.order-details .address .name .phone {
	margin-left: 40rpx;
}

.order-details .line {
	width: 100%;
	height: 3rpx;
}

.order-details .line image {
	width: 100%;
	height: 100%;
	display: block;
}

.order-details .wrapper {
	background-color: #fff;
	margin-top: 12rpx;
	padding: 30rpx;
}

.order-details .wrapper .acea-row {
	display: flex;
	flex-wrap: nowrap;
}

.order-details .wrapper .item {
	font-size: 28rpx;
	color: #282828;
}

.order-details .wrapper .item ~ .item {
	margin-top: 20rpx;
	white-space: normal;
	word-break: break-all;
	word-wrap: break-word;
}

.order-details .wrapper .item .conter {
	color: #868686;
	width: 480srpx;
	display: flex;
	flex-wrap: nowrap;
	justify-content: flex-end;
}

.order-details .wrapper .item .conter .copy {
	font-size: 20rpx;
	color: #333;
	border-radius: 3rpx;
	border: 1rpx solid #666;
	padding: 3rpx 15rpx;
	margin-left: 24rpx;
	white-space: nowrap;
}

.order-details .wrapper .actualPay {
	border-top: 1rpx solid #eee;
	margin-top: 30rpx;
	padding-top: 30rpx;
	display: flex;
	align-items: center;

	.pay-people {
		display: flex;
		align-items: center;

		image {
			width: 40rpx;
			height: 40rpx;
			border-radius: 50%;
		}

		.pay-nickname {
			margin-right: 20rpx;
			padding: 0 10rpx;
		}
	}
}

.order-details .wrapper .actualPay .money {
	font-weight: bold;
	font-size: 30rpx;
}

.order-details .footer {
	width: 100%;
	position: fixed;
	display: flex;
	justify-content: space-between;
	bottom: 0;
	left: 0;
	background-color: #fff;
	padding: 20rpx 30rpx;
	padding-bottom: calc(20rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
	padding-bottom: calc(20rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
	box-sizing: border-box;
	border-top: 1px solid #f5f5f5;

	.more {
		// position: absolute;
		left: 30rpx;
		font-size: 26rpx;
		color: #333;

		.icon-xiangshang {
			margin-left: 6rpx;
			font-size: 22rpx;
		}
	}

	.right-btn {
		display: flex;
	}

	.more-box {
		color: #333;
		position: absolute;
		left: 30rpx;
		background-color: #fff;
		padding: 18rpx 24rpx;
		border-radius: 4rpx;
		font-size: 28rpx;
		-webkit-box-shadow: 0px 0px 3px 0px rgba(200, 200, 200, 0.75);
		-moz-box-shadow: 0px 0px 3px 0px rgba(200, 200, 200, 0.75);
		box-shadow: 0px 0px 3px 0px rgba(200, 200, 200, 0.75);
		bottom: 110rpx;
		/* #ifdef APP-PLUS */
		bottom: calc(110rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		bottom: calc(110rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/

		/* #endif */
		.more-btn {
			color: #333;
			padding: 4rpx;
			z-index: 9999;
		}
	}

	.more-box:before {
		content: '';
		width: 0rpx;
		height: 0rpx;
		border-top: 20rpx solid rgba(200, 200, 200, 0.4);
		border-bottom: 0rpx solid transparent;
		border-top: 20rpx solid rgba(200, 200, 200, 0.4);
		border-left: 20rpx solid rgba(0, 0, 0, 0);
		border-right: 20rpx solid rgba(0, 0, 0, 0);
		position: absolute;
		bottom: -20rpx;
		left: 20rpx;
	}

	.more-box::after {
		content: '';
		width: 0rpx;
		height: 0rpx;
		border-top: 20rpx solid #fff;
		border-bottom: 0rpx solid rgba(0, 0, 0, 0);
		border-left: 20rpx solid rgba(0, 0, 0, 0);
		border-right: 20rpx solid rgba(0, 0, 0, 0);
		position: absolute;
		bottom: -18rpx;
		left: 20rpx;
		z-index: 9;
	}
}

.order-details .footer .bnt {
	width: 200rpx;
	height: 60rpx;
	text-align: center;
	line-height: 60rpx;
	border-radius: 50rpx;
	color: #fff;
	font-size: 27rpx;
}

.order-details .footer .bnt.refundBnt {
	width: 210rpx;
}

.order-details .footer .bnt.cancel {
	color: #666;
	border: 1rpx solid #ccc;
}

.order-details .footer .bnt ~ .bnt {
	margin-left: 18rpx;
}

.order-details .writeOff {
	background-color: #fff;
	margin-top: 13rpx;
	padding-bottom: 30rpx;
}

.order-details .writeOff .title {
	font-size: 30rpx;
	color: #282828;
	height: 87rpx;
	border-bottom: 1px solid #f0f0f0;
	padding: 0 30rpx;
	line-height: 87rpx;
}

.order-details .writeOff .grayBg {
	background-color: #f2f5f7;
	width: 590rpx;
	height: 384rpx;
	border-radius: 20rpx 20rpx 0 0;
	margin: 50rpx auto 0 auto;
	padding-top: 55rpx;
	position: relative;
}

.order-details .writeOff .grayBg .written {
	position: absolute;
	top: 0;
	right: 0;
	width: 60rpx;
	height: 60rpx;
}

.order-details .writeOff .grayBg .written image {
	width: 100%;
	height: 100%;
}

.order-details .writeOff .grayBg .pictrue {
	width: 290rpx;
	height: 290rpx;
	margin: 0 auto;
}

.order-details .writeOff .grayBg .pictrue image {
	width: 100%;
	height: 100%;
	display: block;
}

.order-details .writeOff .gear {
	width: 590rpx;
	height: 30rpx;
	margin: 0 auto;
}

.order-details .writeOff .gear image {
	width: 100%;
	height: 100%;
	display: block;
}

.order-details .writeOff .num {
	background-color: #f0c34c;
	width: 590rpx;
	height: 84rpx;
	color: #282828;
	font-size: 48rpx;
	margin: 0 auto;
	border-radius: 0 0 20rpx 20rpx;
	text-align: center;
	padding-top: 4rpx;
}

.order-details .writeOff .rules {
	margin: 46rpx 30rpx 0 30rpx;
	border-top: 1px solid #f0f0f0;
	padding-top: 10rpx;
}

.order-details .writeOff .rules .item {
	margin-top: 20rpx;
}

.order-details .writeOff .rules .item .rulesTitle {
	font-size: 28rpx;
	color: #282828;
}

.order-details .writeOff .rules .item .rulesTitle .iconfont {
	font-size: 30rpx;
	color: #333;
	margin-right: 8rpx;
	margin-top: 5rpx;
}

.order-details .writeOff .rules .item .info {
	font-size: 28rpx;
	color: #999;
	margin-top: 7rpx;
}

.order-details .writeOff .rules .item .info .time {
	margin-left: 20rpx;
}

.order-details .map {
	height: 86rpx;
	font-size: 30rpx;
	color: #282828;
	line-height: 86rpx;
	border-bottom: 1px solid #f0f0f0;
	margin-top: 13rpx;
	background-color: #fff;
	padding: 0 30rpx;
}

.order-details .map .place {
	font-size: 26rpx;
	// width: 176rpx;
	height: 50rpx;
	border-radius: 25rpx;
	line-height: 50rpx;
	text-align: center;
	padding: 0 10rpx;
}

.order-details .map .place .iconfont {
	font-size: 27rpx;
	height: 27rpx;
	line-height: 27rpx;
	margin: 2rpx 3rpx 0 0;
}

.order-details .address .name .iconfont {
	font-size: 34rpx;
	margin-left: 10rpx;
}

.refund {
	padding: 0 30rpx 30rpx;
	margin: 12rpx 0;
	background-color: #fff;

	.title {
		display: flex;
		align-items: center;
		font-size: 30rpx;
		color: #333;
		height: 86rpx;
		border-bottom: 1px solid #f5f5f5;

		image {
			width: 32rpx;
			height: 32rpx;
			margin-right: 10rpx;
		}
	}

	.con {
		padding-top: 25rpx;
		font-size: 28rpx;
		color: #868686;
	}
}
</style>

<style scoped lang="scss">
.invoice-mask {
	background-color: #999999;
	opacity: 1;
}

.more-mask {
	background-color: #fff;
	opacity: 0;
	left: 300rpx;
}

.goodCall {
	color: var(--view-theme);
	text-align: center;
	width: 100%;
	height: 86rpx;
	padding: 0 30rpx;
	border-bottom: 1rpx solid #eee;
	font-size: 30rpx;
	line-height: 86rpx;
	background: #fff;

	.icon-kefu {
		font-size: 36rpx;
		margin-right: 15rpx;
	}

	/* #ifdef MP */
	button {
		display: flex;
		align-items: center;
		justify-content: center;
		height: 86rpx;
		font-size: 30rpx;
		color: var(--view-theme);
	}

	/* #endif */
}

.order-details .header {
	padding: 0 30rpx;
	height: 150rpx;
}

.order-details .header.on {
	background-color: #666 !important;
}

.order-details .header .pictrue {
	width: 110rpx;
	height: 110rpx;
}

.order-details .header .pictrue image {
	width: 100%;
	height: 100%;
}

.order-details .header .data {
	color: rgba(255, 255, 255, 0.8);
	font-size: 24rpx;
	margin-left: 27rpx;
}

.order-details .header .data.on {
	margin-left: 0;
}

.order-details .header .data .state {
	font-size: 30rpx;
	font-weight: bold;
	color: #fff;
	margin-bottom: 7rpx;
}

.order-details .header .data .time {
	margin-left: 20rpx;
}

.order-details .nav {
	background-color: #fff;
	font-size: 26rpx;
	color: #282828;
	padding: 25rpx 0;
}

.order-details .nav .navCon {
	padding: 0 40rpx;
}

.order-details .nav .on {
	color: var(--view-theme);
}

.order-details .nav .progress {
	padding: 0 65rpx;
	margin-top: 10rpx;
}

.order-details .nav .progress .line {
	width: 100rpx;
	height: 2rpx;
	background-color: #939390;
}

.order-details .nav .progress .iconfont {
	font-size: 25rpx;
	color: #939390;
	margin-top: -2rpx;
}

.order-details .address {
	font-size: 26rpx;
	color: #868686;
	background-color: #fff;
	padding: 35rpx 30rpx;
}

.order-details .address .name {
	font-size: 30rpx;
	color: #282828;
	margin-bottom: 15rpx;
}

.order-details .address .name .phone {
	margin-left: 40rpx;
}

.order-details .line {
	width: 100%;
	height: 3rpx;
}

.order-details .line image {
	width: 100%;
	height: 100%;
	display: block;
}

.order-details .wrapper {
	background-color: #fff;
	margin-top: 12rpx;
	padding: 30rpx;
}

.order-details .wrapper .item {
	font-size: 28rpx;
	color: #282828;
}

.order-details .wrapper .item ~ .item {
	margin-top: 20rpx;
}

.order-details .wrapper .item .conter {
	color: #868686;
	// width: 380rpx;
	text-align: justify;
	flex: 1;
	word-break: break-all;
}

.order-details .wrapper .item .conter .upload {
	padding-bottom: 36rpx;
}

.order-details .wrapper .diy-from-title {
	white-space: nowrap;
	width: 5em;
}

.order-details .wrapper .item .conter .upload .pictrue {
	margin: 22rpx 23rpx 0 0;
	width: 156rpx;
	height: 156rpx;
	position: relative;
	font-size: 24rpx;
	color: #bbb;
}

.order-details .wrapper .item .conter .copy {
	font-size: 20rpx;
	color: #333;
	height: max-content;
	border-radius: 3rpx;
	border: 1rpx solid #666;
	padding: 3rpx 15rpx;
	margin-left: 24rpx;
}

.order-details .wrapper .actualPay {
	border-top: 1rpx solid #eee;
	margin-top: 30rpx;
	padding-top: 30rpx;
}

.order-details .wrapper .actualPay .money {
	font-weight: bold;
	font-size: 30rpx;
}

.order-details .footer .bnt {
	width: 160rpx;
	height: 60rpx;
	text-align: center;
	line-height: 60rpx;
	border-radius: 50rpx;
	color: #fff;
	font-size: 27rpx;
}

.order-details .footer .bnt ~ .bnt {
	margin-left: 18rpx;
}

.order-details .writeOff {
	background-color: #fff;
	margin-top: 13rpx;
	padding-bottom: 30rpx;
}

.order-details .writeOff .title {
	font-size: 30rpx;
	color: #282828;
	height: 87rpx;
	border-bottom: 1px solid #f0f0f0;
	padding: 0 30rpx;
	line-height: 87rpx;
}

.order-details .writeOff .grayBg {
	background-color: #f2f5f7;
	width: 590rpx;
	height: 384rpx;
	border-radius: 20rpx 20rpx 0 0;
	margin: 50rpx auto 0 auto;
	padding-top: 55rpx;
	position: relative;
}

.order-details .writeOff .grayBg .written {
	position: absolute;
	top: 0;
	right: 0;
	width: 60rpx;
	height: 60rpx;
}

.order-details .writeOff .grayBg .written image {
	width: 100%;
	height: 100%;
}

.order-details .writeOff .grayBg .pictrue {
	width: 290rpx;
	height: 290rpx;
	margin: 0 auto;
}

.order-details .writeOff .grayBg .pictrue image {
	width: 100%;
	height: 100%;
	display: block;
}

.order-details .writeOff .gear {
	width: 590rpx;
	height: 30rpx;
	margin: 0 auto;
}

.order-details .writeOff .gear image {
	width: 100%;
	height: 100%;
	display: block;
}

.order-details .writeOff .num {
	background-color: #f0c34c;
	width: 590rpx;
	height: 84rpx;
	color: #282828;
	font-size: 48rpx;
	margin: 0 auto;
	border-radius: 0 0 20rpx 20rpx;
	text-align: center;
	padding-top: 4rpx;
}

.order-details .writeOff .rules {
	margin: 46rpx 30rpx 0 30rpx;
	border-top: 1px solid #f0f0f0;
	padding-top: 10rpx;
}

.order-details .writeOff .rules .item {
	margin-top: 20rpx;
}

.order-details .writeOff .rules .item .rulesTitle {
	font-size: 28rpx;
	color: #282828;
}

.order-details .writeOff .rules .item .rulesTitle .iconfont {
	font-size: 30rpx;
	color: #333;
	margin-right: 8rpx;
	margin-top: 5rpx;
}

.order-details .writeOff .rules .item .info {
	font-size: 28rpx;
	color: #999;
	margin-top: 7rpx;
}

.order-details .writeOff .rules .item .info .time {
	margin-left: 20rpx;
}

.order-details .map {
	height: 86rpx;
	font-size: 30rpx;
	color: #282828;
	line-height: 86rpx;
	border-bottom: 1px solid #f0f0f0;
	margin-top: 13rpx;
	background-color: #fff;
	padding: 0 30rpx;
}

.order-details .map .place {
	font-size: 26rpx;
	// width: 176rpx;
	height: 50rpx;
	border-radius: 25rpx;
	line-height: 50rpx;
	text-align: center;
}

.order-details .map .place .iconfont {
	font-size: 27rpx;
	height: 27rpx;
	line-height: 27rpx;
	margin: 2rpx 3rpx 0 0;
}

.order-details .address .name .iconfont {
	font-size: 34rpx;
	margin-left: 10rpx;
}

.refund {
	padding: 0 30rpx 30rpx;
	margin: 12rpx 0;
	background-color: #fff;

	.title {
		display: flex;
		align-items: center;
		font-size: 30rpx;
		color: #333;
		height: 86rpx;
		border-bottom: 1px solid #f5f5f5;

		image {
			width: 32rpx;
			height: 32rpx;
			margin-right: 10rpx;
		}
	}

	.con {
		padding-top: 25rpx;
		font-size: 28rpx;
		color: #868686;
	}
}

.refund-msg {
	background-color: #fff;
	padding: 20rpx 40rpx;
	font-size: 28rpx;

	.refund-msg-user {
		font-weight: bold;
		margin-bottom: 10rpx;

		.copy-refund-msg {
			font-size: 10px;
			border-radius: 1px;
			border: 0.5px solid #666;
			padding: 1px 7px;
			margin-left: 12px;
		}

		.name {
			margin-right: 20rpx;
		}
	}

	.refund-address {
		color: #868686;
	}
}

.copy-text {
	width: max-content;
	font-size: 10px;
	border-radius: 1px;
	border: 0.5px solid #666;
	padding: 1px 7px;
	margin-left: auto;
}

.marketplace-saved-form-product + .marketplace-saved-form-product {
	border-top: 1rpx solid #eee;
	margin-top: 28rpx;
	padding-top: 28rpx;
}

.marketplace-saved-form-product-name {
	font-weight: 600;
	word-break: break-all;
}

.marketplace-saved-form-value {
	color: #868686;
	word-break: break-all;
	white-space: pre-wrap;
}

.marketplace-saved-form-copy {
	box-sizing: border-box;
	width: 100%;
	max-width: 100%;
	min-height: 64rpx;
	margin: 24rpx 0 0;
	padding: 10rpx 20rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	text-align: center;
	font-family: inherit;
	font-size: 26rpx;
	line-height: 1.4;
	color: #333;
	background-color: #fff;
	border: 1rpx solid #666;
	border-radius: 3rpx;
}

.marketplace-saved-form-copy::after {
	border: 0;
}

.upload .pictrue {
	display: inline-block;
	margin: 22rpx 17rpx 20rpx 0;
	width: 156rpx;
	height: 156rpx;
	color: #bbb;
}

.upload .legacy-form-picture { vertical-align: top; }
.legacy-form-image-note { display: block; font-size: 24rpx; line-height: 1.5; color: #666; overflow-wrap: anywhere; }
.upload .pictrue image {
	width: 100%;
	height: 100%;
}
.gift-box {
	background: #ffffff;
	margin: 20rpx 0;
	.user-msg {
		padding: 28rpx 30rpx;
	}
	image {
		width: 36rpx;
		height: 36rpx;
		border-radius: 70rpx 70rpx 70rpx 70rpx;
		border: 2rpx solid #ffffff;
	}
	.nickname {
		font-weight: 400;
		font-size: 28rpx;
		color: #ae5a2a;
	}
	.gift-mark {
		border-top: 1px solid #f0f0f0;
		font-weight: 400;
		font-size: 28rpx;
		color: #333333;
		padding: 28rpx 30rpx;
	}
}
.share-box {
	z-index: 1000;
	position: fixed;
	left: 0;
	top: 0;
	width: 100%;
	height: 100%;

	image {
		width: 100%;
		height: 100%;
	}
}
.canvas {
	width: 750rpx;
	height: 1108rpx;
	z-index: 9999;
	position: absolute;
	bottom: 40000rpx;
	right: 30000rpx;
}
</style>
<style scoped lang="scss">
.marketplace-aftersale-navigation { box-sizing: border-box; width: 100%; min-width: 0; padding: 24rpx 30rpx; background: #fff; }
.marketplace-aftersale-navigation-button { box-sizing: border-box; display: flex; align-items: center; justify-content: center; width: 100%; min-width: 0; max-width: 100%; min-height: 44px; height: auto; margin: 0; padding: 10px 16px; border: 1px solid #ddd; border-radius: 6px; color: var(--view-theme, #333); background: #fff; font-size: 28rpx; line-height: 1.4; text-align: center; white-space: normal; }
.marketplace-aftersale-navigation-button::after { border: 0; }
.marketplace-review-navigation-button { margin-top: 16rpx; }
.marketplace-order-cancel-button { margin-top: 16rpx; }
.marketplace-cancel-feedback { box-sizing: border-box; width: 100%; padding: 12px 30rpx; color: #555; font-size: 26rpx; line-height: 1.6; overflow-wrap: anywhere; }
.order-details .footer button.marketplace-again-button {
	display: flex;
	align-items: center;
	justify-content: center;
	box-sizing: border-box;
	min-width: 0;
	max-width: 100%;
	height: auto;
	min-height: 60rpx;
	margin: 0;
	padding: 8rpx 16rpx;
	line-height: 1.4;
	text-align: center;
	white-space: normal;
}
.order-details .footer button.marketplace-again-button::after {
	border: 0;
}
.order-details .footer .bnt ~ button.marketplace-again-button {
	margin-left: 18rpx;
}
</style>
