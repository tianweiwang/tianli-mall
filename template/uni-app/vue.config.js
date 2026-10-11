const validateApiOrigin = require('./config/api-origin');

// H5 保持同域；其他平台在构建阶段检查接口地址。
if (process.env.UNI_PLATFORM && process.env.UNI_PLATFORM !== 'h5') {
	require('./config/load-api-env')(__dirname);
	validateApiOrigin(process.env.VUE_APP_API_URL, process.env.NODE_ENV);
}

module.exports = {
	productionSourceMap: false, // 生产打包时不输出map文件，增加打包速度
	configureWebpack: config => {
		if (process.env.NODE_ENV === 'production') {
			config.optimization.minimizer[0].options.terserOptions.compress.warnings = false
			config.optimization.minimizer[0].options.terserOptions.compress.drop_console = true
			config.optimization.minimizer[0].options.terserOptions.compress.drop_debugger = true
			config.optimization.minimizer[0].options.terserOptions.compress.pure_funcs = ['console.log']
		}
	}
}
