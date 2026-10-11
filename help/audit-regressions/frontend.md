# 前端审查修复与验证

## 小程序 / App 接口配置

`template/uni-app/.env.development` 已将开发模式配置为 `http://127.0.0.1:8011`，供本机微信开发者工具连接本地商城。H5 始终使用浏览器当前域名，不受该变量影响。Android 模拟器或真机不能把自身的回环地址当作 Mac，需要单独配置可达的 Mac 地址。

真机的 `127.0.0.1` 指向手机自身。需要真机测试时，在不提交的 `template/uni-app/.env.development.local` 中设置 `VUE_APP_API_URL=http://<Mac局域网IP>:8011`，并确认本地服务可从局域网访问。本次不更改服务监听或防火墙。

发布前通过环境变量或 `template/uni-app/.env.production.local` 显式设置 `VUE_APP_API_URL=https://<自己的服务域名>`。这里只填写 origin，不加 `/api`、其他路径、账号、查询或片段。生产构建拒绝缺失地址与 HTTP；开发 HTTP 仅允许 localhost、回环或私有 IPv4。不能再回退到官方演示站。上述 `.local` 文件由 Git 忽略。

`vue.config.js` 在非 H5 构建阶段检查该配置，`config/app.js` 使用同一个校验函数作为运行边界兜底。原来的官方演示地址已移除。

HBuilderX 的 Vue CLI 从编译器目录读取环境文件，因此本项目在构建校验前通过 `config/load-api-env.js` 补读项目目录中的接口地址。优先级为显式环境变量（含空值）→ `.env.<mode>.local` → `.env.<mode>` → `.env.local` → `.env`，只导入 `VUE_APP_API_URL`，复用编译器已有的 dotenv 解析器。生产不会读取 `.env.development`。命令行与 HBuilderX 按钮运行均经过此流程。

## 后台源码与构建

`template/admin/src/vendor` 中的三个文件是项目源码，不能通过 npm 安装重新生成。恢复来源为提交 `3e2a1a158149dbf35b4438f5d317b6ec2316e166`，恢复后逐字节及 Git blob 检查一致：

| 文件 | Git blob | SHA-256 |
| --- | --- | --- |
| Blob.js | 35a9ebfb29a61c8a473f7f8171c41a0d5ff20944 | ad76daada564fb6fbe1df3105249f32983dd245585ecc8e6418efb4b861c7bb7 |
| Export2Excel.js | 27c3ecc043cbaf67a3e377a3483b70f5ec785e39 | 0b58fb0fe0e3c97e0f79ca8c63256d1de53b32decfc975a55b44604e682771c4 |
| newToExcel.js | 2cad6d57a254dbdc858d34b8b6ffdde0b887efb0 | 99e3e0ae6ed25b9c4524e8d65b8c18e024fd17d905a2f5069f0678a2056766dc |

`npm run build`、`npm run dev`、`npm run serve` 都会先运行完整性检查；备份时也应运行 `node help/audit-regressions/frontend-source-check.cjs`。备份脚本不可一概排除所有名为 vendor 的目录。

使用 Node 14–22（本次为隔离的 Node 22.23.3）在 `template/admin` 执行 `npm ci --ignore-scripts --no-audit --no-fund` 和 `npm run build`。未升级锁定依赖；初轮仅验证 dist，后续本地更新见文末。

## 回归命令与边界

在仓库根执行：

```sh
node help/audit-regressions/frontend-regression.cjs
PLAYWRIGHT_MODULE=/path/to/playwright CHROME_EXECUTABLE=/path/to/chrome node help/audit-regressions/frontend-browser.cjs
```

第二条需要本地 H5 已启动，默认连接 `http://127.0.0.1:8011`，可用 `FRONTEND_BASE_URL` 覆盖，并可通过 `FRONTEND_SCREENSHOT` 保存截图。

维护响应的测试读取真实 `utils/request.js` 和真实订单确认页 `getConfirm` 方法，使用内存网络响应，不发送订单请求、不切换实际维护开关。浏览器测试在手机尺寸的实际 H5 页面上使用真实 uni 提示组件：修前维护弹窗出现但 loading 不消失；修后 loading 清理、弹窗可以确认关闭，200 / 401 / 400 的请求行为保留。

这些检查验证源码和本地 H5 行为，不等于完成小程序 / App 的 HBuilder 打包、微信开发者工具或真机验收。现有 H5 发布包没有被本次测试覆盖更新。

前轮修复验收时，本机只有微信开发者工具，缺少 HBuilderX 和 DCloud 编译器，因此当时未做整包编译。

## 2026-10-10 后续安装验证

用户随后授权安装 HBuilderX。已从 DCloud 官方安装 HBuilderX `5.26.2026091802` ARM64 到 `/Applications/HBuilderX.app`，安装包完整性、开发者签名及苹果公证检查通过。通过内置插件管理器安装 `uniapp-cli`、`compile-dart-sass`、`compile-stylus`，并成功导入当前项目。

```sh
/Applications/HBuilderX.app/Contents/MacOS/cli launch web --project "$PWD/template/uni-app" --compile true
```

上述 H5 开发模式本地编译已成功，编译后自动停止运行。输出包含原项目 `sassImplementationName=node-sass` 在 ARM 上仅支持 Dart Sass 的提示，以及 `expList`/`goodList` 循环缺少 key 的两个警告，均未阻止编译；未为此修改项目源码。

安装完成当时，缺少 HBuilderX 编译链这一前提已解除，但生产 H5、小程序/App 编译和真机验收尚未执行；后续进展见下一节。没有上传或替换现有发布包。安装记录位于 `/Volumes/Kingston/cdx/runtime/installers/hbuilderx/install-verification.json`。

## 2026-10-10 继续修复与完整编译

实际小程序首次编译暴露环境文件未加载的问题，现已修复；原有生产 HTTPS/缺配置阻断保持。两个业务列表增加接口返回的稳定 `id` key；骨架屏将原来的类型+索引 key 预先写到数据字段，避免微信编译器丢弃表达式 key；Sass 声明改为 ARM 实际使用的 `dart-sass`。不改变样式或业务规则。

- 微信开发编译成功：`cli launch mp-weixin --project "$PWD/template/uni-app" --compile true`。产物 `template/uni-app/unpackage/dist/dev/mp-weixin` 的 `common/vendor.js` 确认使用 `http://127.0.0.1:8011`，骨架屏 WXML 中两个 `wx:key="skeletonKey"` 均存在。原列表/Sass/key 警告消失。
- H5 生产模式本地编译成功，先独立输出 `/Volumes/Kingston/cdx/runtime/crmeb/audit-20261010/h5-production` 并验证，之后才进行文末的本地更新。保留现有资源体积告警。
- App 开发资源编译成功，输出同证据目录下 `app-development`，其中 `app-service.js` 确认本地接口注入。此产物是运行资源，不是 APK/IPA 安装包。
- 真实编译器在生产小程序缺少正式域名时返回 exit 1，记录 `mp-production-guard.log`；没有为完成打包而填写占位生产地址。

HBuilderX 的 `cli publish web` 要求登录；本次使用已安装的官方 Vue2 本地编译器 `uni-build` 完成生产构建，不涉及云发布或修改登录检查。可从项目根目录复现：

```sh
HX_COMPILER=/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli
HX_PROJECT="$PWD/template/uni-app"
HX_OUTPUT=/Volumes/Kingston/cdx/runtime/crmeb/audit-20261010/h5-production
(
  cd "$HX_COMPILER" || exit 1
  env NODE_ENV=production UNI_PLATFORM=h5 UNI_MINIMIZE=true \
    UNI_INPUT_DIR="$HX_PROJECT" UNI_OUTPUT_DIR="$HX_OUTPUT" \
    VUE_CLI_CONTEXT="$HX_COMPILER" UNI_HBUILDERX_PLUGINS="$HX_COMPILER/.." \
    "$HX_COMPILER/../node/node" bin/uniapp-cli.js
)
```

App 的 HBuilderX `launch app-android --compile true` 自动安装官方 launcher 后，卡在设备扫描：附带的 iOS `mdb_MobileList` 为 x86_64，本机未安装 Rosetta，出现 `EBADARCH (-86)`；Android adb 本身支持 ARM。已停止本次 CLI 等待，改由相同官方 Vue CLI Service 以 `NODE_ENV=development`、`UNI_PLATFORM=app-plus`、`uni-build`、`watch:false` 直接编译资源成功。未修改插件或额外安装 Rosetta；真机工具问题保留为设备调试前提。

新增 HBuilderX 环境加载与真实 H5 产物回归：

```sh
UNI_CLI_CONTEXT=/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli \
  node help/audit-regressions/frontend-regression.cjs
H5_BUILD_DIR=/Volumes/Kingston/cdx/runtime/crmeb/audit-20261010/h5-production \
  PLAYWRIGHT_MODULE=/path/to/playwright CHROME_EXECUTABLE=/path/to/chrome \
  node help/audit-regressions/frontend-built-browser.cjs
```

`frontend-built-browser.cjs` 直接加载新编译的页面和脚本，进入真实订单确认路由，不注入 `request.js` 或消费者源码。用隔离浏览器的虚构登录状态和内存网络响应验证 402 loading 收尾/弹窗关闭、401 清除登录并跳转；受保护请求和外部网络均拦截，只允许明确的本地公开展示 GET。现有 `frontend-browser.cjs` 保留为源码级回归，不能与整包验收混同。

生产域名、对应微信 AppID 权限、真实登录/支付、APK/IPA 与真机验收仍待实际环境提供；本轮不上传、不远程部署。原有子包归属建议、资源体积提示及 Harmony 适配提示不阻断当前平台编译；Harmony 支持未在本轮验证。

## 本地预览更新

独立产物浏览器检查完成后，已将最终 H5 和后台 dist 更新到本机 `8011` 预览。只更新构建产物列出的文件，没有删除旧资源。共 351 个文件发生变化，其中 15 个覆盖前逐文件备份、336 个新增；174 个上传文件、uploads 符号链接及 2 个 PHP 文件摘要在更新前后完全一致。没有改数据库或服务监听。

HTTP 核验：手机 UA 的 `/`、直接 `/index.html`、`/admin/` 返回内容均与相应新构建 SHA256 完全一致。普通桌面 UA 的 `/` 仍由既有服务提供包装页面，其 iframe 指向 `/pages/index/index` 并以 375px 宽居中。商城常规入口为 `http://127.0.0.1:8011/`，H5 页面入口为 `http://127.0.0.1:8011/pages/index/index`，后台为 `http://127.0.0.1:8011/admin/`。`/index.html` 只用于产物字节核验，不能作为 history 路由的首页入口。

备份清单及回滚脚本：`/Volumes/Kingston/cdx/runtime/crmeb/audit-20261010/local-preview-before-094303/manifest.json`、同目录 `rollback.py`。回滚前逐项确认当前文件仍等于本次更新结果，若发现后来改动则拒绝覆盖；脚本已检查语法，未执行回滚。

`frontend-built-browser.cjs` 设置 `FRONTEND_USE_SERVED_ASSETS=1` 时，浏览器从真实本地 HTTP 服务读取资源，并将每个响应体 SHA256 与指定构建比对；设置 `FRONTEND_CHECK_HOME=1` 时另测手机/宽屏 H5 首页。网络 mock 和外部请求阻断仍生效；外部字体被阻断导致部分图标未完整验收，不应误报为产品样式缺陷。

实际服务验收全部通过：402、401、手机首页及宽屏 H5 首页分别校验 14/17/15/14 个 HTTP 资源；桌面根路径的 375px iframe 首页可见、居中；后台登录页正常，10 个 HTTP 资源与新 dist 一致。所有截图已检查、没有脚本异常或未知请求。后台验证码和自定义脚本被测试替换，不声称验证了真实验证码或账号登录。日志为同证据目录下 `h5-served-browser-test.log`、`admin-served-browser-test.log`、`h5-served-desktop-wrapper.json`，总览为 `platform-summary.json`。
