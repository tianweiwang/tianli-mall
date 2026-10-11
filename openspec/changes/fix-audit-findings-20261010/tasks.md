# Execution checklist

- [x] 记录范围、验收条件、实现设计与隔离验证边界；确认未启用 Git auto-sync。
- [x] A1/A2：权限及扫码上传 red regression → 修复 → green regression。
- [x] A3：锁内余额支付、重复支付防护及真实 MySQL 并发/失败回滚验证。
- [x] A3 补充：已定位的退款、充值、佣金转入、后台调额、注册奖励余额写入使用共同账户锁，验证并发余额和流水。
- [x] A3 收尾：移动端商家调额、抽奖消耗和现金领奖的余额与流水并发回归。
- [x] A4：精确恢复导出源码，校验忽略规则及摘要，支持版本 Node 后台构建。
- [x] A5：试算只读、事务内原子核券、失败回滚与并发核券验证。
- [x] A6：核实表契约，迁移 red/green、兼容性/幂等验证，快照后本地新增空表并验证旧表未变。
- [x] A7：MP/App 显式 origin 配置与缺配置阻断；H5 同域回归；记录本地配置和完整平台构建/真机未验状态。
- [x] A8：402 Promise 结束回归及浏览器维护提示/loading 验证。
- [x] A9：v1 DIY 修复与 v1/v2 只读 HTTP 对照。
- [x] 汇总运行验证证据、检查改动范围和剩余外部验收条件。

## 后续平台验证（安装 HBuilderX 后继续）

- [x] B1：修复已出现的 Sass 配置/列表 key 警告，完成 H5 生产模式本地构建。
- [x] B2：微信小程序开发编译，核实生成代码使用本地接口及配置校验兼容目标运行时；检查 App 本地编译能力。
- [x] B3：实际新 H5 编译产物的浏览器运行与 402/loading 回归，核实后台新构建预览。
- [x] B4：记录可运行产物、验证证据及正式域名/开发者身份/真机等外部验收条件。
- [x] B5：将验证通过的 H5/后台产物更新到本机 8011 预览，覆盖前逐文件备份；核验上传文件与后端入口不变，并验证 HTTP 实际提供新包。

继续范围：只在本机编译和预览，保留现有业务数据和上传文件，不上传、云打包或远程部署。最终包独立验证通过后，更新本地预览所需文件并保留逐文件备份，使修复可在本机直接查看。对编译器/目标运行时暴露的问题做最小修复，新增行为先补设计与回归。

## Evidence

实施时补充命令、结果与必要限制；不保存凭证或业务数据。

- A1/A2：PHP 8.2 `help/audit-regressions/security.php` 修前 7 pass / 16 fail，修后 23 pass / 0 fail。真实权限中间件/上传控制器与隔离依赖，拒绝时 next/upload 计数为 0；语法检查通过。
- A9：`python3 help/audit-regressions/diy-http.py` 修前 v1 business 400（不存在 getDiyInfo），修后默认、0、1、不存在 ID 四组均 business 200，数据与 v2 相同；控制器 PHP 语法通过。
- A3/A5：`help/audit-regressions/commerce.php` 初始 30/30，抽奖收尾后最终 38/38 通过。修前复现 100 元两笔 80 元均支付、充值覆盖扣款、券提前核销等；修后余额/流水连续、重复请求仅成功一次、异常事务回滚、券竞争仅一单成功。抽奖与支付并发、现金奖重复领取、奖品记录回滚及父事务嵌套也通过。支付渠道和通知使用替身，未实际外联付款/退款。
- A3 后台调额：`wallet-admin.php` 修前 10/10 失败，修后 10/10 通过；账户辅助加减、后台加减、注册奖励、支付并发及数据库故障回滚。事件替身，不声称跨系统通知随 DB 回滚。
- A4：三文件与备份前 Git blob/SHA256 一致（详见 frontend.md）。隔离 Node 22.23.3，锁文件 npm ci 后 admin build exit 0；11 个现有 CSS 顺序/资源体积警告，lock 未修改。新 dist 浏览器预览正常进入登录页，无脚本异常；初轮未覆盖 public/admin，后续本地更新见 B5。
- A6：历史安装 SQL 缺定义，按实际服务契约补最小结构。隔离 41/41 通过。本地 apply 新增空 eb_theme_module；原 157 张表 SHOW CREATE SHA256 和 CHECKSUM TABLE EXTENDED 均未改变。快照 `/Volumes/Kingston/cdx/runtime/crmeb/schema-audits/20261010-theme-module-before-e498e105.json`，对应 after 文件记录校验。默认 check 为 compatible；本地真实 ThemeModuleServices 返回空列表、count=0。
- A7/A8：frontend-regression.cjs 通过本地开发配置、生产缺配置/HTTP 拒绝、H5 同域与 200/401/400/402 请求回归。真实 H5 uni UI + request.js + 订单确认 getConfirm，390×844 网络 mock：维护弹窗保留、loading 消失、弹窗可关闭，无新增脚本错误。初轮当时微信开发者工具已安装但 HBuilder/DCloud 编译器缺失，因此完整移动端打包及真机未验，H5 包未替换；后续进展见 B1–B5。
- A3 移动商家调额：wallet-store.php 修前 2 pass / 10 fail，修后 12/12 通过；真实账户、流水和充值表在随机 audit_store_* 库测试，保持原截零和零额行为。
- 最终合并回归：security 23、commerce 38、wallet-admin 10、wallet-store 12、theme-module 41，共 124 项通过；21 个本次作用域 PHP 文件语法通过，作用域 CRLF-aware diff 空白检查通过；Node 前端回归通过，DIY 四组及基础配置/主题/商品/分类/登录信息等 8 个只读接口 business 200。所有随机审计数据库已清理（remaining_fixture_databases=0）。脱敏日志目录 `/Volumes/Kingston/cdx/runtime/crmeb/audit-20261010/`。

## 后续编译证据

- B1：Sass 声明与 ARM 实际编译器统一为 dart-sass，两个业务列表使用接口真实 id key；最终 H5 production 本地编译 exit 0，入口 `index.5ef79b9d.js`，原 Sass/key 警告消失。输出、日志在 `/Volumes/Kingston/cdx/runtime/crmeb/audit-20261010/`；原资源体积提示保留。
- B2：首次真实 MP 编译因 HBuilderX 不加载项目 .env 而失败，补项目级配置加载后通过；带真实编译器依赖的环境文件/优先级/生产隔离回归通过。小程序 common/vendor.js 和 App app-service.js 均注入显式 `http://127.0.0.1:8011`。骨架屏表达式 key 改为预先计算的数据字段，最终 WXML 两个 wx:key=skeletonKey 均存在；MP 最终编译成功。App 官方 Vue CLI 开发资源构建 exit 0（不是 APK/IPA）；生产 MP 缺域名按预期 exit 1，未构建可误用的生产包。
- B3：最终 H5 生产包独立浏览器四项通过：实际订单确认页 402 loading 收尾/提示关闭、401 清 token 跳登录、390×844 首页和 1440×900 H5 首页；没有注入产品源码、真实业务写入或运行时异常。截图已逐张检查；外部字体/CDN被阻断，部分图标完整显示未验，宽屏 H5 不代表独立 PC 商城适配验收。
- B5：本地静态预览更新 351 文件，15 个覆盖文件已备份、336 个新增；174 上传文件、uploads 符号链接和2个PHP文件摘要前后相同。手机入口、/index.html 与 /admin/ 的 HTTP 响应与新构建 SHA256 一致。备份清单/回滚脚本在 audit-20261010/local-preview-before-094303；脚本检查语法但未执行回滚。
- B5 实际服务验收：H5 四项浏览器检查再次通过，分别校验 14/17/15/14 个真实 HTTP 资源 SHA256；桌面根路径既有 375px iframe 首页可见、居中；后台登录页显示正常并校验 10 个 HTTP 资源与新 dist 一致。均无脚本异常/未知请求，截图已检查。验证码/自定义脚本使用 mock，未登录实际账号；证据汇总 `audit-20261010/platform-summary.json`。
- 本轮未修改后端；先前 124 项后端回归证据继续有效，未为前端配置/编译变化重跑业务数据库测试。

## Remaining platform acceptance (not claimed complete)

- HBuilderX 5.26 ARM64 已安装；H5 生产模式、小程序开发模式、App 开发资源编译均已完成。移动端生产包、微信开发者工具运行/真机及真实登录支付仍未验。小程序/App 生产域名尚未提供；HBuilderX 的 iOS 设备探测工具为 x86_64，本机缺 Rosetta（EBADARCH -86），独立官方编译器已完成 App 资源编译；未改插件或额外安装 Rosetta。
- 本地 8011 的 H5/后台包已从通过独立验证的产物更新，旧文件有逐文件备份；没有远程部署。正式域名与真实支付/通知渠道验收留待对应环境提供。
- 工作区开始前的 help/docker/README.md、help/docker/run.sh 和恢复的 vendor/runtime 保留，public 仅按 B5 清单更新并备份；未提交、推送或启用 Git auto-sync。全仓 diff check 中 run.sh 的既有尾空白不属于本次改动，作用域检查通过。
