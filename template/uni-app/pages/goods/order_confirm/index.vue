<template>
	<view :style="colorStyle">
		<view class='order-submission'>
			<view v-if="marketplaceV1" class="marketplace-checkout-notice">
				<view>{{marketplaceConfirmation.store.name}}</view>
				<view v-if="!marketplaceCreateAvailable">{{$t(`当前可确认金额和收货信息，暂未开放提交订单。`)}}</view>
				<view v-if="marketplaceSubmitState === 'submitting'">{{$t(`正在提交本次订单，请稍候。`)}}</view>
				<view v-if="marketplaceSubmitState === 'uncertain'">
					<view>{{marketplaceRestoredUnknown ? $t(`提交结果待确认。刷新后仅可查询原订单，不会再次提交。`) : $t(`提交结果尚未确认，本次信息已锁定。重试将发送上一次提交的相同内容。`)}}</view>
					<view v-if="marketplaceAttempt && marketplaceAttempt.generation !== marketplaceEditGeneration">{{$t(`重试原提交，不包含尚未提交的调整内容。`)}}</view>
					<button v-if="!marketplaceRestoredUnknown" class="marketplace-adjust" :disabled="marketplaceLookupPending" @tap="adjustMarketplaceOrder">{{$t(`调整信息`)}}</button>
				</view>
				<view v-if="marketplaceEditGeneration > 0 && marketplaceSubmitState === 'editing'">{{$t(`原提交仍可能随后完成；若订单已生成，将显示原订单，调整内容不会另生成一单。`)}}</view>
			</view>
			<view v-if="marketplaceNotice || marketplaceCleanupFailed" class="marketplace-checkout-notice" aria-live="polite">
				<view>{{marketplaceCleanupFailed ? $t(`本机结算资料尚未清理成功，暂不能提交新订单。原订单仍可查询。`) : marketplaceNotice}}</view>
				<button v-if="orderKey && marketplaceRestoredUnknown" class="marketplace-adjust" :disabled="marketplaceLookupPending || marketplaceRestoring" @tap="lookupRestoredMarketplaceOrder">{{$t(`查询提交结果`)}}</button>
				<button v-if="marketplaceResumeUnavailable || marketplaceRestoredUnknown" class="marketplace-adjust" @tap="leaveMarketplaceConfirmation">{{$t(`返回购物车`)}}</button>
			</view>
			<view v-if="marketplaceDraft && marketplaceDraft.kind === 'full' && (!marketplaceV1 || marketplaceResumeUnavailable)" class="marketplace-checkout-notice marketplace-saved-context">
				<view>{{$t(`以下是本人本机保存的信息，尚未作为当前报价或可提交确认。`)}}</view>
				<view>{{marketplaceDraft.view.store.name}}</view>
				<view v-for="(product,index) in marketplaceDraft.view.products" :key="index">{{product.name}} · {{product.sku}} × {{product.qty}}</view>
				<view>{{$t(`备注`)}}：{{marketplaceDraft.fields.mark}}</view>
				<view>{{$t(`联系人`)}}：{{marketplaceDraft.fields.contacts}} {{marketplaceDraft.fields.contactsTel}}</view>
				<view>{{$t(`留言`)}}：{{marketplaceDraft.fields.gift_mark}}</view>
				<view>{{$t(`付款方式`)}}：{{marketplaceDraft.fields.payType}}</view>
				<view>{{$t(`收货地址`)}}：{{marketplaceDraft.selection.address.mode === 'none' ? $t(`未选择`) : marketplaceDraft.selection.address.snapshot.real_name}} {{marketplaceDraft.selection.address.snapshot.detail}}</view>
				<view>{{$t(`发票`)}}：{{marketplaceDraft.selection.invoice.mode === 'none' ? $t(`不开发票`) : marketplaceDraft.selection.invoice.snapshot.name}}</view>
				<view v-for="group in marketplaceDraft.forms" :key="String(group.product_id)">
					<view>{{group.product_name}}</view>
					<view v-for="field in group.fields" :key="field.field_index">{{field.title}}：{{field.label === 'img' ? field.value.join('、') : field.value}}</view>
				</view>
			</view>
			<view class="allAddress" :style="store_self_mention && is_shipping ? '':'padding-top:10rpx'"
				v-if="!virtual_type && (!is_gift || is_gift == 2)">
				<view class="nav acea-row">
					<view class="item font-num" :class="shippingType == 0 ? 'on' : 'on2'" @tap="addressType(0)"
						v-if='!marketplaceV1 && store_self_mention && is_shipping'>
						<view class="before">
							{{$t(`快递配送`)}}
						</view>
					</view>
					<view class="item font-num" :class="shippingType == 1 ? 'on' : 'on2'" @tap="addressType(1)"
						v-if='!marketplaceV1 && store_self_mention && is_shipping'>
						<view class="before">
							{{$t(`到店自提`)}}
						</view>
					</view>
				</view>
				<view class="add-title acea-row row-between-wrapper" v-if="!store_self_mention || !is_shipping"
					@click.prevent="openList">
					<view class="acea-row row-middle">
						<view class="icon" :class="shippingType==1?'orange':'red'">
							{{shippingType==0?'商城配送':'门店自提'}}
						</view>
						<view class="text add-text line1" v-if="shippingType==0">{{$t(`由平台为您提供配送服务`)}}</view>
						<view class="text add-text line1" v-if="shippingType==1">{{$t(`线上下单，到店自提`)}}</view>
					</view>

					<view class="text">{{shippingType == 0 ? $t('切换地址') : $t('切换门店')}} <text class='iconfont icon-jiantou'></text>
					</view>
				</view>
				<view class='address acea-row row-between-wrapper' @tap='onAddress' v-if='shippingType == 0'>
					<view class='addressCon' v-if="addressInfo.real_name || ''">
						<view class='name'>{{addressInfo.real_name || ''}}
							<text class='phone'>{{addressInfo.phone || ''}}</text>
						</view>
						<view class="line1">
							<text class='default font-num'
								v-if="addressInfo.is_default">[{{$t(`默认`)}}]</text>{{addressInfo.province}}{{addressInfo.city}}{{addressInfo.district}}{{addressInfo.detail}}
						</view>
						<!-- <view class='setaddress'>设置收货地址</view> -->
					</view>
					<view class='addressCon' v-else>
						<view class='setaddress'>{{$t(`设置收货地址`)}}</view>
					</view>
					<view v-if="store_self_mention && is_shipping" class='iconfont icon-jiantou'></view>
				</view>
				<view class='address acea-row row-between-wrapper' v-else @tap="showStoreList">
					<block v-if="storeList.length>0">
						<view class='addressCon'>
							<view class='name'>{{system_store.name || ''}}
								<text class='phone'>{{system_store.phone || ''}}</text>
							</view>
							<view class="line1"> {{system_store.address}}{{", " + system_store.detailed_address}}</view>
						</view>
						<!-- <view class='iconfont icon-jiantou'></view> -->
					</block>
					<block v-else>
						<view>{{$t(`暂无门店信息`)}}</view>
					</block>
					<view class="icon acea-row row-middle" v-if="storeList.length>0">
						<view class="iconfont icon-dianhua" @click.stop="call(system_store.phone)"></view>
						<view class="iconfont icon-dingwei2" @click.stop="showMaoLocation(system_store)"></view>
					</view>
				</view>
				<view class='line'>
					<image src='/static/images/line.jpg'></image>
				</view>
			</view>
			<view v-if="giftData" class="gift-box">
				<view class="acea-row row-middle user-msg">
					<image class="avatar mr-12" :src="giftData.avatar" mode=""></image>
					<text class="nickname">{{ giftData.nickname}} 赠您一份礼物，请查收！</text>
				</view>
				<view class="line"></view>
				<view class="gift-mark" v-if="giftData.gift_mark">
					{{giftData.gift_mark}}
				</view>
			</view>
			<orderGoods :cartInfo="cartInfo" :is_confirm='true' :is_gift='is_gift' :shipping_type="shippingType"></orderGoods>
			<view v-if="is_gift == 1" class="gift-msg acea-row row-between-wrapper">
				<view>{{$t(`好友留言`)}}</view>
				<view class="discount">
					<input style="text-align: right;" :value="gift_mark" @input="setCheckoutText($event,'gift_mark')" :disabled="marketplacePreparing || marketplaceRestoredUnknown || marketplaceRestoring || (marketplaceV1 && !marketplaceCanEdit)" type="text" :placeholder="$t(`填写好友留言，最多40字`)"
						placeholder-class="placeholder"></input>
				</view>
			</view>
			<view class='wrapper' v-if="(!is_gift && is_gift == 2) && shippingType == 1">
				<view class="item acea-row row-between-wrapper">
					<view>{{$t(`用户姓名`)}}</view>
					<view class="discount">
						<input style="text-align: right;" :value="contacts" @input="setCheckoutText($event,'contacts')" :disabled="marketplacePreparing || marketplaceRestoredUnknown || marketplaceRestoring || (marketplaceV1 && !marketplaceCanEdit)" type="text" :placeholder="$t(`请输入姓名`)"
							placeholder-class="placeholder"></input>
					</view>
				</view>
				<view class="item acea-row row-between-wrapper">
					<view>{{$t(`联系电话`)}}</view>
					<view class="discount">
						<input style="text-align: right;" :value="contactsTel" @input="setCheckoutText($event,'contactsTel')" :disabled="marketplacePreparing || marketplaceRestoredUnknown || marketplaceRestoring || (marketplaceV1 && !marketplaceCanEdit)" type="text" :placeholder="$t(`请输入手机号`)"
							placeholder-class="placeholder"></input>
					</view>
				</view>
			</view>
			<view v-if="is_gift == 2" class="receive-btn" @click="receiveGift">
				立即领取
			</view>
			<view class='wrapper' v-if="!is_gift || is_gift == 1">
				<view class='item acea-row row-between-wrapper' @tap='couponTap'
					v-if="!marketplaceV1 && !pinkId && !BargainId && !combinationId && !seckillId&& !noCoupon && !discountId && !advanceId">
					<view>{{$t(`优惠券`)}}</view>
					<view class='discount'>
						{{couponTitle}}
						<text class='iconfont icon-jiantou'></text>
					</view>
				</view>
				<view class='item acea-row row-between-wrapper'
					v-if="!marketplaceV1 && !pinkId && !BargainId && !combinationId && !seckillId && !advanceId && integral_open">
					<view>{{$t(`积分抵扣`)}}</view>
					<view class='discount acea-row row-middle'>
						<view> {{useIntegral ? $t(`剩余积分`):$t(`当前积分`)}}
							<text class='num font-color'>{{integral || 0}}</text>
						</view>
						<checkbox-group @change="ChangeIntegral">
							<checkbox :disabled="integral<=0 && !useIntegral" :checked='useIntegral ? true : false' />
						</checkbox-group>
					</view>
				</view>
				<view v-if="invoice_func || special_invoice" class='item acea-row row-between-wrapper' @tap="goInvoice">
					<view>{{$t(`开具发票`)}}</view>
					<view class='discount'>
						{{invTitle}}
						<text class='iconfont icon-jiantou'></text>
					</view>
				</view>
				<view v-if="shippingType == 1">
					<view class="item acea-row row-between-wrapper">
						<view>{{$t(`用户姓名`)}}</view>
						<view class="discount">
							<input style="text-align: right;" :value="contacts" @input="setCheckoutText($event,'contacts')" :disabled="marketplacePreparing || marketplaceRestoredUnknown || marketplaceRestoring || (marketplaceV1 && !marketplaceCanEdit)" type="text" :placeholder="$t(`请输入姓名`)"
								placeholder-class="placeholder"></input>
						</view>
					</view>
					<view class="item acea-row row-between-wrapper">
						<view>{{$t(`联系电话`)}}</view>
						<view class="discount">
							<input style="text-align: right;" :value="contactsTel" @input="setCheckoutText($event,'contactsTel')" :disabled="marketplacePreparing || marketplaceRestoredUnknown || marketplaceRestoring || (marketplaceV1 && !marketplaceCanEdit)" type="text" :placeholder="$t(`请输入手机号`)"
								placeholder-class="placeholder"></input>
						</view>
					</view>
				</view>
				<view class='item' v-if="textareaStatus">
					<view>{{$t(`备注说明`)}}</view>
					<view class="mark" v-if="!coupon.coupon && !inputTrip" @click="inputTripClick">
						<view :class="{'mark-msg': mark}" v-text="mark || $t(`填写备注信息，100字以内`)"></view>
					</view>
					<textarea placeholder-class='placeholder' :placeholder="$t(`填写备注信息，100字以内`)"
						v-if="!coupon.coupon && inputTrip" @input='bindHideKeyboard' :focus="focus" @blur="inputTrip = false"
						:value="mark" :disabled="marketplacePreparing || marketplaceRestoredUnknown || marketplaceRestoring || (marketplaceV1 && !marketplaceCanEdit)" :maxlength="150" name="mark">
						</textarea>
				</view>
			</view>
			<view v-if="marketplaceV1">
				<view class='wrapper' v-for="group in marketplaceFormRenderGroups" :key="group.renderKey">
					<view class='item'>{{group.product_name}}</view>
					<view class='item acea-row row-between-wrapper' v-for="item in group.fields" :key="item.renderKey">
						<view>
							<span v-if="item.status" style="color: red;">*</span>
							<span v-else style="marginLeft: 8px;"></span>
							{{item.title}}
						</view>
						<view v-if="item.label=='text' || item.label=='number' || item.label=='email'" class="confirm">
							<input type="text" :placeholder="$t(`请填写`)+item.title" :value="item.value" :disabled="!marketplaceCanEdit"
								@input="setMarketplaceFormValue($event,group.product_id,group.definition_sha256,item.field_index)" />
						</view>
						<view v-if="item.label=='data'" class="uni-list">
							<view class="uni-list-cell"><view class="uni-list-cell-db">
								<picker mode="date" :value="item.value"
									:disabled="!marketplaceCanEdit"
									@change="setMarketplaceFormValue($event,group.product_id,group.definition_sha256,item.field_index)">
									<view v-if="item.value == ''" class="fontC">{{date+item.title}}<text class='iconfont icon-jiantou'></text></view>
									<view v-else class="uni-input">{{item.value}}</view>
								</picker>
							</view></view>
						</view>
						<view v-if="item.label=='time'">
							<picker mode="time" :value="item.value" start="00:00" end="23:59"
								:disabled="!marketplaceCanEdit"
								@change="setMarketplaceFormValue($event,group.product_id,group.definition_sha256,item.field_index)">
								<view v-if="item.value == ''" class="fontC">{{time+item.title}}<text class='iconfont icon-jiantou'></text></view>
								<view v-else>{{item.value}}</view>
							</picker>
						</view>
						<view v-if="item.label=='id'" class="confirm">
							<input type="idcard" :placeholder="$t(`请填写`)+item.title" :value="item.value" :disabled="!marketplaceCanEdit"
								@input="setMarketplaceFormValue($event,group.product_id,group.definition_sha256,item.field_index)" />
						</view>
						<view v-if="item.label=='phone'" class="confirm">
							<input type="tel" :placeholder="$t(`请填写`)+item.title" :value="item.value" :disabled="!marketplaceCanEdit"
								@input="setMarketplaceFormValue($event,group.product_id,group.definition_sha256,item.field_index)" />
						</view>
						<view v-if="item.label=='img'" class="confirmImg">
							<view class='list acea-row row-middle'>
								<view class='pictrue' v-for="(image,imageIndex) in item.value" :key="imageIndex">
									<image :src="orderFormImageSource(group,item,imageIndex)" class="img" @click="previewOrderFormImages(group,item,imageIndex)"></image>
									<text v-if="marketplaceCanEdit" class='iconfont icon-guanbi1 font-num del'
										@click="deleteMarketplaceFormImage(group.product_id,group.definition_sha256,item.field_index,imageIndex)"></text>
								</view>
								<view class='pictrue acea-row row-center-wrapper row-column bor'
									@click="uploadMarketplaceFormImage(group.product_id,group.definition_sha256,item.field_index)" v-if="marketplaceCanEdit && !marketplaceUploadPending && item.value.length < 8">
									<text class='iconfont icon-icon25201'></text>
									<view>{{$t(`上传图片`)}}</view>
								</view>
							</view>
						</view>
					</view>
				</view>
			</view>
			<view class='wrapper' v-if="!marketplaceV1 && confirm.length && (!is_gift || is_gift == 1)">
				<view class='item acea-row row-between-wrapper' v-for="(item,index) in confirm" :key="index">
					<view>
						<span v-if="item.status" style="color: red;">*</span>
						<span v-else style="marginLeft: 8px;"></span>
						{{ item.title }}
					</view>
					<!-- text -->
					<view v-if="item.label=='text'" class="confirm">
						<input type="text" :placeholder="$t(`请填写${item.title}`)" v-model="item.value" />
					</view>
					<!-- number -->
					<view v-if="item.label=='number'" class="confirm">
						<input type="number" :placeholder="$t(`请填写${item.title}`)" v-model="item.value" />
					</view>
					<!-- email -->
					<view v-if="item.label=='email'" class="confirm">
						<input type="text" :placeholder="$t(`请填写${item.title}`)" v-model="item.value" />
					</view>
					<!-- data -->
					<view v-if="item.label=='data'" class="uni-list">
						<view class="uni-list-cell">
							<view class="uni-list-cell-db">
								<picker mode="date" :value="item.value" @change="bindDateChange($event,index)">
									<view v-if="item.value == ''" class="fontC">{{date+item.title}}<text
											class='iconfont icon-jiantou'></text></view>
									<view v-else class="uni-input">{{item.value}}</view>
								</picker>
							</view>
						</view>
					</view>
					<!-- time -->
					<view v-if="item.label=='time'">
						<view>
							<view>
								<picker mode="time" :value="item.value" start="00:00" end="23:59"
									@change="bindTimeChange($event,index)">
									<view v-if="item.value == ''" class="fontC">{{time+item.title}}<text
											class='iconfont icon-jiantou'></text></view>
									<view>{{item.value}}</view>
								</picker>
							</view>
						</view>
					</view>
					<!-- id -->
					<view v-if="item.label=='id'" class="confirm">
						<input type="idcard" :placeholder="$t(`请填写`)+item.title" v-model="item.value" />
					</view>
					<!-- phone -->
					<view v-if="item.label=='phone'" class="confirm">
						<input type="tel" :placeholder="$t(`请填写`)+item.title" v-model="item.value" />
					</view>
					<!-- img -->
					<view v-if="item.label=='img'" class="confirmImg">
						<view class='list acea-row row-middle'>
							<view class='pictrue legacy-form-picture' v-for="(items,indexs) in item.value" :key="indexs">
								<image v-if="legacyFormImageSource(item,indexs)" :src="legacyFormImageSource(item,indexs)" class="img" @click="previewLegacyFormImages(item,indexs)" @error="legacyFormImageFailed(item,indexs)"></image>
								<text v-else class="legacy-form-image-note">{{legacyFormImageMessage(item,indexs)}}</text>
								<button class='legacy-form-delete' :disabled="legacyFormLocked" @click='DelPic(index,indexs)' :aria-label="$t(`删除图片`)"><text class="iconfont icon-guanbi1"></text></button>
							</view>
							<button class='pictrue legacy-form-upload bor' :disabled="legacyFormLocked" @click='uploadpic(index,item)'
								v-if="item.value.length < 8">
								<text class='iconfont icon-icon25201'></text>
								<view>{{$t(`上传图片`)}}</view>
							</button>
						</view>
					</view>
				</view>
			</view>
			<view class='moneyList' v-if="!is_gift || is_gift == 1">
				<view class='item acea-row row-between-wrapper'>
					<view>{{$t(`商品总价`)}}：</view>
					<view class='money'>
						{{$t(`￥`)}}{{allPrice || 0}}
					</view>
				</view>
				<view v-if="is_gift && priceGroup.giftPrice > 0" class='item acea-row row-between-wrapper'>
					<view>{{$t(`礼品附加费`)}}：</view>
					<view class='money'>
						{{$t(`￥`)}}{{parseFloat(priceGroup.giftPrice)}}
					</view>
				</view>
				<view class='item acea-row row-between-wrapper'
					v-if="priceGroup.storePostage > 0 || priceGroup.storePostageDiscount > 0">
					<view>{{$t(`配送运费`)}}：</view>
					<view class='money'>
						{{$t(`￥`)}}{{(parseFloat(priceGroup.storePostage)+parseFloat(priceGroup.storePostageDiscount)).toFixed(2)}}
					</view>
				</view>
				<view class='item acea-row row-between-wrapper'
					v-if="priceGroup.levelPrice > 0 && userInfo.vip && !pinkId && !BargainId && !combinationId && !seckillId && !discountId">
					<view>{{$t(`用户等级优惠`)}}：</view>
					<view class='money'>-{{$t(`￥`)}}{{parseFloat(priceGroup.levelPrice).toFixed(2)}}</view>
				</view>
				<view class='item acea-row row-between-wrapper'
					v-if="priceGroup.memberPrice > 0 && userInfo.vip && !pinkId && !BargainId && !combinationId && !seckillId && !discountId">
					<view>{{$t(`付费会员优惠`)}}：</view>
					<view class='money'>-{{$t(`￥`)}}{{parseFloat(priceGroup.memberPrice).toFixed(2)}}</view>
				</view>
				<view class='item acea-row row-between-wrapper' v-if="priceGroup.storePostageDiscount > 0">
					<view>{{$t(`会员运费优惠`)}}：</view>
					<view class='money'>-{{$t(`￥`)}}{{parseFloat(priceGroup.storePostageDiscount).toFixed(2)}}</view>
				</view>
				<view class='item acea-row row-between-wrapper' v-if="coupon_price > 0">
					<view>{{$t(`优惠券抵扣`)}}：</view>
					<view class='money'>-{{$t(`￥`)}}{{parseFloat(coupon_price).toFixed(2)}}</view>
				</view>
				<view class='item acea-row row-between-wrapper' v-if="integral_price > 0">
					<view>{{$t(`积分抵扣`)}}：</view>
					<view class='money'>-{{$t(`￥`)}}{{parseFloat(integral_price).toFixed(2)}}</view>
				</view>
			</view>
			<view v-if="!marketplaceV1 && legacyFormNotice" class="legacy-form-notice" role="status">{{legacyFormNotice}}</view>
			<view style='height:120rpx;'></view>
			<view class='footer acea-row row-between-wrapper' v-if="!is_gift || is_gift == 1">
				<view>{{$t(`合计`)}}:
					<text class='font-color'>{{$t(`￥`)}}{{totalPrice || 0}}</text>
				</view>
				<button v-if="marketplaceV1 || marketplaceResumeUnavailable || marketplaceRestoredUnknown" class="settlement marketplace-submit"
					:disabled="marketplaceRestoredUnknown || (marketplaceSubmitState === 'uncertain' ? !marketplaceCreateAvailable || marketplaceLookupPending || marketplaceCleanupFailed : !marketplaceCanSubmit)"
					:loading="marketplaceSubmitState === 'submitting'" @tap.stop="handleMarketplaceSubmit">{{marketplaceRestoredUnknown ? $t(`仅查询原订单`) : marketplaceSubmitState === 'uncertain' ? $t(`重试本次提交`) : $t(`提交订单`)}}</button>
				<button v-else-if="(valid_count>0&&!discount_id) || (valid_count==cartInfo.length&&discount_id)" class='settlement marketplace-submit' :disabled="legacyFormLocked" :loading="legacyFormSubmitPending" @tap.stop="Debounce(SubOrder())">{{$t(`提交订单`)}}</button>
				<view class='settlement bg-color-hui' style='z-index:100' v-else>{{$t(`提交订单`)}}</view>
			</view>
		</view>
		<view class="alipaysubmit" v-html="formContent"></view>
		<couponListWindow v-if="!marketplaceV1" :coupon='coupon' @ChangCouponsClone="ChangCouponsClone" :openType='openType' :cartId='cartId'
			@ChangCoupons="ChangCoupons"></couponListWindow>
		<addressWindow ref="addressWindow" @changeTextareaStatus="changeTextareaStatus" :news='news' :address='address'
			:pagesUrl="pagesUrl" :marketplace-checkout="marketplaceV1" @manage-address="openMarketplaceAddressList" @OnChangeAddress="OnChangeAddress" @changeClose="changeClose"
			@onHaveAddressList="onHaveAddressList"></addressWindow>
		<!-- #ifdef MP -->
		<!-- <authorize @onLoadFun="onLoadFun" :isAuto="isAuto" :isShowAuth="isShowAuth" @authColse="authColse"></authorize> -->
		<!-- #endif -->
		<home v-show="!invShow"></home>
		<invoice-picker :inv-show="invShow" :inv-list="invList" :inv-checked="invChecked" :is-special="special_invoice"
			:url-query="urlQuery" :marketplace-checkout="marketplaceV1" @manage-invoice="openMarketplaceInvoice" @inv-close="invClose" @inv-change="invChange" @inv-cancel="invCancel">
		</invoice-picker>
		<canvas canvas-id="canvas" v-if="canvasStatus"
			:style="{width: canvasWidth + 'px', height: canvasHeight + 'px',position: 'absolute',left:'-100000px',top:'-100000px'}"></canvas>
	</view>
</template>
<script>
	import {
		orderConfirm,
		getCouponsOrderPrice,
		orderCreate,
		orderConfirmation,
		orderConfirmationRestore,
		postOrderComputed,
		checkShipping,
		getGiftOrderDetail,
		orderReceiveGift
	} from '@/api/order.js';
	import {
		getAddressDefault,
		getAddressDetail,
		invoiceList,
		invoiceOrder
	} from '@/api/user.js';
	import {
		openPaySubscribe
	} from '@/utils/SubscribeMessage.js';
	import {
		storeListApi
	} from '@/api/store.js';
	import {
		CACHE_LONGITUDE,
		CACHE_LATITUDE
	} from '@/config/cache.js';
	import couponListWindow from '@/components/couponListWindow';
	import addressWindow from '@/components/addressWindow';
	import orderGoods from '@/components/orderGoods';
	import home from '@/components/home';
	import invoicePicker from '../components/invoicePicker/index.vue';
	import {
		toLogin
	} from '@/libs/login.js';
	import {
		mapGetters
	} from "vuex";
	// #ifdef MP
	import authorize from '@/components/Authorize';
	// #endif
	import payment from '@/components/payment';
	import orderFormImages from "@/mixins/marketplace-order-form-images.js";
	import {createOrderFormPrivateScope, openOrderFormPrivateScope, orderFormUploadReference, isLegacyOrderFormImage, legacyOrderFormUploadReference, legacyOrderFormReceipt} from "@/api/marketplace-order-form-private.js";
	import legacyOrderFormImages from '@/mixins/legacy-order-form-images.js';
	import request from '@/utils/request.js';
	import colors from "@/mixins/color";
	import Debounce from "@/mixins/debounce";
	import {
		initializeMarketplaceForms, retainMarketplaceForms, marketplaceFormAnswers,
		marketplaceFormIdentity, findMarketplaceFormField, firstMissingMarketplaceAnswer
	} from '@/utils/marketplace-order-form.js';
	import {bindMarketplaceSubmission, snapshotMarketplaceSubmission, marketplaceConfirmationResult, marketplaceCreateResult}
		from '@/utils/marketplace-order-submit.js';
	import {marketplaceOwnerUid, marketplacePageInstance, marketplaceConfirmationUrl, marketplaceIdentityCurrent,
		verifyMarketplaceOwner, readMarketplaceConfirmation, findMarketplaceUnknown, saveMarketplaceConfirmation,
		sealMarketplaceConfirmationAttempt, removeCreatedMarketplaceConfirmation} from '@/utils/marketplace-confirmation-draft.js';
	export default {
		components: {
			payment,
			invoicePicker,
			couponListWindow,
			addressWindow,
			orderGoods,
			home,
			// #ifdef MP
			authorize
			// #endif
		},
		mixins: [colors, Debounce, orderFormImages, legacyOrderFormImages],
		data() {
			const currentDate = this.getDate({
				format: true
			})
			return {
				marketplacePageInstance: marketplacePageInstance(),
				marketplaceOwner: null,
				marketplaceDraft: null,
				marketplaceStartedAt: 0,
				marketplaceRestoring: false,
				marketplaceRestoredUnknown: false,
				marketplaceNotice: '',
				marketplaceStorageFailed: false,
				marketplaceHistoryPending: false,
				marketplaceSelection: {address: {mode: 'none', id: '0', status: 'none', snapshot: {}}, invoice: {mode: 'none', id: '0', status: 'none', snapshot: {}}},
				marketplaceConfirmation: null,
				marketplaceMerIntent: '',
				legacyConfirmedKey: '',
				marketplaceResumeUnavailable: false,
				marketplaceComputing: false,
				marketplaceComputeRevision: 0,
				privateFormImagesVisible: true,
				legacyFormUploadPending: false,
				legacyFormSubmitPending: false,
				legacyFormBlocked: false,
				legacyFormSubmitted: false,
				legacyFormNotice: '',
				legacyFormReceipt: null,
				legacyFormGeneration: 0,
				marketplaceFormGroups: [],
				marketplaceFormsReady: false,
				marketplaceSubmitBinding: null,
				marketplaceAttempt: null,
				marketplaceSendRevision: 0,
				marketplaceSubmitState: 'editing',
				marketplaceEditGeneration: 0,
				marketplaceLookupPending: false,
				marketplaceLookupTask: null,
				marketplacePendingEdits: 0,
				marketplaceNavigationPending: false,
				marketplaceUploadPending: false,
				marketplaceQuoteReady: false,
				marketplaceConfirmPending: 0,
				marketplaceConfirmRevision: 0,
				confirm: '', //自定义留言
				date: this.$t(`请选择`),
				time: this.$t(`请选择`),

				canvasWidth: "",
				canvasHeight: "",
				canvasStatus: false,
				newImg: [],


				textareaStatus: true,
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
				virtual_type: 0,
				allPrice: 0,
				formContent: '',
				payType: '', //支付方式
				openType: 1, //优惠券打开方式 1=使用
				active: 0, //支付方式切换
				coupon: {
					coupon: false,
					list: [],
					statusTile: this.$t(`立即使用`)
				}, //优惠券组件
				address: {
					address: false
				}, //地址组件
				addressInfo: {}, //地址信息
				pinkId: 0, //拼团id
				addressId: 0, //地址id
				couponId: 0, //优惠券id
				cartId: '', //购物车id
				orderId: '', // 订单id, 领取礼物页面传参
				BargainId: 0,
				combinationId: 0,
				seckillId: 0,
				discountId: 0,
				userInfo: {}, //用户信息
				mark: '', //备注信息
				couponTitle: this.$t(`请选择`), //优惠券
				coupon_price: 0, //优惠券抵扣金额
				useIntegral: false, //是否使用积分
				integral_price: 0, //积分抵扣金额
				integral: 0,
				usable_integral: 0,
				ChangePrice: 0, //使用积分抵扣变动后的金额
				formIds: [], //收集formid
				status: 0,
				is_address: false,
				toPay: false, //修复进入支付时页面隐藏从新刷新页面
				shippingType: 0,
				system_store: {},
				storePostage: 0,
				advanceId: 0,
				gift_mark: '这是送您的一份礼物~', // 礼物留言
				contacts: '',
				contactsTel: '',
				mydata: {},
				storeList: [],
				store_self_mention: 0,
				cartInfo: [],
				priceGroup: {},
				animated: false,
				totalPrice: 0,
				integralRatio: "0",
				pagesUrl: "",
				orderKey: "",
				// usableCoupon: {},
				offlinePostage: "",
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				from: '',
				news: 1,

				// invTitle: '不开发票',
				invTitle: this.$t(`不开发票`),
				special_invoice: false,
				invoice_func: false,
				header_type: '',
				invShow: false,
				invList: [],
				invChecked: '',
				urlQuery: '',
				pay_close: false,
				noCoupon: 0,
				valid_count: 0,
				discount_id: 0,
				is_shipping: true,
				inputTrip: false,
				focus: true,
				integral_open: false,
				jumpData: {},
				is_gift: 0, // 1 购买的礼品 2领取礼品
				giftData: null
			};
		},
		computed: {
			legacyFormContext() { return !this.marketplaceV1 && this.orderKey ? {kind: 'confirmation', key: this.orderKey} : null; },
			legacyFormFields() { return this.marketplaceV1 ? [] : this.confirm; },
			legacyFormActive() { return this.privateFormImagesVisible && !this.marketplaceConfirmPending && !!this.legacyFormContext; },
			legacyFormLocked() { return this.legacyFormUploadPending || this.legacyFormSubmitPending || this.legacyFormBlocked || this.legacyFormSubmitted; },
			legacyHasPrivateForm() { return !this.marketplaceV1 && Array.isArray(this.confirm) && this.confirm.some(field => field.label === 'img' && Array.isArray(field.value) && field.value.some(isLegacyOrderFormImage)); },
			...mapGetters(['isLogin']),
			orderFormImageContext() { return this.marketplaceV1 && this.orderKey ? {kind: 'confirmation', key: this.orderKey, generation: this.marketplaceEditGeneration, page: this.marketplacePageInstance} : null; },
			orderFormImageGroups() { return this.marketplaceFormGroups; },
			marketplaceFormRenderGroups() {
				return this.marketplaceFormGroups.map(group => ({
					...group,
					renderKey: String(group.product_id) + ':' + group.definition_sha256,
					fields: group.fields.map(field => ({...field, renderKey: marketplaceFormIdentity(group, field)}))
				}));
			},
			orderFormImagesActive() { return this.privateFormImagesVisible && !!this.marketplaceOwner && this.marketplaceFormsReady && !this.marketplaceRestoring && this._marketplaceAlive === true; },
			marketplaceCleanupFailed() { return this.$store.state.app.confirmationCleanupFailed === true; },
			marketplacePreparing() {
				const legacyReady = !this.marketplaceV1 && !!this.legacyConfirmedKey && this.legacyConfirmedKey === this.orderKey && marketplaceIdentityCurrent(this.$store, this.marketplaceOwner);
				return !!this.marketplaceMerIntent && ((!this.marketplaceSubmitBinding && !legacyReady) || this.marketplaceHistoryPending);
			},
			marketplaceV1() {
				return !!this.marketplaceConfirmation && this.marketplaceConfirmation.marketplace_confirmation_version === 1;
			},
			marketplaceCanCreate() {
				return this.marketplaceV1 && this.marketplaceFormsReady && !this.marketplaceResumeUnavailable && this.marketplaceConfirmation.marketplace_create_enabled === true;
			},
			marketplaceCreateAvailable() {
				return this.marketplaceV1 && this.marketplaceConfirmation.marketplace_create_enabled === true;
			},
			marketplaceCanEdit() {
				return this.marketplaceSubmitState === 'editing' && !this.marketplaceLookupPending && !this.marketplaceResumeUnavailable &&
					!this.marketplaceRestoredUnknown && !this.marketplaceRestoring && !this.marketplaceStorageFailed && !this.marketplacePreparing &&
					(!this.marketplaceV1 || marketplaceIdentityCurrent(this.$store, this.marketplaceOwner));
			},
			marketplaceCanSubmit() {
				return this.marketplaceCanCreate && this.marketplaceCanEdit && !this.marketplaceCleanupFailed && this.marketplaceSelectionsResolved() && !!this.marketplaceSubmitBinding && this.marketplaceQuoteReady &&
					!this.marketplaceComputing && !this.marketplaceConfirmPending && !this.marketplacePendingEdits && !this.marketplaceNavigationPending && !this.marketplaceUploadPending;
			}
		},
		watch: {
			'$store.state.app.authRevision'() {
				this.legacyConfirmedKey = '';
				this.invalidateLegacyFormWork(true);
				this.closeOrderFormImages();
				this._marketplaceLifecycle++;
				this.marketplaceQuoteReady = false;
				this.marketplaceOwner = null;
				this.marketplaceDraft = null;
				if (this.orderKey || this.marketplaceV1) {
					this.marketplaceRestoredUnknown = true; this.marketplaceSubmitState = 'uncertain';
					this.marketplaceNotice = this.$t(`登录身份已变化，请重新查询原订单。`);
					this.marketplaceFormGroups = []; this.cartInfo = []; this.userInfo = {}; this.addressInfo = {};
					this.marketplaceAttempt = null; this.invList = []; this.invShow = false; this.address.address = false;
					this.marketplaceSelection = {address: {mode: 'none', id: '0', status: 'none', snapshot: {}}, invoice: {mode: 'none', id: '0', status: 'none', snapshot: {}}};
					this.mark = ''; this.contacts = ''; this.contactsTel = ''; this.gift_mark = ''; this.invTitle = this.$t(`不开发票`);
				}
			}
		},
		onLoad(options) {
			this._marketplaceAlive = true;
			this._marketplaceLifecycle = 0;
			this._marketplaceInitializing = true;
			// #ifdef H5
			this.from = this.$wechat.isWeixin() ? 'weixin' : 'weixinh5'
			// #endif
			// #ifdef MP
			this.from = 'routine'
			// #endif
			// #ifdef APP-PLUS
			this.from = 'app'
			// #endif
			if (options.orderKey && !options.is_gift && !options.order_id) {
				this.orderKey = options.orderKey;
				this.marketplaceRestoredUnknown = true;
				this.marketplaceSubmitState = 'uncertain';
				this.resumeMarketplaceConfirmation();
				return;
			}
			if (!options.cartId && !options.order_id) return this.$util.Tips({
				title: this.$t(`请选择要购买的商品`)
			}, {
				tab: 3,
				url: 1
			});
			if(options.is_gift){
				this.is_gift = Number(options.is_gift);
			}
			this.couponId = options.couponId || 0;
			this.noCoupon = Number(options.noCoupon) || 0;
			this.pinkId = options.pinkId ? parseInt(options.pinkId) : 0;
			this.addressId = options.addressId || 0;
			this.cartId = options.cartId;
			this.marketplaceMerIntent = options.mer_id || '';
			this.orderId = options.order_id || 0
			this.is_address = options.is_address ? true : false;
			this.news = !options.new || options.new === '0' ? 0 : 1;
			this.invChecked = options.invoice_id || '';
			this.header_type = options.header_type || '1';
			this.couponTitle = options.couponTitle || this.$t(`请选择`)
			if (options.invoice_id) {
				let name = ''
				name += options.header_type == 1 ? this.$t(`个人`) : this.$t(`企业`);
				name += options.invoice_type == 1 ? this.$t(`普通`) : this.$t(`专用`);
				name += this.$t(`发票`);
				this.invTitle = name;
			}
			// #ifndef APP-PLUS
			this.textareaStatus = true;
			// #endif
			if (this.isLogin && this.toPay == false && (this.is_gift == 0 || this.is_gift == 1)) {
				if (!this.is_gift && !this.orderId) this.startMarketplaceFresh();
				else this.checkShipping();
			}else if(this.is_gift && this.isLogin){
				this.getOrderDetail()
			} else {
				toLogin();
			}
		},
		/**
		 * 生命周期函数--监听页面显示
		 */
		onShow() {
			this.privateFormImagesVisible = true;
			let _this = this
			this.marketplaceNavigationPending = false;
			if (this._marketplaceDidShow && this.orderKey && (this.marketplaceV1 || this.marketplaceRestoredUnknown) && !this.is_gift && !this.orderId && !this._marketplaceInitializing) {
				this.resumeMarketplaceConfirmation(this._marketplaceChildReturned === true);
				this._marketplaceChildReturned = false;
			}
			this._marketplaceDidShow = true;
			// #ifdef H5
			if (!this._marketplaceStorageListener && typeof window !== 'undefined') {
				this._marketplaceStorageListener = event => {
					if (event.key === null || event.key === 'marketplace-confirmation:v1:' + this.$store.state.app.verifiedUid) {
						this.marketplaceQuoteReady = false;
						if (this.orderKey && !this.marketplaceRestoring) this.resumeMarketplaceConfirmation();
					}
				};
				window.addEventListener('storage', this._marketplaceStorageListener);
			}
			// #endif

			uni.$on("handClick", res => {
				if (res && (!this.marketplaceV1 || this.marketplaceCanEdit)) {
					_this.system_store = res.address
				}
				// 清除监听
				uni.$off('handClick');
			})

			// 如果当前是门店自提模式，重新获取定位以确保门店列表准确
			if (this.shippingType == 1 && !this.system_store.name) {
				this.refreshLocationAndStores();
			}

		},
		onHide() {
			this.invalidateLegacyFormWork(false);
			this.privateFormImagesVisible = false; this.closeOrderFormImages();
			if (this.marketplaceV1 && this.marketplaceCanEdit) this.persistMarketplaceContext();
			this._marketplaceLifecycle++;
			this.marketplaceQuoteReady = false;
			this._marketplaceResumeTask = null; this.marketplaceRestoring = false;
			if (this.orderKey) this._marketplaceInitializing = false;
		},
		onUnload() {
			this.invalidateLegacyFormWork(false);
			this.privateFormImagesVisible = false; this.closeOrderFormImages();
			if (this.marketplaceV1 && this.marketplaceCanEdit) this.persistMarketplaceContext();
			this._marketplaceAlive = false; this._marketplaceLifecycle++;
			// #ifdef H5
			if (this._marketplaceStorageListener && typeof window !== 'undefined') window.removeEventListener('storage', this._marketplaceStorageListener);
			// #endif
		},
		methods: {
			legacyFormGuard() {
				const app = this.$store.state.app;
				return {token: app.token, revision: app.authRevision, uid: app.verifiedUid, key: this.orderKey, fields: this.confirm, generation: this.legacyFormGeneration};
			},
			legacyFormCurrent(guard) {
				const app = this.$store.state.app;
				return !!guard && this.privateFormImagesVisible && !this.marketplaceV1 && !!guard.token && app.token === guard.token && app.authRevision === guard.revision &&
					(!guard.uid || app.verifiedUid === guard.uid) && guard.key === this.orderKey && guard.fields === this.confirm && guard.generation === this.legacyFormGeneration;
			},
			invalidateLegacyFormWork(clear) {
				const unconfirmed = this._legacyFormUploadIssued || this._legacyFormSubmitIssued;
				this.legacyFormGeneration++;
				if (this._legacyFormUploadScope) this._legacyFormUploadScope.dispose();
				this._legacyFormUploadScope = null; this._legacyFormSubmitGuard = null;
				this._legacyFormUploadIssued = false; this._legacyFormSubmitIssued = false;
				this.legacyFormUploadPending = false; this.legacyFormSubmitPending = false;
				if (!this.marketplaceV1) this.canvasStatus = false;
				if (clear) { this.confirm = []; this.legacyFormBlocked = true; this.legacyFormNotice = this.$t(`登录身份已变化，请重新进入订单确认页`); }
				else if (unconfirmed && !this.legacyFormSubmitted) this.legacyFormFailure(null);
			},
			legacyFormFailure(response) {
				const receipt = legacyOrderFormReceipt(response); this.legacyFormReceipt = receipt;
				this.legacyFormBlocked = !receipt || receipt.complete !== false || receipt.database_committed !== false || receipt.physical_changed === null ||
					receipt.cleanup_complete === false || (receipt.physical_changed === true && receipt.cleanup_complete !== true);
				this.legacyFormNotice = this.$t(this.legacyFormBlocked ?
					(receipt && receipt.database_committed === true ? '资料已写入，但结果尚未完整确认。草稿已保留，请先核实原订单，勿重复操作' : '本次操作结果尚未确认。草稿已保留，请先核实原订单，勿重复操作') :
					'本次操作未保存，草稿已保留，可核对后重新操作');
			},
			submitLegacyPrivateForm(data) {
				if (this.legacyFormBlocked || this.legacyFormSubmitted || this.legacyFormUploadPending || this._legacyFormSubmitIssued) return;
				const guard = this._legacyFormSubmitGuard || this.legacyFormGuard();
				if (!this.legacyFormCurrent(guard)) return;
				this._legacyFormSubmitGuard = guard; this.legacyFormSubmitPending = true; this.legacyFormNotice = ''; this.legacyFormReceipt = null;
				const body = JSON.parse(JSON.stringify(data));
				this._legacyFormSubmitIssued = true;
				return request.post('order/create/' + encodeURIComponent(guard.key), body, {noVerify: true}).then(response => {
					if (!this.legacyFormCurrent(guard)) return;
					const orderId = response && response.data && response.data.result && response.data.result.orderId;
					if (!response || response.status !== 200 || typeof orderId !== 'string' || !orderId || orderId.length > 64 || orderId.includes('\0')) return this.legacyFormFailure(response);
					this.legacyFormSubmitted = true;
					uni.reLaunch({url: '/pages/goods/cashier/index?order_id=' + encodeURIComponent(orderId) + '&from_type=order'});
				}).catch(error => { if (this.legacyFormCurrent(guard)) this.legacyFormFailure(error); })
					.finally(() => { if (this.legacyFormCurrent(guard)) { this.legacyFormSubmitPending = false; this._legacyFormSubmitIssued = false; this._legacyFormSubmitGuard = null; } });
			},
			marketplaceGuard(kind = '') {
				if (!this._marketplaceRequestRevisions) this._marketplaceRequestRevisions = {};
				const revision = kind ? this._marketplaceRequestRevisions[kind] = (this._marketplaceRequestRevisions[kind] || 0) + 1 : 0;
				return {identity: this.marketplaceOwner, lifecycle: this._marketplaceLifecycle, key: this.orderKey, generation: this.marketplaceEditGeneration, kind, revision};
			},
			marketplaceGuardCurrent(guard) {
				return this._marketplaceAlive && guard && guard.lifecycle === this._marketplaceLifecycle && marketplaceIdentityCurrent(this.$store, guard.identity) &&
					guard.key === this.orderKey && guard.generation === this.marketplaceEditGeneration &&
					(!guard.kind || this._marketplaceRequestRevisions[guard.kind] === guard.revision);
			},
			marketplaceBootstrapGuard() {
				return {authRevision: this.$store.state.app.authRevision, token: this.$store.state.app.token, identity: null,
					lifecycle: this._marketplaceLifecycle, key: this.orderKey, generation: this.marketplaceEditGeneration,
					revision: this._marketplaceBootstrapRevision = (this._marketplaceBootstrapRevision || 0) + 1};
			},
			marketplaceBootstrapCurrent(guard) {
				return this._marketplaceAlive && guard.lifecycle === this._marketplaceLifecycle && guard.key === this.orderKey && guard.generation === this.marketplaceEditGeneration &&
					guard.revision === this._marketplaceBootstrapRevision && guard.authRevision === this.$store.state.app.authRevision && guard.token === this.$store.state.app.token &&
					(!guard.identity || marketplaceIdentityCurrent(this.$store, guard.identity));
			},
			marketplacePendingEdit(guard, pending) {
				if (!this.marketplaceGuardCurrent(guard)) return;
				if (!this._marketplacePendingReaders) this._marketplacePendingReaders = {};
				if (pending) this._marketplacePendingReaders[guard.kind] = guard;
				else if (this._marketplacePendingReaders[guard.kind] === guard) this._marketplacePendingReaders[guard.kind] = null;
				this.marketplacePendingEdits = Object.values(this._marketplacePendingReaders).filter(Boolean).length;
			},
			marketplaceSelectionsResolved() {
				if (!this.marketplaceV1) return true;
				return ['none', 'resolved'].includes(this.marketplaceSelection.address.status) && ['none', 'resolved'].includes(this.marketplaceSelection.invoice.status) &&
					(this.payType === '' || this.cartArr.some(item => item.value === this.payType && !!item.payStatus));
			},
			marketplaceStorageError(error) {
				this.marketplaceStorageFailed = true; this.marketplaceQuoteReady = false;
				this.marketplaceNotice = error && error.message ? error.message : this.$t(`结算资料未能安全保存，本次尚未发送。`);
			},
			marketplaceContextRecord() {
				const previous = this.marketplaceDraft;
				const selection = JSON.parse(JSON.stringify(this.marketplaceSelection));
				for (const kind of ['address', 'invoice']) {
					if (selection[kind].mode === 'none') selection[kind].snapshot = {};
					else if (selection[kind].status !== 'resolved' && previous && previous.selection[kind].id === selection[kind].id) selection[kind].snapshot = previous.selection[kind].snapshot;
				}
				if (selection.address.status === 'resolved') {
					selection.address.snapshot = {};
					for (const field of ['id', 'real_name', 'phone', 'province', 'city', 'district', 'detail', 'is_default']) {
						if (Object.prototype.hasOwnProperty.call(this.addressInfo, field)) selection.address.snapshot[field] = this.addressInfo[field];
					}
				}
				const invoice = this.invList.find(row => String(row.id) === selection.invoice.id) || selection.invoice.invoice || selection.invoice.snapshot;
				if (selection.invoice.status === 'resolved' && invoice) {
					selection.invoice.snapshot = {};
					for (const field of ['id', 'name', 'header_type', 'type']) {
						if (Object.prototype.hasOwnProperty.call(invoice, field)) selection.invoice.snapshot[field] = invoice[field];
					}
				}
				delete selection.invoice.invoice;
				return {key: this.orderKey, binding: this.marketplaceSubmitBinding, startedAt: previous ? previous.startedAt : this.marketplaceStartedAt,
					draftExpiresAt: (previous ? previous.startedAt : this.marketplaceStartedAt) + 600000,
					editGeneration: this.marketplaceEditGeneration, phase: this.marketplaceSubmitState,
					fields: {mark: this.mark, contacts: this.contacts, contactsTel: this.contactsTel, gift_mark: this.gift_mark, payType: this.payType},
					selection, forms: this.marketplaceFormGroups,
					view: {store: {mer_id: this.marketplaceConfirmation.mer_id, name: this.marketplaceConfirmation.store.name},
						products: this.cartInfo.map(row => ({ref: row.id, name: row.productInfo && row.productInfo.store_name || '', qty: row.cart_num,
							sku: row.productInfo && row.productInfo.attrInfo && row.productInfo.attrInfo.suk || ''}))},
					attempts: previous ? previous.attempts : [], firstSendIntentAt: previous ? previous.firstSendIntentAt : 0,
					attemptExpiresAt: previous ? previous.attemptExpiresAt : 0};
			},
			persistMarketplaceContext() {
				if (!this.marketplaceV1 || !this.marketplaceSubmitBinding || !marketplaceIdentityCurrent(this.$store, this.marketplaceOwner) || this.marketplaceRestoredUnknown) return false;
				try {
					this.marketplaceDraft = saveMarketplaceConfirmation(this.marketplaceOwner.ownerUid, this.marketplaceContextRecord(), this.marketplaceDraft ? this.marketplaceDraft.revision : 0);
					return true;
				} catch (error) { this.marketplaceStorageError(error); return false; }
			},
			startMarketplaceFresh() {
				const scope = this.marketplaceBootstrapGuard();
				if (this.marketplaceMerIntent) this.marketplaceNotice = this.$t(`正在核实登录身份和新确认内容。`);
				return verifyMarketplaceOwner(this.$store).then(identity => {
					if (!this.marketplaceBootstrapCurrent(scope)) return;
					scope.identity = identity;
					this.marketplaceOwner = identity;
					if (this.marketplaceMerIntent) {
						const old = findMarketplaceUnknown(identity.ownerUid, this.marketplaceMerIntent, this.news ? 'temporary' : 'persistent', this.cartId.split(','));
						if (old) {
							this.orderKey = old.key; scope.key = old.key;
							return uni.redirectTo({url: marketplaceConfirmationUrl(old.key), fail: () => {
								if (!this.marketplaceBootstrapCurrent(scope)) return;
								this.marketplaceRestoredUnknown = true; this.marketplaceSubmitState = 'uncertain'; this._marketplaceInitializing = false;
								this.marketplaceNotice = this.$t(`原提交结果仍待确认，请查询原订单。`);
							}});
						}
					}
					this.checkShipping();
				}).catch(error => { if (this.marketplaceBootstrapCurrent(scope)) this.marketplaceStorageError(error); });
			},
			resumeMarketplaceConfirmation(liveReturn = false) {
				if (this._marketplaceResumeTask) return this._marketplaceResumeTask;
				const liveAttempt = liveReturn && !this.marketplaceRestoredUnknown && this.marketplaceSubmitState === 'editing' ? this.marketplaceAttempt : null;
				const liveOwner = this.marketplaceOwner, liveGeneration = this.marketplaceEditGeneration;
				this._marketplaceLifecycle++;
				this.marketplaceLookupPending = false; this.marketplaceLookupTask = null;
				this.marketplacePendingEdits = 0; this.marketplaceComputing = false;
				this._marketplacePendingReaders = {};
				this.marketplaceConfirmPending = 0;
				this.marketplaceUploadPending = false; this.canvasStatus = false;
				this.marketplaceRestoring = true; this.marketplaceQuoteReady = false;
				this.marketplaceNotice = this.$t(`正在核对登录身份和原确认内容。`);
				const key = this.orderKey;
				const scope = this.marketplaceBootstrapGuard();
				const task = verifyMarketplaceOwner(this.$store).then(identity => {
					if (!this.marketplaceBootstrapCurrent(scope)) return;
					scope.identity = identity;
					this.marketplaceOwner = identity;
					const guard = this.marketplaceGuard('resume-lookup');
					return orderConfirmation(key).then(response => {
						if (!this.marketplaceGuardCurrent(guard)) return;
						const result = marketplaceConfirmationResult(response.data, key);
						if (result.state === 'created') return this.completeMarketplaceOrder(result, true, guard.generation);
						let record = null;
						try { record = readMarketplaceConfirmation(identity.ownerUid, key); }
						catch (error) { this.marketplaceStorageError(error); }
						this.marketplaceDraft = record;
						const sameLiveAttempt = liveAttempt && liveOwner && identity.ownerUid === liveOwner.ownerUid && identity.authRevision === liveOwner.authRevision &&
							record && record.kind === 'full' && record.editGeneration === liveGeneration && liveGeneration > liveAttempt.generation &&
							JSON.stringify(this.marketplaceSubmitBinding) === JSON.stringify(record.binding) && record.attempts.some(saved => JSON.stringify(saved) === JSON.stringify(liveAttempt));
						if (result.state !== 'not_found' || !record || record.kind !== 'full' || (record.firstSendIntentAt && !sameLiveAttempt) || record.phase !== 'editing') {
							this.marketplaceRestoredUnknown = true; this.marketplaceSubmitState = 'uncertain';
							this.marketplaceNotice = this.$t(`提交结果待确认。仅可查询原订单，不会重建确认或再次提交。`);
							return;
						}
						this.marketplaceEditGeneration = record.editGeneration;
						scope.generation = record.editGeneration;
						const restoreGuard = this.marketplaceGuard('resume-dto');
						return orderConfirmationRestore(key, {addressId: record.selection.address.id, invoice_id: record.selection.invoice.id, payType: record.fields.payType}).then(response => {
							if (!this.marketplaceGuardCurrent(restoreGuard)) return;
							const data = response.data, outcome = marketplaceConfirmationResult(data, key);
							if (outcome.state === 'created') return this.completeMarketplaceOrder(outcome, true, record.editGeneration);
							if (outcome.state !== 'not_found') { this.marketplaceRestoredUnknown = true; this.marketplaceSubmitState = 'uncertain'; return; }
							const binding = bindMarketplaceSubmission(data.confirmation);
							if (JSON.stringify(binding) !== JSON.stringify(record.binding)) throw new Error(this.$t(`原商品选择已变化，不能重建确认。`));
							this.hydrateMarketplaceConfirmation(data.confirmation, record, data.selection);
							this.marketplaceRestoredUnknown = false; this.marketplaceResumeUnavailable = false;
							this.marketplaceStorageFailed = false; this.marketplaceSubmitState = 'editing';
							this.marketplaceNotice = this.marketplaceSelectionsResolved() ? '' : this.$t(`原地址、发票或付款方式尚未核实，请重新选择。`);
							this.marketplaceRestoring = false;
							if (this.persistMarketplaceContext()) return this.computedPrice();
						});
					});
				}).catch(error => {
					if (!this.marketplaceBootstrapCurrent(scope)) return;
					this.marketplaceResumeUnavailable = true;
					this.marketplaceRestoredUnknown = true; this.marketplaceSubmitState = 'uncertain';
					this.marketplaceNotice = error && error.message ? error.message : this.$t(`原确认内容暂不能恢复，请仅查询原订单。`);
				}).finally(() => {
					if (this.marketplaceBootstrapCurrent(scope)) { this.marketplaceRestoring = false; this._marketplaceInitializing = false; }
					if (this._marketplaceResumeTask === task) this._marketplaceResumeTask = null;
				});
				this._marketplaceResumeTask = task;
				return task;
			},
			hydrateMarketplaceConfirmation(data, record, selection) {
				this.applyMarketplaceMetadata(data);
				if (!this.marketplaceV1 || !data.userInfo || !data.priceGroup) throw new Error(this.$t(`完整确认内容尚未取得。`));
				if (!this.marketplaceOwner || marketplaceOwnerUid(data.userInfo.uid) !== this.marketplaceOwner.ownerUid) throw new Error(this.$t(`本人确认身份未完整取得。`));
				this.marketplaceMoneyFields(data.priceGroup, ['totalPrice', 'sumPrice', 'storePostage', 'storePostageDiscount', 'vipPrice', 'levelPrice', 'memberPrice']);
				this.marketplaceSubmitBinding = bindMarketplaceSubmission(data);
				this.orderKey = data.orderKey; this.cartId = this.marketplaceSubmitBinding.refs.join(',');
				this.userInfo = data.userInfo; this.cartInfo = data.cartInfo; this.priceGroup = data.priceGroup;
				this.marketplaceFormGroups = record ? retainMarketplaceForms(data.custom_form, record.forms) : initializeMarketplaceForms(data.custom_form);
				this.marketplaceFormsReady = true;
				this.valid_count = data.valid_count; this.virtual_type = data.virtual_type || 0;
				this.integral = data.usable_integral; this.usable_integral = data.usable_integral;
				this.integralRatio = data.integralRatio; this.offlinePostage = data.offlinePostage;
				this.invoice_func = data.invoice_func; this.special_invoice = data.special_invoice;
				this.store_self_mention = 0; this.shippingType = 0; this.integral_open = false;
				this.totalPrice = data.priceGroup.totalPrice; this.allPrice = data.priceGroup.sumPrice; this.ChangePrice = this.totalPrice;
				this.cartArr[0].payStatus = data.pay_weixin_open === true ? 1 : 0;
				this.cartArr[1].payStatus = data.ali_pay_status === true ? 1 : 0;
				// #ifdef MP
				this.cartArr[1].payStatus = 0;
				// #endif
				this.cartArr[2].payStatus = 0; this.cartArr[2].number = data.userInfo.now_money;
				this.cartArr[3].payStatus = String(data.offline_pay_status) === '1' && !data.deduction ? 1 : 0;
				this.cartArr[4].payStatus = String(data.friend_pay_status) === '1' ? 1 : 0;
				if (record) {
					['mark', 'contacts', 'contactsTel', 'gift_mark', 'payType'].forEach(field => { this[field] = record.fields[field]; });
					if (!selection || !selection.address || !selection.invoice) throw new Error(this.$t(`收货及发票选择尚未完整取得。`));
					for (const kind of ['address', 'invoice']) {
						const actual = selection[kind], hint = record.selection[kind];
						if (String(actual.id) !== hint.id || actual.mode !== hint.mode || !['none', 'resolved', 'unavailable'].includes(actual.status)) throw new Error(this.$t(`原选择未完整恢复。`));
					}
					this.marketplaceSelection = {address: {...selection.address, id: String(selection.address.id), snapshot: {}},
						invoice: {...selection.invoice, id: String(selection.invoice.id), snapshot: {}}};
					this.addressId = selection.address.id; this.addressInfo = selection.address.status === 'resolved' ? data.addressInfo : {};
					this.invChecked = selection.invoice.mode === 'none' ? '' : String(selection.invoice.id);
					this.invTitle = selection.invoice.status === 'resolved' ? this.marketplaceInvoiceTitle(selection.invoice.invoice) :
						selection.invoice.mode === 'none' ? this.$t(`不开发票`) : this.$t(`原发票待重新选择`);
				} else {
					this.contacts = typeof data.userInfo.real_name === 'string' ? data.userInfo.real_name : '';
					this.contactsTel = typeof data.userInfo.record_phone === 'string' && data.userInfo.record_phone !== '0' ? data.userInfo.record_phone : String(data.userInfo.phone || '');
					this.addressInfo = data.addressInfo && !Array.isArray(data.addressInfo) ? data.addressInfo : {};
					this.addressId = this.addressInfo.id || 0;
					this.marketplaceSelection.address = {mode: this.addressId ? 'id' : 'none', id: String(this.addressId), status: this.addressId ? 'resolved' : 'none', snapshot: {}};
				}
				this.address.addressId = this.addressId;
				this.marketplaceQuoteReady = false;
			},
			marketplaceInvoiceTitle(row) {
				if (!row || !row.id) return this.$t(`原发票待重新选择`);
				return this.$t(Number(row.header_type) === 1 ? `个人` : `企业`) + this.$t(Number(row.type) === 1 ? `普通` : `专用`) + this.$t(`发票`);
			},
			lookupRestoredMarketplaceOrder() {
				if (this.marketplaceLookupPending || this.marketplaceRestoring) return;
				return this.resumeMarketplaceConfirmation();
			},
			leaveMarketplaceConfirmation() { uni.switchTab({url: '/pages/order_addcart/order_addcart'}); },
			setCheckoutText(event, field) {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
				if (['contacts', 'contactsTel', 'gift_mark'].includes(field) && event.detail && typeof event.detail.value === 'string') {
					this[field] = event.detail.value;
					if (this.marketplaceV1) this.persistMarketplaceContext();
				}
			},
			handleMarketplaceSubmit() {
				if (this.marketplaceRestoredUnknown) return this.lookupRestoredMarketplaceOrder();
				if (!this.marketplaceV1) return this.SubOrder();
				return this.marketplaceSubmitState === 'uncertain' ? this.retryMarketplaceOrder() : this.submitMarketplaceOrder();
			},
			queryMarketplaceOrder() {
				if (this.marketplaceLookupPending && this.marketplaceLookupTask) return this.marketplaceLookupTask;
				const key = this.orderKey;
				if (!key || !marketplaceIdentityCurrent(this.$store, this.marketplaceOwner)) return Promise.reject(new Error(this.$t(`请先核实登录身份`)));
				const guard = this.marketplaceGuard('lookup');
				this.marketplaceLookupPending = true;
				const task = orderConfirmation(key).then(res => {
					if (!this.marketplaceGuardCurrent(guard)) throw new Error(this.$t(`原页面或登录身份已变化`));
					return marketplaceConfirmationResult(res.data, key);
				}).finally(() => { if (this.marketplaceGuardCurrent(guard)) { this.marketplaceLookupPending = false; this.marketplaceLookupTask = null; } });
				this.marketplaceLookupTask = task;
				return task;
			},
			completeMarketplaceOrder(result, fromLookup, generation) {
				if (!result || result.key !== this.orderKey || result.state !== 'created' || !marketplaceIdentityCurrent(this.$store, this.marketplaceOwner) || this.marketplaceSubmitState === 'done') return;
				this.marketplaceSubmitState = 'done';
				this.marketplaceQuoteReady = false;
				try {
					const actual = readMarketplaceConfirmation(this.marketplaceOwner.ownerUid, result.key);
					removeCreatedMarketplaceConfirmation(this.marketplaceOwner.ownerUid, result.key, actual ? actual.revision : 0);
					this.marketplaceDraft = null; this.marketplaceAttempt = null;
				} catch (error) {
					this.marketplaceStorageFailed = true;
					this.marketplaceNotice = this.$t(`订单已生成，但本机结算资料尚未清理完成。请勿再次提交。`);
				}
				const earlier = generation !== this.marketplaceEditGeneration;
				const guard = this.marketplaceGuard('complete');
				const navigate = () => { if (this.marketplaceGuardCurrent(guard)) uni.reLaunch({url: '/pages/goods/order_details/index?order_id=' + encodeURIComponent(result.orderId)}); };
				if (earlier || (fromLookup && this.marketplaceEditGeneration > 0)) uni.showModal({title: this.$t(`订单已生成`),
					content: earlier ? this.$t(`原提交已生成订单，调整内容未另行下单。请查看订单详情中的实际信息。`) :
						this.$t(`订单已生成，请以订单详情中的实际收货信息和表单为准。调整不会另生成第二笔订单。`),
					showCancel: false, success: navigate});
				else navigate();
			},
			sendMarketplaceAttempt(attempt) {
				if (this.marketplaceRestoredUnknown || this.marketplaceCleanupFailed || this.marketplaceStorageFailed || !marketplaceIdentityCurrent(this.$store, this.marketplaceOwner) || !this.marketplaceCreateAvailable || this.marketplaceSubmitState === 'done' ||
					!this.marketplaceSubmitBinding || attempt.key !== this.marketplaceSubmitBinding.key) return Promise.resolve();
				// The exact immutable body is installed and read back before any create HTTP call.
				if (!this.persistMarketplaceContext()) return Promise.resolve();
				try {
					const sealed = sealMarketplaceConfirmationAttempt(this.marketplaceOwner.ownerUid, this.orderKey, this.marketplaceDraft.revision, attempt);
					this.marketplaceDraft = sealed.record; attempt = sealed.attempt; this.marketplaceAttempt = attempt;
				} catch (error) { this.marketplaceStorageError(error); return Promise.resolve(); }
				this.marketplaceSubmitState = 'submitting';
				const guard = this.marketplaceGuard('send');
				const sendRevision = ++this.marketplaceSendRevision;
				this.invShow = false; this.address.address = false; this.inputTrip = false;
				return orderCreate(attempt.key, attempt.body).then(res => {
					if (!this.marketplaceGuardCurrent(guard)) return;
					this.completeMarketplaceOrder(marketplaceCreateResult(res, attempt.key), false, attempt.generation);
				}).catch(() => {
					if (!this.marketplaceGuardCurrent(guard)) return;
					if (this.marketplaceSubmitState === 'done') return;
					const current = sendRevision === this.marketplaceSendRevision;
					if (current) { this.marketplaceSubmitState = 'uncertain'; this.persistMarketplaceContext(); }
					return this.queryMarketplaceOrder().then(result => {
						if (this.marketplaceGuardCurrent(guard) && result.state === 'created') this.completeMarketplaceOrder(result, true, attempt.generation);
					}).catch(() => {
						if (this.marketplaceGuardCurrent(guard) && sendRevision === this.marketplaceSendRevision && this.marketplaceSubmitState !== 'done') this.marketplaceSubmitState = 'uncertain';
					});
				});
			},
			submitMarketplaceOrder() {
				if (!this.marketplaceCanSubmit) return Promise.resolve();
				const guard = this.marketplaceGuard('submit');
				if (!this.addressId) return this.$util.Tips({title: this.$t(`请选择收货地址`)});
				const missing = firstMissingMarketplaceAnswer(this.marketplaceFormGroups);
				if (missing) return this.$util.Tips({title: missing.product_name + '：' + this.$t(`请填写正确的`) + missing.title});
				const capture = () => {
					if (!this.marketplaceCanSubmit) return;
					this.marketplaceAttempt = snapshotMarketplaceSubmission(this.marketplaceSubmitBinding, {
						addressId: this.addressId, invoice_id: this.invChecked, payType: this.payType,
						mark: this.mark, gift_mark: this.gift_mark, real_name: typeof this.contacts === 'string' ? this.contacts : '',
						phone: typeof this.contactsTel === 'string' ? this.contactsTel : ''
					}, marketplaceFormAnswers(this.marketplaceFormGroups), this.marketplaceEditGeneration);
					return this.sendMarketplaceAttempt(this.marketplaceAttempt);
				};
				if (!this.marketplaceAttempt || this.marketplaceAttempt.generation === this.marketplaceEditGeneration) return capture();
				return this.queryMarketplaceOrder().then(result => {
					if (!this.marketplaceGuardCurrent(guard) || this.marketplaceSubmitState === 'done') return;
					if (result.state === 'created') return this.completeMarketplaceOrder(result, true, this.marketplaceAttempt.generation);
					if (result.state === 'not_found') return capture();
					this.marketplaceSubmitState = 'uncertain';
				}).catch(() => {if (this.marketplaceGuardCurrent(guard) && this.marketplaceSubmitState !== 'done') this.marketplaceSubmitState = 'uncertain';});
			},
			retryMarketplaceOrder() {
				if (this.marketplaceRestoredUnknown || this.marketplaceSubmitState !== 'uncertain' || this.marketplaceLookupPending || !this.marketplaceAttempt) return;
				return this.sendMarketplaceAttempt(this.marketplaceAttempt);
			},
			adjustMarketplaceOrder() {
				if (this.marketplaceRestoredUnknown || this.marketplaceSubmitState !== 'uncertain' || this.marketplaceLookupPending) return;
				const guard = this.marketplaceGuard('adjust');
				return this.queryMarketplaceOrder().then(result => {
					if (!this.marketplaceGuardCurrent(guard) || this.marketplaceSubmitState === 'done') return;
					if (result.state === 'created') return this.completeMarketplaceOrder(result, true, this.marketplaceAttempt.generation);
					if (result.state !== 'not_found') return;
					uni.showModal({title: this.$t(`调整订单信息`), content: this.$t(`当前未查到订单，原提交仍可能随后完成。若订单已生成，将显示原订单，调整不会另生成一单。`),
						confirmText: this.$t(`继续调整`), cancelText: this.$t(`保持原信息`), success: res => {
							if (!this.marketplaceGuardCurrent(guard) || !res.confirm || this.marketplaceSubmitState !== 'uncertain') return;
							this.marketplaceEditGeneration++;
							this.marketplaceSubmitState = 'editing';
							this.marketplaceQuoteReady = false;
							if (!this.persistMarketplaceContext()) return;
							this.computedPrice();
						}});
				}).catch(() => {if (this.marketplaceGuardCurrent(guard) && this.marketplaceSubmitState !== 'done') this.marketplaceSubmitState = 'uncertain';});
			},
			marketplaceFormIdentity,
			applyMarketplaceForms(definitions, retainAnswers = false) {
				this.marketplaceFormsReady = false;
				this.marketplaceFormGroups = retainAnswers ? retainMarketplaceForms(definitions, this.marketplaceFormGroups) :
					initializeMarketplaceForms(definitions);
				this.marketplaceFormsReady = true;
			},
			setMarketplaceFormValue(event, productId, definitionHash, fieldIndex) {
				if (!this.marketplaceCanEdit) return;
				const field = findMarketplaceFormField(this.marketplaceFormGroups, productId, definitionHash, fieldIndex);
				if (field && field.label !== 'img' && event.detail && typeof event.detail.value === 'string') {
					field.value = event.detail.value;
					this.persistMarketplaceContext();
				}
			},
			deleteMarketplaceFormImage(productId, definitionHash, fieldIndex, imageIndex) {
				if (!this.marketplaceCanEdit) return;
				const field = findMarketplaceFormField(this.marketplaceFormGroups, productId, definitionHash, fieldIndex);
				if (field && field.label === 'img') { field.value.splice(imageIndex, 1); this.persistMarketplaceContext(); }
			},
			uploadMarketplaceFormImage(productId, definitionHash, fieldIndex) {
				if (!this.marketplaceCanEdit || this.marketplaceUploadPending) return;
				const generation = this.marketplaceEditGeneration, key = this.orderKey;
				const guard = this.marketplaceGuard('upload');
				const current = () => {
					const field = findMarketplaceFormField(this.marketplaceFormGroups, productId, definitionHash, fieldIndex);
					return this.privateFormImagesVisible && this.marketplaceGuardCurrent(guard) && this.marketplaceCanEdit && generation === this.marketplaceEditGeneration && key === this.orderKey && !!field && field.label === 'img';
				};
				let uploadScope, options;
				try {
					uploadScope = createOrderFormPrivateScope(current);
					options = uploadScope.confirmationUploadOptions(key, productId, fieldIndex);
				} catch (error) { return this.$util.Tips({title: error.message}); }
				this._orderFormUploadScope = uploadScope;
				this.marketplaceUploadPending = true; this.canvasStatus = true;
				const finish = () => {
					if (this._orderFormUploadScope === uploadScope) { this._orderFormUploadScope = null; this.canvasStatus = false; this.marketplaceUploadPending = false; }
					uploadScope.dispose();
				};
				try { this.$util.uploadImageChange(options, res => {
					const field = findMarketplaceFormField(this.marketplaceFormGroups, productId, definitionHash, fieldIndex);
					if (!uploadScope.current() || !field || field.value.length >= 8) return;
					try { const reference = orderFormUploadReference(res && res.data); field.value.push(reference); this.persistMarketplaceContext(); }
					catch (error) { this.$util.Tips({title: error.message}); }
				}, finish, res => {
					if (!uploadScope.current()) return;
					this.canvasWidth = res.w; this.canvasHeight = res.h;
				}, finish, () => uploadScope.current()); } catch (error) { finish(); if (current()) this.$util.Tips({title: error.message}); }
			},
			applyMarketplaceMetadata(data) {
				if (!data || data.marketplace_confirmation_version !== 1 || this.is_gift || this.orderId) {
					if (this.marketplaceV1) throw new Error(this.$t(`本店确认响应不完整，请重新确认商品`));
					return;
				}
				if (!data.store || String(data.store.mer_id) !== String(data.mer_id) || typeof data.store.name !== 'string' || !data.store.name ||
					!['persistent', 'temporary'].includes(data.source_kind) || typeof data.marketplace_create_enabled !== 'boolean' ||
					(this.marketplaceV1 && (String(data.mer_id) !== String(this.marketplaceConfirmation.mer_id) ||
						data.source_kind !== this.marketplaceConfirmation.source_kind))) {
					throw new Error(this.$t(`店铺确认信息暂未完整取得`));
				}
				this.marketplaceConfirmation = {
					marketplace_confirmation_version: 1,
					marketplace_create_enabled: data.marketplace_create_enabled === true,
					mer_id: data.mer_id,
					source_kind: data.source_kind,
					store: data.store
				};
				this.news = data.source_kind === 'temporary' ? 1 : 0;
				this.couponId = 0;
				this.useIntegral = false;
				this.integral_open = false;
				this.cartArr[2].payStatus = 0;
				if (this.payType === 'yue') this.payType = '';
			},
			marketplaceMoneyFields(data, fields) {
				if (!data || fields.some(field => typeof data[field] !== 'string' || !/^(0|[1-9][0-9]*)\.[0-9]{2}$/.test(data[field]))) {
					throw new Error(this.$t(`本店金额响应不完整，请重新计算`));
				}
			},
			marketplaceCheckoutContext() {
				return {
					ownerUid: this.marketplaceOwner.ownerUid, authRevision: this.marketplaceOwner.authRevision,
					parentInstance: this.marketplacePageInstance, generation: this.marketplaceEditGeneration,
					version: 1, mer_id: this.marketplaceConfirmation.mer_id, orderKey: this.orderKey,
					cartId: this.cartId, source_kind: this.marketplaceConfirmation.source_kind,
					new: this.marketplaceConfirmation.source_kind === 'temporary' ? 1 : 0
				};
			},
			openMarketplacePage(url, event, selected) {
				if (!this.marketplaceV1 || !this.orderKey || !this.marketplaceCanEdit) return;
				if (!this.persistMarketplaceContext()) return;
				const context = this.marketplaceCheckoutContext();
				const generation = this.marketplaceEditGeneration;
				// The URL carries only a real parent identity hint. Only the eventChannel supplies the V1 DTO.
				url += (url.includes('?') ? '&' : '?') + 'orderKey=' + encodeURIComponent(context.orderKey) +
					'&mer_id=' + encodeURIComponent(context.mer_id);
				const events = {};
				events[event] = (value) => {
					if (generation === this.marketplaceEditGeneration && this.orderKey === context.orderKey && value && value.orderKey === context.orderKey &&
						value.ownerUid === context.ownerUid && value.authRevision === context.authRevision && value.parentInstance === this.marketplacePageInstance &&
						marketplaceIdentityCurrent(this.$store, this.marketplaceOwner) && String(value.mer_id) === String(context.mer_id)) this._marketplaceChildReturned = true;
				};
				this.marketplaceNavigationPending = true;
				uni.navigateTo({
					url, events,
					fail: () => { this.marketplaceNavigationPending = false; },
					success: (res) => {
						if (!res.eventChannel || typeof res.eventChannel.emit !== 'function') {
							// The child can recover the same owner/key without a live channel.
							return;
						}
						res.eventChannel.emit('marketplaceCheckoutContext', context);
					}
				});
			},
			openMarketplaceAddressList() {
				this.openMarketplacePage('/pages/users/user_address_list/index', 'marketplaceAddressSelected',
					(value) => this.OnChangeAddress(value.addressId));
			},
			openMarketplaceInvoice(id) {
				this.invShow = false;
				const url = '/pages/users/user_invoice_form/index?from=order_confirm&specialInvoice=' +
					this.special_invoice + (id ? '&id=' + encodeURIComponent(id) : '');
				this.openMarketplacePage(url, 'marketplaceInvoiceSelected', (value) => {
					this.invChecked = String(value.invoiceId);
					this.header_type = String(value.headerType);
					this.getInvoiceList();
				});
			},
			/**
			 * 刷新定位并更新门店列表
			 */
			refreshLocationAndStores() {
				let that = this;
				// #ifdef H5
				if (that.$wechat.isWeixin()) {
					that.$wechat.location().then(res => {
						uni.setStorageSync('user_latitude', res.latitude);
						uni.setStorageSync('user_longitude', res.longitude);
						this.getList()
					}).catch(err => {
						// 如果获取定位失败，仍然尝试用缓存的定位获取门店列表
						this.getList()
					})
				} else {
					// #endif
					uni.getLocation({
						type: 'wgs84',
						success: (res) => {
							uni.setStorageSync('user_latitude', res.latitude);
							uni.setStorageSync('user_longitude', res.longitude);
						},
						fail: (err) => {
							// 获取定位失败时的处理，仍然使用缓存定位
							console.log('获取定位失败:', err);
						},
						complete: () => {
							this.getList()
						}
					})
					// #ifdef H5
				}
				// #endif
			},
			checkShipping() {
				if (this.marketplaceRestoredUnknown || this.marketplaceRestoring) return;
				let that = this;
				const guard = this.marketplaceOwner ? this.marketplaceGuard('shipping') : null;
				checkShipping(that.cartId, that.news).then(res => {
					if (guard && !this.marketplaceGuardCurrent(guard)) return;
					if (res.data.type == 0) {
						that.is_shipping = true;
						that.shippingType = 0;
						if (!this.marketplaceMerIntent) this.getaddressInfo();
						this.getConfirm();
						this.$nextTick(function() {
							this.$refs.addressWindow.getAddressList();
						})
					} else {
						if (res.data.type == 1) {
							that.is_shipping = false;
							that.shippingType = 0;
							if (!this.marketplaceMerIntent) this.getaddressInfo();
							this.getConfirm();
							this.$nextTick(function() {
								this.$refs.addressWindow.getAddressList();
							})
						} else if (res.data.type == 2) {
							that.is_shipping = false;
							that.shippingType = 1;
							this.addressType(1)
							this.getConfirm();
							this.getList();
						}
					}
				}).catch(err => {
					if (guard && !this.marketplaceGuardCurrent(guard)) return;
					uni.showToast({
						title: err,
						icon: 'none'
					});
				});
			},

			// 不开发票
			invCancel() {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
				this.invChecked = '';
				this.invTitle = this.$t(`不开发票`)
				this.invShow = false;
				if (this.marketplaceV1) {
					this.marketplaceSelection.invoice = {mode: 'none', id: '0', status: 'none', snapshot: {}};
					if (this.persistMarketplaceContext()) this.computedPrice();
				}
			},
			// 选择发票
			invChange(id) {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
				let name = '';
				this.invChecked = id;
				this.invShow = false;
				const result = this.invList.find(item => String(item.id) === String(id));
				if (!result) return;
				name += result.header_type === 1 ? this.$t(`个人`) : this.$t(`企业`);
				name += result.type === 1 ? this.$t(`普通`) : this.$t(`专用`);
				name += this.$t(`发票`);
				this.invTitle = name;
				if (this.marketplaceV1) {
					this.marketplaceSelection.invoice = {mode: 'id', id: String(id), status: Number(result.type) === 2 ? (this.special_invoice ? 'resolved' : 'unavailable') : (this.invoice_func ? 'resolved' : 'unavailable'), snapshot: {}};
					this.persistMarketplaceContext(); this.computedPrice();
				}
			},
			openList() {
				if (this.shippingType == 0) {
					this.onAddress()
				} else {
					this.showStoreList()
				}
			},
			// 关闭发票
			invClose() {
				this.invShow = false;
				this.getInvoiceList()
			},
			getInvoiceList() {
				if (this.marketplaceRestoredUnknown || this.marketplaceRestoring) return Promise.resolve();
				if (this.marketplaceV1 && !this.marketplaceCanEdit) return Promise.resolve();
				const v1 = this.marketplaceV1, generation = this.marketplaceEditGeneration, key = this.orderKey;
				const guard = v1 ? this.marketplaceGuard('invoice') : null;
				const invoiceRevision = this._marketplaceInvoiceRevision = (this._marketplaceInvoiceRevision || 0) + 1;
				if (v1 && this.invChecked) { this.marketplaceSelection.invoice.status = 'pending'; this.marketplaceQuoteReady = false; }
				if (v1) this.marketplacePendingEdit(guard, true);
				uni.showLoading({
					title: this.$t(`正在加载中`)
				})
				return invoiceList().then(res => {
					if (v1 && (!this.marketplaceGuardCurrent(guard) || invoiceRevision !== this._marketplaceInvoiceRevision)) return;
					if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
					if (v1 && (!this.marketplaceCanEdit || generation !== this.marketplaceEditGeneration || key !== this.orderKey)) return;
					uni.hideLoading();
					this.invList = res.data.map(item => {
						item.id = item.id.toString();
						return item;
					});
					const result = this.invList.find(item => item.id == this.invChecked);
					if (result) {
						let name = '';
						name += result.header_type === 1 ? this.$t(`个人`) : this.$t(`企业`);
						name += result.type === 1 ? this.$t(`普通`) : this.$t(`专用`);
						name += this.$t(`发票`);
						this.invTitle = name;
					} else if (v1) {
						this.invTitle = this.invChecked ? this.$t(`原发票待重新选择`) : this.$t(`不开发票`);
					}
					if (v1) {
						this.marketplaceSelection.invoice = {mode: this.invChecked ? 'id' : 'none', id: String(this.invChecked || '0'),
							status: !this.invChecked ? 'none' : result && (Number(result.type) === 2 ? this.special_invoice : this.invoice_func) ? 'resolved' : 'unavailable', snapshot: {}};
						this.persistMarketplaceContext();
						this.computedPrice();
					}
				}).catch(err => {
					if (v1 && (!this.marketplaceGuardCurrent(guard) || invoiceRevision !== this._marketplaceInvoiceRevision)) return;
					if (v1 && this.invChecked) {
						this.marketplaceSelection.invoice.status = 'unavailable'; this.invTitle = this.$t(`原发票待重新选择`); this.persistMarketplaceContext();
					}
					uni.showToast({
						title: err,
						icon: 'none'
					});
				}).finally(() => {if (v1) this.marketplacePendingEdit(guard, false);});
			},
			/**
			 * 开发票
			 */
			goInvoice: function() {
				if (this.marketplaceRestoredUnknown || this.marketplaceRestoring) return;
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
				this.getInvoiceList()
				this.invShow = true;
				this.urlQuery =
					`new=${this.news}&cartId=${this.cartId}&pinkId=${this.pinkId}&couponId=${this.couponId}&addressId=${this.addressId}&specialInvoice=${this.special_invoice}&couponTitle=${this.couponTitle}`;
			},
			/**
			 * 授权回调事件
			 * 
			 */
			onLoadFun: function() {
				this.getaddressInfo();
				this.getConfirm();
				//调用子页面方法授权后执行获取地址列表
				// this.$scope.selectComponent('#address-window').getAddressList();
			},
			/**
			 * 事件回调
			 *
			 */
			onChangeFun: function(e) {
				let opt = e;
				let action = opt.action || null;
				let value = opt.value != undefined ? opt.value : null;
				action && this[action] && this[action](value);
			},
			payClose: function() {
				this.pay_close = false;
			},
			goPay() {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
				this.pay_close = true;
			},
			payCheck(type) {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
				this.payType = type;
				if (this.marketplaceV1) { this.marketplaceQuoteReady = false; if (this.persistMarketplaceContext()) this.computedPrice(); return; }
				this.SubOrder();
			},
			/**
			 * 获取门店列表数据
			 */
			getList: function() {
				let longitude = uni.getStorageSync("user_longitude") || ''; //经度
				let latitude = uni.getStorageSync("user_latitude") || ''; //纬度
				let data = {
					latitude: latitude, //纬度
					longitude: longitude, //经度
					page: 1,
					limit: 10
				}
				storeListApi(data).then(res => {
					let list = res.data.list.list || [];
					this.$set(this, 'storeList', list);
					this.$set(this, 'system_store', list[0]);
				}).catch(err => {})
			},
			// 关闭地址弹窗；
			changeClose: function() {
				this.$set(this.address, 'address', false);
			},
			/*
			 * 跳转门店列表
			 */
			showStoreList: function() {
				let _this = this
				if (this.storeList.length > 0) {
					uni.navigateTo({
						url: '/pages/goods/goods_details_store/index'
					})
				}
			},
			changePayType(type) {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
				this.payType = type
				if (this.marketplaceV1 && !this.persistMarketplaceContext()) return;
				this.computedPrice()
			},
			computedPrice() {
				if (this.marketplaceRestoredUnknown || this.marketplaceRestoring) return Promise.resolve();
				if (this.marketplaceResumeUnavailable) return;
				if (this.marketplaceV1) {
					if (!this.marketplaceCanEdit) return Promise.resolve();
					const binding = this.marketplaceSubmitBinding;
					const key = this.orderKey;
					const generation = this.marketplaceEditGeneration;
					const revision = ++this.marketplaceComputeRevision;
					const guard = this.marketplaceGuard('quote');
					if (!this.marketplaceSelectionsResolved()) { this.marketplaceQuoteReady = false; return Promise.resolve(); }
					this.marketplaceComputing = true;
					this.marketplaceQuoteReady = false;
					return postOrderComputed(key, {
						addressId: this.addressId, shipping_type: 1, payType: this.payType,
						mer_id: this.marketplaceConfirmation.mer_id, couponId: 0, useIntegral: 0
					}).then(res => {
						if (!this.marketplaceGuardCurrent(guard)) return;
						const result = res.data.result;
						if (result && result.state === 'created') {
							if (!binding) return;
							const created = marketplaceConfirmationResult(result, binding.key);
							if (binding.key === key && this.marketplaceSubmitBinding === binding && this.orderKey === key) {
								this.completeMarketplaceOrder(created, true, generation);
							}
							return;
						}
						if (!this.marketplaceCanEdit || generation !== this.marketplaceEditGeneration || key !== this.orderKey || revision !== this.marketplaceComputeRevision) return;
						this.applyMarketplaceMetadata(result);
						this.marketplaceMoneyFields(result, ['pay_price', 'pay_postage', 'storePostageDiscount', 'deduction_price', 'coupon_price']);
						if (Object.prototype.hasOwnProperty.call(result, 'custom_form')) this.applyMarketplaceForms(result.custom_form, true);
						this.totalPrice = result.pay_price;
						this.$set(this.priceGroup, 'storePostage', result.pay_postage);
						this.$set(this.priceGroup, 'storePostageDiscount', result.storePostageDiscount);
						this.integral_price = result.deduction_price;
						this.coupon_price = result.coupon_price;
						this.marketplaceQuoteReady = this.marketplaceSelectionsResolved();
						this.persistMarketplaceContext();
					}).catch(error => {
						if (this.marketplaceGuardCurrent(guard) && revision === this.marketplaceComputeRevision) this.$util.Tips({title: error.message || error});
					}).finally(() => {
						if (this.marketplaceGuardCurrent(guard) && revision === this.marketplaceComputeRevision) this.marketplaceComputing = false;
					});
				}
				let shippingType = this.shippingType;
				let data = {
					addressId: this.addressId,
					useIntegral: this.useIntegral ? 1 : 0,
					couponId: this.couponId,
					shipping_type: parseInt(shippingType) + 1,
					payType: this.payType
				}
				if (this.is_gift) data.is_gift = this.is_gift
				postOrderComputed(this.orderKey, data).then(res => {
					let result = res.data.result;
					if (result) {
						this.totalPrice = result.pay_price;
						this.integral_price = result.deduction_price;
						this.coupon_price = result.coupon_price;
						this.integral = this.useIntegral ? result.SurplusIntegral : this.usable_integral;
						this.$set(this.priceGroup, 'storePostage', shippingType == 1 ? 0 : result.pay_postage);
						this.$set(this.priceGroup, 'storePostageDiscount', result.storePostageDiscount);
					}
				})
			},
			addressType(e) {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
				if (this.marketplaceV1) {
					this.shippingType = 0;
					return this.computedPrice();
				}
				let index = e;
				let that = this;
				if (this.shippingType == parseInt(index)) return
				this.shippingType = parseInt(index);
				if (index == 1) {
					// #ifdef H5
					if (that.$wechat.isWeixin()) {
						that.$wechat.location().then(res => {
							uni.setStorageSync('user_latitude', res.latitude);
							uni.setStorageSync('user_longitude', res.longitude);
							this.getList()
						}).catch(err => {
							this.getList()
						})
					} else {
						// #endif	
						uni.getLocation({
							type: 'wgs84',
							success: (res) => {
								uni.setStorageSync('user_latitude', res.latitude);
								uni.setStorageSync('user_longitude', res.longitude);
							},
							complete: () => {
								this.getList()
							}
						})
						// #ifdef H5	
					}
					// #endif
				};
				this.$nextTick(e => {
					if(!this.is_gift){
						this.getConfirm();
						this.computedPrice();
					}
				})
			},
			bindPickerChange: function(e) {
				if (this.marketplaceV1) return;
				let value = e.detail.value;
				this.shippingType = value;
				this.computedPrice();
			},
			ChangCouponsClone: function() {
				this.$set(this.coupon, 'coupon', false);
			},
			changeTextareaStatus: function() {
				for (let i = 0, len = this.coupon.list.length; i < len; i++) {
					this.coupon.list[i].use_title = '';
					this.coupon.list[i].is_use = 0;
				}
				this.textareaStatus = true;
				this.status = 0;
				this.$set(this.coupon, 'list', this.coupon.list);
			},
			/**
			 * 处理点击优惠券后的事件
			 * 
			 */
			ChangCoupons: function(e) {
				if (this.marketplaceV1) return;
				// this.usableCoupon = e
				// this.coupon.coupon = false
				let index = e,
					list = this.coupon.list,
					couponTitle = this.$t(`请选择`),
					couponId = 0;
				for (let i = 0, len = list.length; i < len; i++) {
					if (i != index) {
						list[i].use_title = '';
						list[i].is_use = 0;
					}
				}
				if (list[index].is_use) {
					//不使用优惠券
					list[index].use_title = '';
					list[index].is_use = 0;
				} else {
					//使用优惠券
					list[index].use_title = this.$t(`不使用`);
					list[index].is_use = 1;
					couponTitle = list[index].coupon_title;
					couponId = list[index].id;
				}
				this.couponTitle = couponTitle;
				this.couponId = couponId;
				this.$set(this.coupon, 'coupon', false);
				this.$set(this.coupon, 'list', list);
				this.computedPrice();
			},
			/**
			 * 使用积分抵扣
			 */
			ChangeIntegral: function() {
				if (this.marketplaceV1) return;
				this.useIntegral = !this.useIntegral;
				this.computedPrice();
			},
			/**
			 * 选择地址后改变事件
			 * @param object e
			 */
			OnChangeAddress: function(e) {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring) return;
				if (this.marketplaceV1) {
					if (!this.marketplaceCanEdit) return;
					const guard = this.marketplaceGuard('address-edit');
					this.addressId = e; this.address.address = false; this.textareaStatus = true; this.marketplaceQuoteReady = false;
					this.marketplaceSelection.address = {mode: String(e) === '0' ? 'none' : 'id', id: String(e), status: String(e) === '0' ? 'none' : 'pending', snapshot: {}};
					if (!this.persistMarketplaceContext()) return;
					return this.getaddressInfo().then(() => { if (this.marketplaceGuardCurrent(guard)) return this.computedPrice(); }).catch(error => {
						if (this.marketplaceGuardCurrent(guard)) this.$util.Tips({title: error.message || error});
					});
				}
				this.textareaStatus = true;
				this.addressId = e;
				this.address.address = false;
				this.getaddressInfo();
				if(!this.is_gift){
					if (!this.marketplaceV1) this.getConfirm()
					this.computedPrice();
				}
			},
			bindHideKeyboard: function(e) {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
				this.mark = e.detail.value;
				if (this.marketplaceV1) this.persistMarketplaceContext();
			},
			getOrderDetail() {
				getGiftOrderDetail(this.orderId).then(res => {
					this.giftData = res.data
					this.$set(this, 'cartInfo', res.data.cartInfo);
					this.store_self_mention = res.data.store_self_mention
					if (res.data.type == 0) {
						this.is_shipping = true;
						this.shippingType = 0;
						this.getaddressInfo();
						this.$nextTick(()=> {
							this.$refs.addressWindow.getAddressList();
						})
					} else {
						if (res.data.type == 1) {
							this.is_shipping = false;
							this.shippingType = 0;
							this.getaddressInfo();
							this.$nextTick(()=> {
								this.$refs.addressWindow.getAddressList();
							})
						} else if (res.data.type == 2) {
							this.is_shipping = false;
							this.shippingType = 1;
							this.addressType(1)
							this.getList();
						}
					}
				})
			},
			/**
			 * 获取当前订单详细信息
			 * 
			 */
			getConfirm: function() {
				if (this.marketplaceRestoredUnknown || this.marketplaceRestoring) return;
				if (this.marketplaceResumeUnavailable) return;
				if (this.marketplaceV1) return this.computedPrice();
				if (!this.is_gift && !this.orderId && this.marketplaceMerIntent) {
					if (this._marketplaceFreshConfirmStarted) return;
					this._marketplaceFreshConfirmStarted = true;
				}
				let that = this;
				const confirmationRevision = ++this.marketplaceConfirmRevision;
				this.legacyConfirmedKey = '';
				this.marketplaceConfirmPending = 1;
				// return;
				uni.showLoading({
					title: that.$t(`正在加载中`),
					mask: true
				});
				let data = {
					cartId: that.cartId,
					new: that.news,
					addressId: that.addressId,
					shipping_type: that.shippingType + 1
				}
				if (that.is_gift) data.is_gift = that.is_gift
				if (!that.is_gift && !that.orderId && that.marketplaceMerIntent) data.mer_id = that.marketplaceMerIntent;
				const guard = this.marketplaceOwner ? this.marketplaceGuard('confirm') : null;
				this.marketplaceStartedAt = Date.now();
				return orderConfirm(data).then(res => {
					if (guard && !this.marketplaceGuardCurrent(guard)) return;
					if (confirmationRevision !== this.marketplaceConfirmRevision) return;
					const versioned = Object.prototype.hasOwnProperty.call(res.data, 'marketplace_confirmation_version');
					if (versioned && res.data.marketplace_confirmation_version !== 1) throw new Error(this.$t(`订单确认版本暂不可用，请重新确认商品`));
					if (res.data.marketplace_confirmation_version === 1) {
						this.hydrateMarketplaceConfirmation(res.data, null, null);
						if (guard) guard.key = this.orderKey;
						this.marketplaceSelection.invoice = {mode: this.invChecked ? 'id' : 'none', id: String(this.invChecked || '0'), status: this.invChecked ? 'pending' : 'none', snapshot: {}};
						if (!this.persistMarketplaceContext()) return;
						uni.hideLoading();
						this.marketplaceHistoryPending = true;
						return uni.redirectTo({url: marketplaceConfirmationUrl(this.orderKey), fail: () => {
							if (guard && !this.marketplaceGuardCurrent(guard)) return;
							this.marketplaceHistoryPending = false;
							this._marketplaceInitializing = false; this.marketplaceResumeUnavailable = true;
							this.marketplaceNotice = this.$t(`原确认已保存，页面切换未完成，请重新打开原确认。`);
						}});
					}
					if (res.data.marketplace_confirmation_version === 1 &&
						(confirmationRevision !== this.marketplaceConfirmRevision || this.marketplaceSubmitState !== 'editing')) return;
					that.applyMarketplaceMetadata(res.data);
					if (that.marketplaceV1) that.marketplaceMoneyFields(res.data.priceGroup, ['totalPrice', 'sumPrice', 'storePostage', 'storePostageDiscount', 'vipPrice', 'levelPrice', 'memberPrice']);
					that.$set(that, 'userInfo', res.data.userInfo);
					if (that.marketplaceV1) that.applyMarketplaceForms(res.data.custom_form);
					else {
						that.$set(that, 'confirm', res.data.custom_form || []);
						this.confirm.map(e => {
							if (e.label === 'img') e.value = []
						})
					}
					that.$set(that, 'integral', res.data.usable_integral);
					that.$set(that, 'usable_integral', res.data.usable_integral);
					that.$set(that, 'contacts', res.data.userInfo.real_name);
					that.$set(that, 'contactsTel', res.data.userInfo.record_phone === '0' ? res.data.userInfo.phone : res
						.data
						.userInfo.record_phone);
					that.$set(that, 'cartInfo', res.data.cartInfo);
					that.$set(that, 'integralRatio', res.data.integralRatio);
					that.$set(that, 'offlinePostage', res.data.offlinePostage);
					that.$set(that, 'orderKey', res.data.orderKey);
					if (guard) guard.key = that.orderKey;
					if (that.marketplaceV1) that.marketplaceSubmitBinding = bindMarketplaceSubmission(res.data);
					that.$set(that, 'valid_count', res.data.valid_count);
					that.$set(that, 'discount_id', res.data.discount_id)
					that.$set(that, 'priceGroup', res.data.priceGroup);
					if (that.marketplaceV1) {
						that.totalPrice = res.data.priceGroup.totalPrice;
						that.allPrice = res.data.priceGroup.sumPrice;
					} else {
					that.$set(that, 'totalPrice', that.$util.$h.Add(parseFloat(res.data.priceGroup.totalPrice),
						parseFloat(res.data
							.priceGroup.storePostage)));
					that.$set(that, 'allPrice', that.$util.$h.Add(parseFloat(res.data.priceGroup.totalPrice),
						parseFloat(res.data
							.priceGroup.vipPrice)).toFixed(2));
					}
					that.$set(that, 'seckillId', parseInt(res.data.seckill_id));
					that.$set(that, 'invoice_func', res.data.invoice_func);
					that.$set(that, 'special_invoice', res.data.special_invoice);
					that.$set(that, 'store_self_mention', res.data.store_self_mention);
					that.$set(that, 'virtual_type', res.data.virtual_type || 0);
					that.$set(that, 'integral_open', !that.marketplaceV1 && res.data.integral_open);
					uni.hideLoading()
					//微信支付是否开启
					that.cartArr[0].payStatus = res.data.pay_weixin_open || 0
					//支付宝是否开启
					that.cartArr[1].payStatus = res.data.ali_pay_status || 0;
					//#ifdef MP
					that.cartArr[1].payStatus = 0;
					//#endif
					//余额支付是否开启
					// that.cartArr[2].title = '可用余额:' + res.data.userInfo.now_money;
					that.cartArr[2].number = res.data.userInfo.now_money;
					that.cartArr[2].payStatus = !that.marketplaceV1 && res.data.yue_pay_status == 1 ? res.data.yue_pay_status : 0
					if (res.data.offline_pay_status == 2 || res.data.deduction) {
						that.cartArr[3].payStatus = 0
					} else {
						that.cartArr[3].payStatus = 1
					}
					//好友代付是否开启
					that.cartArr[4].payStatus = res.data.friend_pay_status || 0;
					// that.$set(that, 'cartArr', that.cartArr);
					that.$set(that, 'ChangePrice', that.totalPrice);
					if (!that.marketplaceV1) that.getBargainId();
					if (!versioned && !this.marketplaceV1 && !this.marketplaceRestoredUnknown && !this.marketplaceRestoring && !this.marketplaceResumeUnavailable &&
						typeof res.data.orderKey === 'string' && res.data.orderKey && res.data.orderKey === this.orderKey) {
						this.legacyConfirmedKey = res.data.orderKey;
						this._marketplaceInitializing = false;
						if (this.marketplaceMerIntent) this.marketplaceNotice = '';
					}
					setTimeout(() => {
						if (!that.marketplaceV1) that.getCouponList();
					}, 500);
					if (that.marketplaceV1) {
						this.getaddressInfo().then(() => this.computedPrice()).catch(error => {
							this.$util.Tips({title: error.message || error});
						});
					} else if (this.addressId && !this.is_gift) {
						this.computedPrice();
					}
				}).catch(err => {
					if (guard && !this.marketplaceGuardCurrent(guard)) return;
					uni.hideLoading()
					return this.$util.Tips({
						title: err
					});
				}).finally(() => {if (!guard || this.marketplaceGuardCurrent(guard)) this.marketplaceConfirmPending = 0;});
			},
			/*
			 * 提取砍价和拼团id
			 */
			getBargainId: function() {
				let that = this;
				let cartINfo = that.cartInfo;
				let BargainId = 0;
				let combinationId = 0;
				let discountId = 0;
				let advanceId = 0;
				cartINfo.forEach(function(value, index, cartINfo) {
					BargainId = cartINfo[index].bargain_id,
						combinationId = cartINfo[index].combination_id,
						discountId = cartINfo[index].discount_id,
						advanceId = cartINfo[index].advance_id
				})
				that.$set(that, 'BargainId', parseInt(BargainId));
				that.$set(that, 'combinationId', parseInt(combinationId));
				that.$set(that, 'discountId', parseInt(discountId));
				that.$set(that, 'advanceId', parseInt(advanceId));
				if (that.cartArr.length == 3 && (BargainId || combinationId || that.seckillId || discountId)) {
					that.cartArr[2].payStatus = 0;
					that.$set(that, 'cartArr', that.cartArr);
				}
			},
			/**
			 * 获取当前金额可用优惠券
			 * 
			 */
			getCouponList: function() {
				if (this.marketplaceV1) return;
				let shippingType = this.shippingType;
				let that = this;
				let data = {
					cartId: this.cartId,
					'new': this.news,
					'shippingType': parseInt(shippingType) + 1
				}
				getCouponsOrderPrice(this.totalPrice, data).then(res => {
					this.$set(this.coupon, 'list', res.data);
					if (!this.pinkId && !this.BargainId && !this.combinationId && !this.seckillId && !this.noCoupon && !this.discountId && !this.advanceId) {
						this.coupon.list.length && this.ChangCoupons(0)
					}
					this.openType = 1;
				});
			},
			/*
			 * 获取默认收货地址或者获取某条地址信息
			 */
			getaddressInfo: function() {
				if (this.marketplaceRestoredUnknown || this.marketplaceRestoring) return Promise.resolve();
				let that = this;
				if (that.marketplaceV1 && !that.marketplaceCanEdit) return Promise.resolve();
				const v1 = that.marketplaceV1, generation = that.marketplaceEditGeneration, key = that.orderKey;
				const guard = this.marketplaceOwner && (v1 || this.marketplaceMerIntent) ? this.marketplaceGuard('address') : null;
				const addressRevision = this._marketplaceAddressRevision = (this._marketplaceAddressRevision || 0) + 1;
				if (v1) that.marketplacePendingEdit(guard, true);
				const requestedAddressId = that.addressId;
				if (v1 && (!requestedAddressId || String(requestedAddressId) === '0')) {
					that.addressInfo = {}; that.marketplaceSelection.address = {mode: 'none', id: '0', status: 'none', snapshot: {}};
					that.marketplacePendingEdit(guard, false); that.persistMarketplaceContext(); return Promise.resolve();
				}
				let fnc = requestedAddressId ? getAddressDetail : getAddressDefault
				return fnc(requestedAddressId).then(res => {
					if (guard && (!this.marketplaceGuardCurrent(guard) || addressRevision !== this._marketplaceAddressRevision)) return;
					if (that.marketplaceV1 && !that.marketplaceCanEdit) return;
					if (v1 && (!that.marketplaceCanEdit || generation !== that.marketplaceEditGeneration || key !== that.orderKey)) return;
					if (that.marketplaceV1 && String(that.addressId) !== String(requestedAddressId)) return;
					if (!Array.isArray(res.data)) {
						res.data.is_default = parseInt(res.data.is_default);
						that.addressInfo = res.data || {};
						that.addressId = res.data.id || 0;
						that.address.addressId = res.data.id || 0;
						if (v1) {
							that.marketplaceSelection.address = {mode: 'id', id: String(requestedAddressId), status: String(res.data.id) === String(requestedAddressId) ? 'resolved' : 'unavailable', snapshot: {}};
							that.persistMarketplaceContext();
						}
					}
				}).catch(error => {
					if (v1 && this.marketplaceGuardCurrent(guard) && addressRevision === this._marketplaceAddressRevision) { that.addressInfo = {}; that.marketplaceSelection.address.status = 'unavailable'; that.persistMarketplaceContext(); }
					throw error;
				}).finally(() => {if (v1) that.marketplacePendingEdit(guard, false);});
			},
			onHaveAddressList() {
				this.haveAddressList = true
			},
			payItem: function(e) {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
				let that = this;
				let active = e;
				that.active = active;
				that.animated = true;
				that.payType = that.cartArr[active].value;
				if (that.marketplaceV1 && !that.persistMarketplaceContext()) return;
				that.computedPrice();
				setTimeout(function() {
					that.car();
				}, 500);
			},
			couponTap: function() {
				if (this.marketplaceV1) return;
				this.coupon.coupon = true;
				this.coupon.list.forEach((item, index) => {
					if (item.id == this.couponId) {
						item.is_use = 1
					} else {
						item.is_use = 0
					}
				})
				this.$set(this.coupon, 'list', this.coupon.list);
			},
			car: function() {
				let that = this;
				that.animated = false;
			},
			onAddress: function() {
				if (this.marketplaceRestoredUnknown || this.marketplaceRestoring) return;
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
				if (this.marketplaceV1 && !this.addressInfo.real_name && !this.haveAddressList) {
					return this.openMarketplacePage('/pages/users/user_address/index', 'marketplaceAddressSelected',
						(value) => this.OnChangeAddress(value.addressId));
				}
				let that = this;
				if (this.addressInfo.real_name || this.haveAddressList) {
					this.$refs.addressWindow.getAddressList();
					that.textareaStatus = false;
					that.address.address = true;
					that.pagesUrl = '/pages/users/user_address_list/index?news=' + this.news + '&cartId=' + this
						.cartId +
						'&pinkId=' +
						this.pinkId +
						'&couponId=' +
						this.couponId + 
						'&is_gift=' +
						that.is_gift + 
						'&order_id=' + that.orderId
				} else {
					let url = '/pages/users/user_address/index?cartId=' + this.cartId + '&pinkId=' + this
							.pinkId +
							'&couponId=' + this.couponId + '&new=' + this.news + '&is_gift=' + that.is_gift + '&order_id=' + that.orderId
					uni.navigateTo({
						url: url
					})
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
			payment(data, queuedGuard) {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring) return;
				if (this.marketplaceV1) return this.submitMarketplaceOrder();
				if (this.legacyHasPrivateForm || this._legacyFormSubmitGuard) return this.submitLegacyPrivateForm(data);
				if (this.legacyFormLocked && (!queuedGuard || !this.legacyFormCurrent(queuedGuard))) return;
				if (this.marketplaceResumeUnavailable || (this.marketplaceV1 && !this.marketplaceCanCreate)) {
					return this.$util.Tips({title: this.$t(`当前商城暂未开放提交订单`)});
				}
				let that = this;
				const legacyGuard = queuedGuard || this.legacyFormGuard(); this.legacyFormSubmitPending = true;
				orderCreate(that.orderKey, data).then(res => {
					if (!this.legacyFormCurrent(legacyGuard)) return;
					this.legacyFormSubmitted = true;
					let url = `/pages/goods/cashier/index?order_id=${res.data.result.orderId}&from_type=order`
					uni.reLaunch({
						url
					})
				}).catch(err => {
					if (!this.legacyFormCurrent(legacyGuard)) return;
					uni.hideLoading();
					return that.$util.Tips({
						title: err
					});
				}).finally(() => { if (this.legacyFormCurrent(legacyGuard)) this.legacyFormSubmitPending = false; });
			},
			clickTextArea() {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
				this.$refs.textarea.focus()
			},
			SubOrder(e) {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring) return;
				if (this.marketplaceV1) return this.submitMarketplaceOrder();
				if (this.legacyFormLocked) return;
				if (this.marketplaceResumeUnavailable || (this.marketplaceV1 && !this.marketplaceCanCreate)) {
					return this.$util.Tips({title: this.$t(`当前商城暂未开放提交订单`)});
				}
				let that = this,
					data = {};
				if (!that.addressId && !that.shippingType && !that.virtual_type && !that.is_gift) return that.$util.Tips({
					title: that.$t(`请选择收货地址`)
				});
				if (that.shippingType == 1) {
					if (that.contacts == "" || that.contactsTel == "") {
						return that.$util.Tips({
							title: that.$t(`请填写联系人或联系人电话`)
						});
					}
					if (!/^1(3|4|5|7|8|9|6)\d{9}$/.test(that.contactsTel)) {
						return that.$util.Tips({
							title: that.$t(`请输入正确的手机号码`)
						});
					}
					if (!that.contacts) {
						return that.$util.Tips({
							title: that.$t(`请输入姓名`)
						});
					}
					if (that.storeList.length == 0) return that.$util.Tips({
						title: that.$t(`暂无门店,请选择其他方式`)
					});
				}
				if (that.marketplaceV1) {
					const missing = firstMissingMarketplaceAnswer(that.marketplaceFormGroups);
					if (missing) return uni.showToast({
						title: missing.product_name + '：' + that.$t(missing.label === 'img' ? `请上传` : `请填写正确的`) + missing.title,
						icon: 'none'
					});
				}
				for (var i = 0; !that.marketplaceV1 && i < that.confirm.length; i++) {
					let data = that.confirm[i]
					if (data.status) {
						if (data.label === 'text' || data.label === 'data' || data.label === 'time' || data.label ===
							'id') {
							if (!data.value.trim()) {
								return uni.showToast({
									title: that.$t(`请输入`) + `${data.title}`,
									icon: 'none'
								});
							}
						}
						if (data.label === 'number') {
							if (data.value <= 0) {
								return uni.showToast({
									title: that.$t(`请输入`) + `${data.title}`,
									icon: 'none'
								});
							}
						}
						if (data.label === 'email') {
							if (!/^\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/.test(data.value)) {
								return uni.showToast({
									title: that.$t(`请输入正确的`) + `${data.title}`,
									icon: 'none'
								});
							}
						}
						if (data.label === 'phone') {
							if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(data.value)) {
								return uni.showToast({
									title: that.$t(`请输入正确的`) + `${data.title}`,
									icon: 'none'
								});
							}
						}
						if (data.label === 'img') {
							if (!data.value.length) {
								return uni.showToast({
									title: that.$t(`请上传`) + `${data.title}`,
									icon: 'none'
								});
							}
						}
					}
				}
				data = {
					custom_form: that.marketplaceV1 ? marketplaceFormAnswers(that.marketplaceFormGroups) : that.confirm,
					gift_mark: that.gift_mark, // 礼物留言
					real_name: that.contacts,
					phone: that.contactsTel,
					addressId: that.addressId,
					formId: '',
					couponId: that.couponId,
					useIntegral: that.useIntegral,
					bargainId: that.BargainId,
					combinationId: that.combinationId,
					discountId: that.discountId,
					pinkId: that.pinkId,
					advanceId: that.advanceId,
					seckill_id: that.seckillId,
					mark: that.mark,
					store_id: that.system_store ? that.system_store.id : 0,
					'from': that.from,
					shipping_type: that.$util.$h.Add(that.shippingType, 1),
					'new': that.news,
					'invoice_id': that.invChecked,
					// #ifdef H5
					quitUrl: location.protocol + '//' + location.hostname +
						'/pages/goods/order_pay_status/index?' +
						'&type=3' + '&totalPrice=' + this.totalPrice
					// #endif
					// #ifdef APP-PLUS
					quitUrl: '/pages/goods/order_details/index?order_id=' + this.order_id
					// #endif
				};
				if (that.is_gift) data.is_gift = that.is_gift
				if (data.payType == 'yue' && parseFloat(that.userInfo.now_money) < parseFloat(that.totalPrice))
					return that.$util.Tips({
						title: that.$t(`余额不足`)
					});
				// uni.showLoading({
				// 	title: that.$t(`订单支付中`)
				// });
				const legacySubmitGuard = this.legacyFormGuard();
				this.legacyFormSubmitPending = true;
				if (this.legacyHasPrivateForm) this._legacyFormSubmitGuard = legacySubmitGuard;
				data = JSON.parse(JSON.stringify(data));
				// #ifdef MP
				openPaySubscribe().then(() => {
					if (this.legacyFormCurrent(legacySubmitGuard)) that.payment(data, legacySubmitGuard);
				}).catch(() => { if (legacySubmitGuard && this.legacyFormCurrent(legacySubmitGuard)) { this.legacyFormSubmitPending = false; this._legacyFormSubmitGuard = null; } });
				// #endif
				// #ifndef MP
				that.payment(data, legacySubmitGuard);
				// #endif
			},
			receiveGift() {
				let data = {
					gift_key: this.giftData.gift_key,
					shipping_type: this.$util.$h.Add(this.shippingType, 1),
					name: this.contacts,
					phone: this.contactsTel,
					address_id: this.addressId,
					store_id: this.system_store ? this.system_store.id : 0,
				}
				orderReceiveGift(this.orderId, data).then(res => {
					uni.reLaunch({
						url: `/pages/goods/receive_gifts_status/index?status=${res.data.status}&order_id=${this.giftData.order_id}`
					})
				}).catch(err => {
					uni.showToast({
						icon: 'none',
						title: err
					})
				})
			},
			bindDateChange: function(e, index) {
				this.confirm[index].value = e.target.value
			},
			bindTimeChange: function(e, index) {
				this.confirm[index].value = e.target.value
			},
			getDate(type) {
				const date = new Date();
				let year = date.getFullYear();
				let month = date.getMonth() + 1;
				let day = date.getDate();

				if (type === 'start') {
					year = year - 60;
				} else if (type === 'end') {
					year = year + 2;
				}
				month = month > 9 ? month : '0' + month;
				day = day > 9 ? day : '0' + day;
				return `${year}-${month}-${day}`;
			},
			uploadpic: function(index, item) {
				if (this.marketplaceV1 || this.legacyFormLocked || !this.orderKey || this.confirm[index] !== item || item.label !== 'img' || !Array.isArray(item.value) || item.value.length >= 8) return;
				const first = this.cartInfo[0], productId = first && (first.product_id || first.productInfo && first.productInfo.id);
				const guard = this.legacyFormGuard(); let scope, outcome = false;
				const current = () => this.legacyFormCurrent(guard) && this.confirm[index] === item;
				this.legacyFormUploadPending = true; this.legacyFormNotice = ''; this.legacyFormReceipt = null;
				const finish = () => {
					if (current()) {
						if (this._legacyFormUploadIssued && !outcome) this.legacyFormFailure(null);
						this.legacyFormUploadPending = false; this.canvasStatus = false; this._legacyFormUploadIssued = false;
					}
					if (scope) scope.dispose();
					if (this._legacyFormUploadScope === scope) this._legacyFormUploadScope = null;
				};
				openOrderFormPrivateScope(current).then(value => {
					scope = value;
					if (!current()) { scope.dispose(); return; }
					this._legacyFormUploadScope = scope;
					const options = scope.legacyConfirmationUploadOptions(guard.key, productId, index), track = options.onUploadTask;
					options.onUploadTask = task => { track(task); if (current()) this._legacyFormUploadIssued = true; };
					this.canvasStatus = true;
					this.$util.uploadImageChange(options, response => {
						if (!current() || !scope.current()) return;
						outcome = true;
						try {
							const reference = legacyOrderFormUploadReference(response && response.data);
							if (item.value.length >= 8 || this.confirm.some(field => field.label === 'img' && Array.isArray(field.value) && field.value.includes(reference))) throw new Error('上传引用未确认');
							item.value.push(reference); this.legacyFormNotice = this.$t(`图片已暂存，提交订单后保存`);
						} catch (error) { this.legacyFormFailure(response); }
					}, response => { if (current()) { outcome = true; this.legacyFormFailure(response); } }, size => {
						if (current()) { this.canvasWidth = size.w; this.canvasHeight = size.h; }
					}, finish, () => current() && scope.current());
				}).catch(error => { if (current()) { if (this._legacyFormUploadIssued) this.legacyFormFailure(null); else this.legacyFormNotice = error && error.message || this.$t(`图片尚未上传，请重新选择`); } finish(); });
			},
			DelPic: function(index, indexs) {
				if (this.marketplaceV1 || this.legacyFormLocked) return;
				let that = this,
					pic = this.confirm[index].value;
				that.confirm[index].value.splice(indexs, 1);
				// that.$set(that, 'pics', that.pics);
			},
			inputTripClick() {
				if (this.marketplacePreparing || this.marketplaceRestoredUnknown || this.marketplaceRestoring || (this.marketplaceV1 && !this.marketplaceCanEdit)) return;
				this.inputTrip = true
				// this.$refs.trip.foucs()
			},
			showMaoLocation(e) {
				let self = this;
				// #ifdef H5
				if (self.$wechat.isWeixin()) {
					self.$wechat.seeLocation({
						latitude: Number(e.latitude),
						longitude: Number(e.longitude),
						name: e.name,
						scale: 13,
						address: `${e.address}-${e.detailed_address}`,
					}).then(res => {})
				} else {
					// #endif	
					uni.openLocation({
						latitude: Number(e.latitude),
						longitude: Number(e.longitude),
						name: e.name,
						address: `${e.address}-${e.detailed_address}`,
						success: function() {
							Number
						}
					});
					// #ifdef H5	
				}
				// #endif
			},
			call(phone) {
				uni.makePhoneCall({
					phoneNumber: phone,
				});
			},
		}
	}
</script>

<style lang="scss" scoped>
	.legacy-form-notice { margin: 20rpx 30rpx; color: #666; font-size: 26rpx; line-height: 1.6; overflow-wrap: anywhere; }
	.legacy-form-image-note { display: block; padding: 10rpx; box-sizing: border-box; font-size: 22rpx; line-height: 1.5; color: #666; overflow-wrap: anywhere; }
	.confirmImg .list .legacy-form-picture { height: auto; min-height: 136rpx; }
	.legacy-form-picture .legacy-form-image-note { padding-top: 46rpx; }
	.confirmImg .list .legacy-form-upload { display: flex; align-items: center; justify-content: center; flex-direction: column; box-sizing: border-box; padding: 0; line-height: 1.5; background: transparent; }
	.confirmImg .list .legacy-form-upload[disabled] { opacity: .55; }
	.confirmImg .list .legacy-form-delete { position: absolute; top: 0; right: 0; width: 44rpx; height: 44rpx; margin: 0; padding: 0; display: flex; align-items: center; justify-content: center; line-height: 1; color: #fff; background: rgba(0,0,0,.65); border-radius: 0 8rpx 0 8rpx; }
	.legacy-form-delete::after,.legacy-form-upload::after { border: 0; }
	::v-deep uni-checkbox[disabled] .uni-checkbox-input {
		background-color: #eee;
	}

	.alipaysubmit {
		display: none;
	}

	.order-submission {
		/* #ifndef H5 */
		padding-bottom: 70rpx;
		/* #endif */
	}

	.order-submission .line {
		width: 100%;
		height: 3rpx;
	}

	.order-submission .line image {
		width: 100%;
		height: 100%;
		display: block;
	}

	.order-submission .address {
		padding: 28rpx 30rpx;
		background-color: #fff;
		box-sizing: border-box;
		flex-wrap: nowrap;

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

	.order-submission .address .addressCon {
		max-width: 510rpx;
		font-size: 26rpx;
		color: #666;
	}

	.order-submission .address .addressCon .name {
		font-size: 30rpx;
		color: #282828;
		font-weight: bold;
		margin-bottom: 10rpx;
	}

	.order-submission .address .addressCon .name .phone {
		margin-left: 50rpx;
	}

	.order-submission .address .addressCon .default {
		margin-right: 12rpx;
	}

	.order-submission .address .addressCon .setaddress {
		color: #333;
		font-size: 28rpx;
	}

	.order-submission .address .iconfont {
		font-size: 35rpx;
		color: #707070;
	}

	.gift-box {
		background: #FFFFFF;
		margin: 20rpx 0;

		.user-msg {
			padding: 28rpx 30rpx;
		}

		image {
			width: 36rpx;
			height: 36rpx;
			border-radius: 70rpx 70rpx 70rpx 70rpx;
			border: 2rpx solid #FFFFFF;
		}

		.nickname {
			font-weight: 400;
			font-size: 28rpx;
			color: #AE5A2A;
		}

		.gift-mark {
			border-top: 1px solid #F0F0F0;
			font-weight: 400;
			font-size: 28rpx;
			color: #333333;
			padding: 28rpx 30rpx;
		}
	}

	.receive-btn {
		color: #fff;
		text-align: center;
		height: 84rpx;
		line-height: 84rpx;
		border-radius: 80rpx;
		margin: 66rpx 40rpx;
		background-color: var(--view-theme);
	}

	.gift-msg {
		padding: 0rpx 30rpx 20rpx 30rpx;
		background-color: #fff;

		input {
			font-size: 30rpx;
		}

		.placeholder {
			font-size: 28rpx;
		}
	}

	.order-submission .allAddress {
		width: 100%;
		background: linear-gradient(to bottom, var(--view-theme) 0%, #f5f5f5 100%);
		padding-top: 100rpx;
		margin-bottom: 12rpx;

		.add-title {
			height: 72rpx;
			margin: 0 26rpx;
			border-bottom: 1px solid #eee;
			background-color: #fff;
			width: 710rpx;
			margin: 0 auto;
			padding: 0 26rpx;
			border-radius: 12rpx 12rpx 0 0;

			.icon {
				height: 32rpx;
				background: #1890FF;
				border-radius: 4rpx;
				font-size: 20rpx;
				font-weight: 400;
				color: #FFFFFF;
				text-align: center;
				line-height: 32rpx;
				padding: 0 6rpx;

				&.orange {
					background: #FE960F;
				}

				&.red {
					background: #E93323;
				}
			}

			.add-text {
				margin-left: 14rpx;
				width: 360rpx;
			}

			.text {
				font-size: 24rpx;
				font-weight: 400;
				color: #999999;

				.icon-jiantou {
					display: inline-block;
					font-size: 20rpx;
				}
			}
		}
	}

	.order-submission .allAddress .nav {
		width: 710rpx;
		margin: 0 auto;
	}

	.order-submission .allAddress .nav .item {
		width: 355rpx;
	}

	.order-submission .allAddress .nav .item.on {
		position: relative;
		width: 270rpx;
	}

	.order-submission .allAddress .nav .item.on .before {
		position: absolute;
		bottom: 0;
		// content: "快递配送";
		left: 0;
		font-size: 28rpx;
		display: block;
		height: 0;
		width: 356rpx;
		border-width: 0 20rpx 80rpx 0;
		border-style: none solid solid;
		border-color: transparent transparent #fff;
		z-index: 2;
		border-radius: 7rpx 30rpx 0 0;
		text-align: center;
		line-height: 80rpx;
	}

	.order-submission .allAddress .nav .item:nth-of-type(2).on .before {
		// content: "到店自提";
		border-width: 0 0 80rpx 20rpx;
		border-radius: 30rpx 7rpx 0 0;
	}

	.order-submission .allAddress .nav .item.on2 {
		position: relative;
	}

	.order-submission .allAddress .nav .item.on2 .before {
		position: absolute;
		bottom: 0;
		left: 0;
		// content: "到店自提";
		font-size: 28rpx;
		display: block;
		height: 0;
		width: 440rpx;
		border-width: 0 0 60rpx 60rpx;
		border-style: none solid solid;
		border-color: transparent transparent rgba(255, 255, 255, 0.6);
		border-radius: 40rpx 6rpx 0 0;
		text-align: center;
		line-height: 60rpx;
	}

	.order-submission .allAddress .nav .item:nth-of-type(1).on2 .before {
		// content: "快递配送";
		border-width: 0 60rpx 60rpx 0;
		border-radius: 6rpx 40rpx 0 0;
	}

	.order-submission .allAddress .address {
		width: 710rpx;
		height: 150rpx;
		margin: 0 auto;
	}

	.order-submission .allAddress .line {
		width: 710rpx;
		margin: 0 auto;
	}

	.order-submission .wrapper .item .discount .placeholder {
		color: #ccc;
	}

	.placeholder-textarea {
		position: relative;

		.placeholder {
			position: absolute;
			color: #ccc;
			top: 26rpx;
			left: 30rpx;
		}
	}

	.order-submission .wrapper {
		background-color: #fff;
		margin-top: 13rpx;
	}

	.order-submission .wrapper .item {
		padding: 27rpx 30rpx;
		font-size: 30rpx;
		color: #282828;
		border-bottom: 1px solid #f0f0f0;

		.mark {
			background-color: #f9f9f9;
			// width: 345px;
			min-height: 70px;
			border-radius: 1px;
			margin-top: 15px;
			padding: 12px 14px;
			color: #ccc;
			font-size: 28rpx;
			box-sizing: border-box;
		}

		.mark-msg {
			color: #333;
			font-size: 28rpx;
		}
	}

	.order-submission .wrapper .item .discount {
		font-size: 30rpx;
		color: #999;
	}

	.order-submission .wrapper .item .discount input {
		text-align: end;
	}

	.order-submission .wrapper .item .discount .iconfont {
		color: #515151;
		font-size: 30rpx;
		margin-left: 15rpx;
	}

	.order-submission .wrapper .item .discount .num {
		font-size: 32rpx;
		margin-right: 20rpx;
	}

	.order-submission .wrapper .item .shipping {
		font-size: 30rpx;
		color: #999;
		position: relative;
		padding-right: 58rpx;
	}

	.order-submission .wrapper .item .shipping .iconfont {
		font-size: 35rpx;
		color: #707070;
		position: absolute;
		right: 0;
		top: 50%;
		transform: translateY(-50%);
		margin-left: 30rpx;
	}

	.order-submission .wrapper .item textarea {
		background-color: #f9f9f9;
		width: 100%;
		height: 135rpx;
		border-radius: 3rpx;
		margin-top: 30rpx;
		padding: 25rpx 28rpx;
		font-size: 28rpx;
		box-sizing: border-box;
	}

	.order-submission .wrapper .item .placeholder {
		color: #ccc;
		font-size: 28rpx;
	}

	.order-submission .wrapper .item .list {
		margin-top: 35rpx;
	}

	.order-submission .wrapper .item .list .payItem {
		border: 1px solid #eee;
		border-radius: 6rpx;
		height: 86rpx;
		width: 100%;
		box-sizing: border-box;
		margin-top: 20rpx;
		font-size: 28rpx;
		color: #282828;
	}

	.order-submission .wrapper .item .list .payItem.on {
		border-color: #fc5445;
		color: #e93323;
	}

	.order-submission .wrapper .item .list .payItem .name {
		width: 50%;
		text-align: center;
		border-right: 1px solid #eee;
		padding-left: 80rpx;
	}

	.order-submission .wrapper .item .list .payItem .name .iconfont {
		width: 44rpx;
		height: 44rpx;
		border-radius: 50%;
		text-align: center;
		line-height: 44rpx;
		background-color: #fe960f;
		color: #fff;
		font-size: 30rpx;
		margin-right: 15rpx;
	}

	.order-submission .wrapper .item .list .payItem .name .iconfont.icon-weixin2 {
		background-color: #41b035;
	}

	.order-submission .wrapper .item .list .payItem .name .iconfont.icon-zhifubao {
		background-color: #1677FF;
	}

	.order-submission .wrapper .item .list .payItem .tip {
		width: 49%;
		text-align: center;
		font-size: 26rpx;
		color: #aaa;
	}

	.order-submission .moneyList {
		margin-top: 12rpx;
		background-color: #fff;
		padding: 30rpx;
	}

	.order-submission .moneyList .item {
		font-size: 28rpx;
		color: #282828;
	}

	.order-submission .moneyList .item~.item {
		margin-top: 20rpx;
	}

	.order-submission .moneyList .item .money {
		color: #868686;
	}

	.order-submission .footer {
		width: 100%;
		background-color: #fff;
		font-size: 28rpx;
		color: #333;
		box-sizing: border-box;
		position: fixed;
		left: 0;
		bottom: 0;
		padding: 15rpx 30rpx;
		padding-bottom: calc(15rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		padding-bottom: calc(15rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		z-index: 9;
	}

	.order-submission .footer .settlement {
		font-size: 30rpx;
		color: #fff;
		width: 240rpx;
		height: 70rpx;
		background-color: var(--view-theme);
		border-radius: 50rpx;
		text-align: center;
		line-height: 70rpx;
	}

	.marketplace-checkout-notice {
		margin: 20rpx 30rpx;
		color: #333;
		font-size: 28rpx;
		line-height: 1.5;
		text-align: left;
	}
	.marketplace-saved-context { overflow-wrap: anywhere; word-break: break-word; }

	.order-submission .footer .marketplace-submit {
		box-sizing: border-box;
		padding: 0;
		margin: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
	}

	.order-submission .footer .marketplace-submit[disabled] {
		background-color: #eee;
		color: #868686;
	}

	.marketplace-adjust {
		box-sizing: border-box;
		width: 100%;
		margin: 16rpx 0 0;
		padding: 10rpx 20rpx;
		min-height: 64rpx;
		display: flex;
		align-items: center;
		justify-content: center;
		text-align: center;
		font: inherit;
		line-height: 1.4;
		color: #333;
		background-color: #fff;
		border: 1rpx solid #ddd;
	}

	.marketplace-adjust::after,
	.marketplace-submit::after {
		border: 0;
	}

	.footer .transparent {
		opacity: 0
	}

	.confirm {
		text-align: right;
		font-size: 22rpx;
	}

	.confirmImg {
		width: 100%;

		.img {
			width: 136rpx;
			height: 136rpx;
		}

		.pictrue {
			width: 136rpx;
			height: 136rpx;
			box-sizing: border-box;
			margin: 18rpx;
			margin-bottom: 35rpx;
			position: relative;
			font-size: 22rpx;
			color: #bbb;

			.del {
				position: absolute;
				top: 0;
				right: 0;
			}
		}

		.bor {
			border: 1rpx solid #ddd;
		}

	}

	.fontC {
		color: grey;
	}
</style>
