const marketplace = require('./marketplace.config');
module.exports = {
  publicPath: '/merchant/',
  outputDir: 'dist',
  assetsDir: 'static',
  productionSourceMap: false,
  chainWebpack(config) {
    config.plugin('html').tap((args) => { args[0].title = marketplace.name + ' · 商家后台'; return args; });
  },
};
