<template>
	<view v-if="isUp">
		<view class="mobile-bg" @click="close"></view>
		<view class="mobile-mask animated" :class="{slideInUp:isUp}">
			<view class="input-item">
				<input type="text" v-model="account" :placeholder="$t(`输入手机号`)" maxlength="11" />
			</view>
			<view class="input-item">
				<input type="text" v-model="codeNum" :placeholder="$t(`输入验证码`)" maxlength="6" />
				<button class="code" :disabled="disabled" @click="code">{{text}}</button>
			</view>
			<view class="sub_btn" @click="loginBtn">{{$t(`立即登录`)}}</view>
		</view>

		<Verify @success="success" :captchaType="captchaType" :imgSize="{ width: '330px', height: '155px' }"
			ref="verify"></Verify>
	</view>
</template>

<script>
	const app = getApp();
	import sendVerifyCode from "@/mixins/SendVerifyCode";
	import Routine from '@/libs/routine';
	import Verify from '../verify/index.vue';
	import Cache from '@/utils/cache';
	import {
		loginMobile,
		registerVerify,
		getCodeApi,
		phoneSilenceAuth,
		phoneWxSilenceAuth
	} from "@/api/user";
	import {
		bindingPhone
	} from '@/api/api.js'
	import {
		silenceAuth
	} from '@/api/public.js'
	export default {
		name: 'login_mobile',
		components: {
			Verify
		},
		props: {
			isUp: {
				type: Boolean,
				default: false,
			},
			canClose: {
				type: Boolean,
				default: true,
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
				identityActive: true,
				identityAttemptRevision: 0,
				userInfo: {}
			}
		},
		mixins: [sendVerifyCode],
		mounted() {
			this.getCode();
		},
		beforeDestroy() {
			this.identityActive = false;
			this.identityAttemptRevision++;
		},
		watch: {
			isUp(value) {
				if (!value) this.identityAttemptRevision++;
			}
		},
		methods: {
			identityScope() {
				return { revision: this.$store.state.app.authRevision, token: this.$store.state.app.token,
					attempt: this.identityAttemptRevision };
			},
			isIdentityScope(scope) {
				return this.identityActive && this.isUp && scope.attempt === this.identityAttemptRevision &&
					this.$store.state.app.authRevision === scope.revision && this.$store.state.app.token === scope.token;
			},
			success(data) {
				let that = this;
				this.$refs.verify.hide()
				getCodeApi().then(res => {
					registerVerify({
						phone: that.account,
						key: res.data.key,
						captchaType: this.captchaType,
						captchaVerification: data.captchaVerification
					}).then(res => {
						that.$util.Tips({
							title: res.msg
						});
						that.sendCode();
					}).catch(err => {
						return that.$util.Tips({
							title: err
						})
					})
				})
			},
			// 获取验证码
			code() {
				let that = this;
				if (!that.account) return that.$util.Tips({
					title: that.$t(`请填写手机号码`)
				});
				if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.account)) return that.$util.Tips({
					title: that.$t(`请输入正确的手机号码`)
				});
				this.$refs.verify.show();
			},
			// 获取验证码api
			getCode() {
				let that = this
				getCodeApi().then(res => {
					that.keyCode = res.data.key;
				}).catch(res => {
					that.$util.Tips({
						title: res
					});
				});
			},
			close(new_user) {
				if (this.canClose) {
					this.identityAttemptRevision++;
					this.$emit('close', new_user)
				}
			},
			// 登录
			loginBtn() {
				let that = this
				this.identityAttemptRevision++;
				const scope = this.identityScope();
				// #ifdef MP
				if (!that.account) return that.$util.Tips({
					title: that.$t(`请填写手机号码`)
				});
				if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.account)) return that.$util.Tips({
					title: that.$t(`请输入正确的手机号码`)
				});
				if (!that.codeNum) return that.$util.Tips({
					title: that.$t(`请填写验证码`)
				});
				if (!/^[\w\d]+$/i.test(that.codeNum)) return that.$util.Tips({
					title: that.$t(`请输入正确的验证码`)
				});
				uni.showLoading({
					title: that.$t(`正在登录中`)
				});
				Routine.getCode()
					.then(code => {
						if (!this.isIdentityScope(scope)) return;
						return this.phoneSilenceAuth(code, scope);
					})
					.catch(error => {
						if (!this.isIdentityScope(scope) || (error && error.staleIdentity)) return;
						uni.hideLoading();
					});
				// #endif
				// #ifdef H5
				if (!that.account) return that.$util.Tips({
					title: that.$t(`请填写手机号码`)
				});
				if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(that.account)) return that.$util.Tips({
					title: that.$t(`请输入正确的手机号码`)
				});
				if (!that.codeNum) return that.$util.Tips({
					title: that.$t(`请填写验证码`)
				});
				if (!/^[\w\d]+$/i.test(that.codeNum)) return that.$util.Tips({
					title: that.$t(`请输入正确的验证码`)
				});
				uni.showLoading({
					title: that.$t(`正在登录中`)
				});
				if (!this.authKey) {
					let key = this.$Cache.get('snsapiKey');
					this.phoneAuth(key)
				} else {
					this.phoneAuth(this.authKey)
				}
				// #endif
			},
			phoneAuth(key) {
				let scope = this.identityScope();
				if (!this.isIdentityScope(scope)) return;
				return phoneWxSilenceAuth({
					spid: app.globalData.spid,
					spread: app.globalData.code,
					phone: this.account,
					captcha: this.codeNum,
					key
				}).then(res => {
					if (!this.isIdentityScope(scope)) return;
					let time = res.data.expires_time - this.$Cache.time();
					this.$store.commit('LOGIN', {
						token: res.data.token,
						time: time
					});
					scope = this.identityScope();
					return this.getUserInfo(undefined, scope);
				}).catch(error => {
					if (!this.isIdentityScope(scope) || (error && error.staleIdentity)) return;
					uni.hideLoading()
					this.$util.Tips({
						title: error
					})
				})
			},
			// #ifdef MP
			phoneSilenceAuth(code, scope = this.identityScope()) {
				let self = this
				if (!this.isIdentityScope(scope)) return;
				return phoneSilenceAuth({
					code: code,
					spread_spid: app.globalData.spid,
					spread_code: app.globalData.code,
					phone: this.account,
					captcha: this.codeNum
				}).then(res => {
					if (!this.isIdentityScope(scope)) return;
					let time = res.data.expires_time - this.$Cache.time();
					this.$store.commit('LOGIN', {
						token: res.data.token,
						time: time
					});
					scope = this.identityScope();
					return this.getUserInfo(res.data.new_user, scope);
				}).catch(error => {
					if (!this.isIdentityScope(scope) || (error && error.staleIdentity)) return;
					uni.hideLoading();
					self.$util.Tips({
						title: error
					})
				})
			},
			// #endif
			/**
			 * 获取个人用户信息
			 */
			getUserInfo: function(new_user, scope = this.identityScope()) {
				let that = this;
				return this.$store.dispatch('USERINFO', true).then(user => {
					if (!this.isIdentityScope(scope) || this.$store.state.app.verifiedUid !== String(user.uid)) return;
					uni.hideLoading();
					that.userInfo = JSON.parse(JSON.stringify(user));
					// #ifdef MP
					if (!new_user) {
						that.$util.Tips({
							title: that.$t(`登录成功`),
							icon: 'success'
						}, {
							tab: 3
						})
					} else {
						that.$util.Tips({
							title: that.$t(`登录成功`),
							icon: 'success'
						})
					}
					that.close(new_user || 0)
					// #endif
					// #ifdef H5
					that.$emit('wechatPhone', true)
					// #endif
				});
			},
		}
	}
</script>

<style lang="stylus">
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

		.input-item {
			display: flex;
			justify-content: space-between;
			width: 100%;
			height: 86rpx;
			margin-bottom: 38rpx;

			input {
				flex: 1;
				display: block;
				height: 100%;
				padding-left: 40rpx;
				border-radius: 43rpx;
				border: 1px solid #DCDCDC;
			}

			.code {
				display: flex;
				align-items: center;
				justify-content: center;
				width: 220rpx;
				height: 86rpx;
				margin-left: 30rpx;
				background: var(--view-minorColorT);
				font-size: 28rpx;
				color: var(--view-theme);
				border-radius: 43rpx;

				&[disabled] {
					background: rgba(0, 0, 0, 0.05);
					color: #999;
				}
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
