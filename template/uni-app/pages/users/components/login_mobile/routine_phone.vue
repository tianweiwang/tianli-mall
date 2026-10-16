<template>
	<view v-if="isPhoneBox">
		<view class="mobile-bg"></view>
		<view class="mobile-mask animated" :class="{slideInUp:isUp}">
			<view class="info-box">
				<image :src="logoUrl"></image>
				<view class="title">{{$t(`获取授权`)}}</view>
				<view class="txt">{{$t(`获取手机号授权`)}}</view>
			</view>
			<button class="sub_btn" open-type="getPhoneNumber"
				@getphonenumber="getphonenumber">{{$t(`获取手机号`)}}</button>
		</view>
	</view>
</template>
<script>
	const app = getApp();
	import Routine from '@/libs/routine';
	import {
		loginMobile,
		getCodeApi
	} from "@/api/user";
	import {
		getLogo,
		silenceAuth,
		routineBindingPhone
	} from '@/api/public';
	export default {
		name: 'routine_phone',
		props: {
			isPhoneBox: {
				type: Boolean,
				default: false,
			},
			logoUrl: {
				type: String,
				default: '',
			},
			authKey: {
				type: String,
				default: '',
			}
		},

		data() {
			return {
				keyCode: '',
				account: '',
				codeNum: '',
				isStatus: false,
				identityActive: true,
				identityAttemptRevision: 0,
				userInfo: {}
			}
		},
		mounted() {},
		beforeDestroy() {
			this.identityActive = false;
			this.identityAttemptRevision++;
		},
		watch: {
			isPhoneBox(value) {
				if (!value) this.identityAttemptRevision++;
			}
		},
		methods: {
			identityScope() {
				return { revision: this.$store.state.app.authRevision, token: this.$store.state.app.token,
					attempt: this.identityAttemptRevision };
			},
			isIdentityScope(scope) {
				return this.identityActive && this.isPhoneBox && scope.attempt === this.identityAttemptRevision &&
					this.$store.state.app.authRevision === scope.revision && this.$store.state.app.token === scope.token;
			},
			// #ifdef MP
			// 小程序获取手机号码
			getphonenumber(e) {
				this.identityAttemptRevision++;
				const scope = this.identityScope();
				uni.showLoading({
					title: this.$t(`正在登录中`)
				});
				Routine.getCode()
					.then(code => {
						if (!this.isIdentityScope(scope)) return;
						return this.getUserPhoneNumber(e.detail.encryptedData, e.detail.iv, code, scope);
					})
					.catch(error => {
						if (!this.isIdentityScope(scope) || (error && error.staleIdentity)) return;
						uni.hideLoading();
					});
			},
			// 小程序获取手机号码回调
			getUserPhoneNumber(encryptedData, iv, code, scope = this.identityScope()) {
				if (!this.isIdentityScope(scope)) return;
				return routineBindingPhone({
						encryptedData: encryptedData,
						iv: iv,
						code: code,
						spread_spid: app.globalData.spid,
						spread_code: app.globalData.code,
						key: this.authKey
					})
					.then(async res => {
						if (!this.isIdentityScope(scope)) return;
						let time = res.data.expires_time - this.$Cache.time();
						this.$store.commit('LOGIN', {
							token: res.data.token,
							time: time
						});
						scope = this.identityScope();
						const user = await this.getUserInfo(scope);
						if (!user || !this.isIdentityScope(scope)) return;
						this.$emit('loginSuccess', {
							isStatus: true,
							new_user: res.data.userInfo.new_user
						})
					})
					.catch(res => {
						if (!this.isIdentityScope(scope) || (res && res.staleIdentity)) return;
						uni.hideLoading();
						this.$util.Tips({title: (res && (res.msg || res.message)) || res});
					});
			},
			/**
			 * 获取个人用户信息
			 */
			getUserInfo: function(scope = this.identityScope()) {
				let that = this;
				return this.$store.dispatch('USERINFO', true).then(user => {
					if (!this.isIdentityScope(scope) || this.$store.state.app.verifiedUid !== String(user.uid)) return null;
					uni.hideLoading();
					that.userInfo = JSON.parse(JSON.stringify(user));
					that.isStatus = true
					return user;
				});
			},
			// #endif
			close(new_user) {
				if (!this.identityActive) return;
				this.identityAttemptRevision++;
				this.$emit('close', {
					isStatus: this.isStatus,
					new_user
				})
			}
		}
	}
</script>

<style lang="scss">
	.mobile-bg {
		position: fixed;
		left: 0;
		top: 0;
		width: 100%;
		height: 100%;
		background: rgba(0, 0, 0, 0.5);
	}

	.mobile-mask {
		z-index: 20;
		position: fixed;
		left: 0;
		bottom: 0;
		width: 100%;
		padding: 67rpx 30rpx;
		background: #fff;

		.info-box {
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;

			image {
				width: 150rpx;
				height: 150rpx;
				border-radius: 10rpx;
			}

			.title {
				margin-top: 30rpx;
				margin-bottom: 20rpx;
				font-size: 36rpx;
			}

			.txt {
				font-size: 30rpx;
				color: #868686;
			}
		}

		.sub_btn {
			width: 690rpx;
			height: 86rpx;
			line-height: 86rpx;
			margin-top: 60rpx;
			background: var(--view-theme);
			border-radius: 43rpx;
			color: #fff;
			font-size: 28rpx;
			text-align: center;
		}
	}

	.animated {
		animation-duration: .4s
	}
</style>
