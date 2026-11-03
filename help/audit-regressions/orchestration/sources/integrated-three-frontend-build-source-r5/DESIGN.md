# 三真实前端构建的普通 Root Source 设计

本包仅 Source。观察基线为统一工作树 `7a17c6bee8f5656235b7a68a5b2c1df21b11fa5c` clean；它不是未来最终构建资格。最终 clean HEAD、独立 Val、完整 tracked blob1/SHA/稳定句柄资格、三 build、DIST、最终三 dist manifest 与 public-copy profile 四件均 UNBOUND。没有实际构建、工具版本重跑、Docker、网络或目标操作。

## 已追到的实际链与复用边界

- admin：`currentbfe-platform-site-image-admin-build-actual-r1/root-driver.py`，历史 R99 reader 对完整原生 PID/wait/双流、源码、离线依赖与 dist 做过独立读回。实际 Windows Node22.23.3/npm10.9.9，离线 modules 来自 `artifacts/ms0b-task8/frontend/admin/node_modules`。package/lock 当前 Git 与 cache 逐字相等。
- merchant：`current41c0-normal-root-admission-r1/run-r92-merchant-ui-build-r2.py` → `currentbfe-merchant-video-audio-ui-build-source-r2/root-driver.py`。同一 Node/npm，离线 modules 来自 `artifacts/ms3-merchant-console-build/source477732-generic-r2/clean-ci/node_modules`；当前 package/lock 逐字相等。原公开 `.env.production` 只有相对 `/merchantapi`。
- H5：R97 readback → `currentbfe-mobile-h5-build-source-r1/{launch.py,build-linux.ps1,build-common.ps1,native.py,explicit-pass-preload.cjs}`。真实编译是在固定本地 Linux Node 镜像 `fb4cd12c...`，RO 安装目录 `E:/HBuilderX/plugins/{uniapp-cli,about,compile-dart-sass,uni_helpers}`，RO 外置 compile-stylus、原 11 件 optimize overlay，并保原 adapter 两 pass/一 afterEmit/八编译源码 guard、完整 dist inventory signature。不得换成未知 controller、本机临时 CLI、下载依赖或普通单 pass 成功。

## 当前 Git 代替旧 overlay

旧 merchant5 中 3 件、admin8 中 6 件、admin2 中 uploadPictures 与当前 Git 全字节相同；其余 API、媒体组件有后继提交。旧 overlay 不再覆盖；只用最终同 HEAD/Val 完整资格的 tracked source。新 assembly 不再沿用旧 bfe HEAD、27 dirty、559/1089 等旧计数，也不搬旧结果或把旧 build SHA 改新。

## 有限实现与资格先行

只有三个固定用途 admin/merchant/h5，不提供任意命令或通用构建框架。复用成熟驱动的 whole-file/native9、物理离线 module copy、隔离 npm 环境、原 `npm run build`/prebuild、Popen 同句柄 wait、created/完整两流/terminal、输出资源和完整 tree 保全。新 Python 主入口的固定 `QUALIFIED_INPUTS_PIN=None` 是硬阻断：未由 Root 在新的已审 Source 代际固定最终资格前，任何目录创建、子进程、Node/npm/HBuilderX/Docker 或 Source assembly 都不能开始。

Root 后继资格需固定：Source/Val 不同绝对路径/独立 gitdir、同 clean HEAD/parent、全 tracked tree 的 path/mode/blob1/bytes/SHA 与目录清单及公开资格 authority；完整离线 modules、HBuilderX 四完整输入树与 Stylus tree 的冻结 manifest，原 compiler guards/adapter/overlay；实际新 output parent。driver 按固定资格完整再读双树，保源码原有文件/目录、配置排除和 no-link/reparse，不能运行时读当前 hash 即信任。

Windows 每次新目录导出完整该端 tracked subtree及其真实 prebuild helper；module 逐普通文件复制，禁止链接/hardlink 复用；保持原 `.cache/.tmp/.git` 仅生成缓存排除合同、完整 protected modules 前后 bytes/native9；空 npmrc/home/cache/temp，只 npm offline/no install/no audit/fund/update fallback。Node/npm 指纹引用 Root 已完成 preflight，但新 actual build 仍另留真实版本原生回执。

H5沿原固定编译命令/RO mounts/network none/read-only/tmpfs/显式两 pass。新 runtime input是同一个最终 Git tracked uni-app完整导出，原安装编译目录不写。只新 output/evidence/input 可写；adapter和原 compiler overlay复制到本新 Source 包保持完整 raw。成功要求 attach/同 CID自然 terminal0/PID0/noRestart、全部原编译回调和 afterEmit inventory join、原输入全 bytes 保持，然后同 CID nonforce rm。失败/未知保 Source、全部原生双流及自有 CID，不自动重试、force、KILL或借新结果覆盖旧结果。

## 输出与 public-copy 依赖

将来只有真实构建成功后才写各 Run 内通用 `DIST-TREE.json`，精确 `files` 有序 path/bytes/SHA rows 与包括空目录的 directories；另存 native metadata 与输入 before/after native9。这里不创建 `manifests/ui-h5-dist.json`、`ui-merchant-dist.json`、`ui-admin-dist.json`、`ui-public-copy-profile.json`。Root 之后须从三个实际成功 Run 全体目录/文件/模式/bytes、实际同 HEAD provenance，与原 physical public-copy 叶的固定 schema (`files`,`directories` + profile builds/Source/public/PNG) 构成新完整 qualified17，再重新 Source/PINS/Host/close/zero DAG；三 build 本身不代 A/postA、B20/20/42、merchant34/25/9/23、H5 DOM/设备/真实资金/部署验收。

## 本轮任务与验证

- [x] 真实成熟三 controller/callee、离线依赖结构、当前 Git与旧 overlay/package/lock静态核对。
- [ ] 仅本目录完成有限 Source、固定原件来源/PINS及完整源码独审；作者不执行/编译/import候选。
- [ ] Root 采用新的最终 Source/Val/fulltracked、完整依赖 authority，派生已绑定 Source 后独审/必要纯解析。
- [ ] Root 串行实际三构建、全原生 created/terminal/PID/wait/完整双流与全部输入/输出保全读回；再形成真实四 manifest。当前全 NOT RUN/UNBOUND。

旧 Source、旧 controller、旧依赖和真实 build 原件完全只读；Repo/C-D 不写，不提交推送。停止样本和真实外部边界保持。

## 实际 npm callee 来源补点（Source 实现前记录）

Root preflight 已核 node.exe 和 56B npm-cli.js，后者真实只 require `../lib/cli.js`，再进 validate-engines/cli/entry/npm及内部 modules；不能把56B入口当整个npm代码资格。本新Source增加一个有限 `npm_runtime_authority` Root固定tree描述符，仍UNBOUND；Windows构建在版本/执行前完整比原NodeRoot/npm目录的固定files/directories与native9，构建后完整再核。不安装或改npm。它与已给定Node二进制/入口指纹、原离线业务modules是不同的实际依赖；Root须在后继Source里真实封闭该输入。原成熟Source和现有预检结果保持，不改写为已做全npm-tree资格。
