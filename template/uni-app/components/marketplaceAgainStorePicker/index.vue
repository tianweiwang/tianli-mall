<template>
	<view v-if="visible" class="again-store-picker">
		<view class="again-store-mask" @tap="cancel" @touchmove.stop.prevent></view>
		<view class="again-store-panel">
			<view class="again-store-title">{{$t(`选择购买店铺`)}}</view>
			<view class="again-store-notice">{{$t(`本次商品来自多家店铺，请选择一家继续确认订单。`)}}</view>
			<scroll-view class="again-store-list" scroll-y :show-scrollbar="true">
				<view class="again-store-options">
					<button v-for="group in groups" :key="group.mer_id" class="again-store-option"
						:disabled="disabled" @tap="choose(group.mer_id)">
						<view class="again-store-option-content">
							<text class="again-store-name">{{group.store.name}}</text>
							<text class="again-store-count">{{group.cart_refs.length}} {{$t(`项商品`)}}</text>
						</view>
					</button>
				</view>
			</scroll-view>
			<button class="again-store-cancel" :disabled="disabled" @tap="cancel">{{$t(`取消`)}}</button>
		</view>
	</view>
</template>

<script>
export default {
	props: {
		visible: { type: Boolean, default: false },
		groups: { type: Array, default: () => [] },
		disabled: { type: Boolean, default: false }
	},
	methods: {
		choose(merId) {
			if (!this.disabled) this.$emit('select', merId);
		},
		cancel() {
			if (!this.disabled) this.$emit('cancel');
		}
	}
};
</script>

<style scoped lang="scss">
.again-store-picker {
	position: fixed;
	top: 0;
	right: 0;
	bottom: 0;
	left: 0;
	z-index: 1100;
	display: flex;
	align-items: center;
	justify-content: center;
	box-sizing: border-box;
	padding: 32rpx;
}
.again-store-mask {
	position: absolute;
	top: 0;
	right: 0;
	bottom: 0;
	left: 0;
	background: rgba(0, 0, 0, 0.5);
}
.again-store-panel {
	position: relative;
	display: flex;
	flex-direction: column;
	box-sizing: border-box;
	width: 100%;
	max-width: 560px;
	max-height: calc(100vh - 64rpx);
	padding: 32rpx;
	border-radius: 20rpx;
	background: #fff;
}
.again-store-title {
	flex-shrink: 0;
	font-size: 32rpx;
	font-weight: bold;
	text-align: center;
	color: #333;
}
.again-store-notice {
	flex-shrink: 0;
	margin-top: 18rpx;
	font-size: 26rpx;
	line-height: 1.5;
	color: #666;
}
.again-store-list {
	flex: 0 1 44vh;
	min-height: 0;
	height: 44vh;
	max-height: 520rpx;
	margin-top: 24rpx;
}
.again-store-options {
	padding: 1rpx;
}
.again-store-option,
.again-store-cancel {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	box-sizing: border-box;
	width: 100%;
	min-width: 0;
	margin: 0;
	padding: 22rpx 20rpx;
	font-size: 28rpx;
	line-height: 1.5;
	text-align: center;
	white-space: normal;
	border-radius: 12rpx;
}
.again-store-option::after,
.again-store-cancel::after {
	border: 0;
}
.again-store-option {
	margin-bottom: 16rpx;
	border: 1rpx solid #eee;
	color: #333;
	background: #fff;
}
.again-store-option-content {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	min-width: 0;
	max-width: 100%;
}
.again-store-name {
	white-space: normal;
	word-break: break-all;
}
.again-store-count {
	margin-top: 6rpx;
	font-size: 24rpx;
	color: #888;
}
.again-store-cancel {
	margin-top: 24rpx;
	color: #666;
	background: #f5f5f5;
}
</style>
