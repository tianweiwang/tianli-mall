<template>
	<view>
		<view class="wrapper card" v-if="savedMarketplaceV1 && savedGroups.length">
			<view class="saved-product" v-for="group in savedGroups" :key="String(group.product_id)+':'+group.definition_sha256">
				<view class="saved-product-name">{{group.product_name}}</view>
				<view class="saved-field" v-for="field in group.fields" :key="field.field_index">
					<view class="saved-title">{{field.field_index+1}}. {{field.title}}：</view>
					<view v-if="field.label==='img'" class="saved-images">
						<image v-for="(image,index) in field.value" :key="index" :src="orderFormImageSource(group,field,index)" mode="aspectFill"
							@click="previewOrderFormImages(group,field,index)"></image>
					</view>
					<view v-else class="saved-value">{{field.value}}</view>
				</view>
			</view>
		</view>
	<!-- 自定义组件 -->
	<view class='wrapper card' v-if="!savedMarketplaceV1 && customForm && customForm.length && isShow">
		<view class='item acea-row row-between' v-for="(item,index) in customForm" :key="index" v-if="(item.value && ['uploadPicture','dateranges'].indexOf(item.name) == -1) || (item.value.length && ['uploadPicture','dateranges'].indexOf(item.name) != -1)">
			<view class="title">{{item.titleConfig.value}}：</view>
			<view v-if="item.name == 'uploadPicture'" class='conter'>
				<view class='pictrue' v-for="(img,indexn) in item.value" :key="indexn">
					<image :src='img' mode="aspectFill" @click='getCustomForm(index,indexn)'></image>
				</view>
			</view>
			<view v-else-if="item.name == 'dateranges'" class="conter">
			   <text v-if="item.value.length">{{item.value[0]+'/'+item.value[1]}}</text>
			</view>
			<view v-else class='conter'>{{item.value}}</view>
		</view>
		<slot name="bottom"></slot>
	</view>
	</view>
</template>

<script>
	import orderFormImages from '@/mixins/marketplace-order-form-images.js';
	export default {
		name: 'customForm',
		mixins: [orderFormImages],
		props: {
			privateRootId: {type: [Number,String], default: null},
			formImagesActive: {type: Boolean, default: true},
			tradeVersion: {type: [Number,String], default: 0},
			customForm:{
				type: Array,
				default: () => []
			}
		},
		computed: {
			orderFormImageContext() { return this.savedMarketplaceV1 && this.privateRootId !== null ? {kind: 'mobile_order', rootId: this.privateRootId} : null; },
			orderFormImageGroups() { return this.savedGroups; },
			orderFormImagesActive() { return this.formImagesActive; },
			savedMarketplaceV1() { return this.tradeVersion===1 || this.tradeVersion==='1'; },
			savedGroups() {
				if(!this.savedMarketplaceV1)return [];
				return this.customForm.map(group => ({product_id:group.product_id,product_name:group.product_name,
					definition_sha256:group.definition_sha256,fields:group.fields.map(field => ({field_index:field.field_index,
						title:field.title,label:field.label,status:field.status,value:Array.isArray(field.value)?field.value.slice():field.value}))
						.filter(field => field.value!=='' && !(Array.isArray(field.value)&&field.value.length===0))}))
					.filter(group => group.fields.length);
			}
		},
		data() {
			return {
				isShow:0
			};
		},
		watch: {
			customForm (value) {
				if(value && value.length){
					value.forEach((item)=>{
						if(item.value){
							return this.isShow = 1
						}
					})
				}
			}
		},
		created() {},
		mounted() {},
		methods: {
			getCustomForm: function(index,indexn) {
				uni.previewImage({
					urls: this.customForm[index].value,
					current: this.customForm[index].value[indexn]
				});
			},
		}
	};
</script>

<style lang="scss">
	.saved-product + .saved-product { margin-top: 24rpx; }
	.saved-product-name { font-weight: bold; margin-bottom: 16rpx; overflow-wrap: anywhere; }
	.saved-field { margin-top: 16rpx; font-size: 28rpx; }
	.saved-title,.saved-value { white-space: pre-wrap; overflow-wrap: anywhere; }
	.saved-value { margin-top: 8rpx; color: #666; }
	.saved-images { display: flex; flex-wrap: wrap; gap: 12rpx; margin-top: 8rpx; }
	.saved-images image { width: 100rpx; height: 100rpx; border-radius: 6rpx; }
	.wrapper{
		    background-color: #fff;
		    margin-top: 6px;
		    padding: 15px;
	}
	.wrapper .item {
		font-size: 28rpx;
		color: #282828;
	}
	
	.wrapper .item .title{
		width: 200rpx;
	}
	
	.wrapper .item~.item {
		margin-top: 20rpx;
		white-space: normal;
		word-break: break-all;
		word-wrap: break-word;
	}
	
	.wrapper .item .conter {
		color: #868686;
		width: 460rpx;
		display: flex;
		flex-wrap: nowrap;
		justify-content: flex-end;
		text-align: right;
		.pictrue{
			width: 80rpx;
			height: 80rpx;
			margin-left: 6rpx;
			image{
				width: 100%;
				height: 100%;
				border-radius: 6rpx;
			}
		}
	}
</style>
