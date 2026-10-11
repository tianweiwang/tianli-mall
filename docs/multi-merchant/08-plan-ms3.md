# MS3：最小商家后台 Implementation Plan

> **For agentic workers (Codex):** 先读 [12-codex-protocol.md](12-codex-protocol.md)。开工前提：MS2 已验收。需要授权级别 L2；安装 npm 依赖需要联网，在开工时说明来源（npm registry）和锁文件。

**Goal:** 商家员工能在独立的商家后台登录，维护商品、规格库存、素材、运费模板、员工角色，并把商品送审；平台管理员能在平台后台管理商家、审核商品；顾客在商城里能看到商品所属的真实店铺。

**Architecture:** 新建 `template/merchant/`，技术栈与 `template/admin/` 一致（Vue 2 + Element UI + vue-cli），独立的 `package.json` 与锁文件、独立的构建输出、独立的 token 存储键与请求客户端，只调用 `/merchantapi/*`。平台后台在 `template/admin/` 中新增"入驻商家"和"商品审核"页面。顾客端在 `template/uni-app/` 的商品详情增加店铺信息，并新增店铺页。所有权限仍由服务端执行，前端隐藏只是体验。

**Tech Stack:** Vue `^2.5.10`、element-ui `2.15.6`、vue-router `^3`、vuex `^3`、axios `^0.18.1`、@vue/cli-service `^3`（与 `template/admin/package.json` 保持一致）；Node 版本与 `help/audit-regressions/frontend.md` 中审计构建所用版本一致；浏览器测试用 Playwright（沿用 `help/audit-regressions/frontend-browser.cjs` 的方式）。

**Spec:** [00-implementation-spec.md](00-implementation-spec.md) §3、§7；用例 F01–F04，回归 MS0a–MS2 全部用例。

## Global Constraints

- `template/merchant/` 不复制平台后台的动态菜单、系统工具、全局 axios 拦截器；只复用经过挑选、没有权限副作用的展示组件（复制到 `template/merchant/src/components/` 并注明来源文件）。
- 构建输出：`template/merchant/dist/`；部署路径 `/merchant/`（`publicPath: '/merchant'`）。是否复制到 `crmeb/public/merchant/` 随 Q2 决定，本里程碑只在隔离环境中部署。
- token 存储键 `tianli_merchant_token`，与平台后台不同；401 时清除 token 并跳转商家登录页；403 显示无权限提示；404 显示"不存在或无权访问"。
- 不升级 Vue 大版本，不在本里程碑改平台后台的构建配置。
- 截图与录屏只用合成数据。

## Review Focus

- **同浏览器双登录**：平台和商家在同一浏览器中同时登录，刷新、退出其中一个不影响另一个；商家页面里请求平台接口被服务端拒绝（F02）。
- **失效后的页面**：员工被停用或权限被撤销后，已打开的编辑页再提交，界面必须显示失败，不能显示"保存成功"（F04）。
- **构建可复现**：从干净检出执行 `npm ci && npm run build` 成功，产物不依赖本机文件（F01）。

---

### Task 1: 商家后台工程骨架

**Files:**
- Create: `template/merchant/package.json`、`package-lock.json`、`vue.config.js`、`.env.production`、`src/main.js`、`src/router/`、`src/store/`、`src/api/request.js`、`src/layout/`、`src/pages/login/`、`src/pages/password-setup/`
- Create: `help/audit-regressions/merchant-frontend.cjs`

**Interfaces:**
- Produces: `src/api/request.js` 导出 `request(method, url, data)`，基础路径 `/merchantapi`，从 `tianli_merchant_token` 读取 token，按 §Global Constraints 处理 401/403/404。

- [ ] **Step 1: 写测试**（BROWSER 层，`merchant-frontend.cjs`）：F01 的构建子场景（干净目录 `npm ci`、`npm run build` 退出码 0，`dist/index.html` 中资源路径以 `/merchant/` 开头）；登录页用员工账号登录成功后进入首页；一次性设置链接页面可以设置密码。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(merchant-ui): standalone merchant console skeleton`。

### Task 2: 商品、规格库存、素材、运费模板页面

**Files:**
- Create: `template/merchant/src/pages/product/`（列表、编辑、规格库存）、`src/pages/attachment/`（素材库与选择器）、`src/pages/freight/`

- [ ] **Step 1: 写测试** F03：员工新建商品 → 编辑规格与库存 → 上传素材并选为主图 → 选择本店运费模板 → 送审；每一步之后用数据库查询核对（商品 `mer_id`、`review_status = 1`、素材 `mer_id`）。截图与数据库结果一并存入证据目录。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(merchant-ui): product, stock, attachment and freight pages`。

### Task 3: 员工与角色页面

**Files:**
- Create: `template/merchant/src/pages/employee/`、`src/pages/role/`

- [ ] **Step 1: 写测试** F04：管理员停用运营员工后，运营员工已打开的商品编辑页点击保存 → 页面显示失败，数据库无变化；刷新后跳转登录页；重新登录被拒绝。管理员撤销某权限后，对应菜单在刷新后消失，直接访问该页面的接口返回 403。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(merchant-ui): employee and role management`。

### Task 4: 平台后台的商家管理与商品审核

**Files:**
- Create: `template/admin/src/pages/marketplace/merchant/`、`template/admin/src/pages/marketplace/productReview/`；对应 `src/api/marketplace.js` 与路由

- [ ] **Step 1: 写测试**：平台管理员建立商家、审核通过、创建商家管理员并复制一次性设置链接；审核商品通过与驳回；受限管理员看不到入口，直接请求接口被拒绝。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(admin-ui): marketplace merchants and product review`。

### Task 5: 顾客端店铺展示

**Files:**
- Modify: `template/uni-app/pages/goods_details/`（以实际目录为准）增加店铺信息块
- Create: `template/uni-app/pages/store/index.vue`（店铺页：店铺信息 + 该店已发布商品）

- [ ] **Step 1: 写测试**（H5 构建产物 + 浏览器）：A、B 商家的商品详情显示各自店铺名；点击进入店铺页只列出该店已发布商品；网络响应中不含 `cost` 等后台字段。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(uni-app): show merchant store on product detail and store page`。

### Task 6: 同浏览器双登录与交付

- [ ] **Step 1: 写测试** F02：同一浏览器上下文中先后登录平台后台和商家后台；分别刷新、退出；在商家页面中用开发者手段请求 `/adminapi/` 接口 → 服务端拒绝；商家构建产物中没有平台系统菜单的路由。
- [ ] **Step 2:** 运行 MS0a–MS2 的全部后端用例与本里程碑前端用例，全部通过。
- [ ] **Step 3:** 写 `records/ms3/report.md`、`results.jsonl`（附截图清单）；提交；停止，等待验收（11 文档 §5.6）。
