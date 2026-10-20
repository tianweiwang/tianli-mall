# 多商家改造（路线 A）开发文档

本目录是"现有 CRMEB 改造为多商家商城 v0.1"的唯一执行依据，替代 `~/Downloads` 下 v0.1–v0.3 三套草稿。Codex 和其他执行方只按本目录工作；草稿与本目录冲突时以本目录为准。

> 状态声明（只写一次）：本目录是设计、计划与验收标准，不是测试报告。所有验收用例的初始状态都是 NOT RUN；实际结果只记录在 `records/` 下，并以提交 SHA 为准。

## 1. 阅读顺序

| 顺序 | 文件 | 用途 | 谁必须读 |
|---|---|---|---|
| 1 | [12-codex-protocol.md](12-codex-protocol.md) | 执行规约：授权级别、停止点、禁止事项、回传模板 | 所有执行方，每次开工前 |
| 2 | [00-implementation-spec.md](00-implementation-spec.md) | 实施规格：范围、决策、命名、身份、归属、交易、支付、迁移 | 所有执行方 |
| 3 | [01-code-facts.md](01-code-facts.md) | 已核实的代码事实与安全发现（带文件位置） | 改代码前 |
| 4 | [03-test-environment.md](03-test-environment.md) | 隔离测试环境、测试分层、证据格式 | 跑任何测试前 |
| 5 | 当前里程碑的计划文件（见下表） | 具体任务、接口、测试与提交 | 执行该里程碑时 |
| 6 | [10-acceptance-matrix.md](10-acceptance-matrix.md) | 全部验收用例 | 写测试、出报告时 |
| 7 | [11-acceptance-process.md](11-acceptance-process.md) | 每个模块完成后怎么验收、谁签收、什么算通过 | 交付和验收时 |

## 2. 里程碑与当前状态

正式验收仍由用户或其指定验收人按 [11 文档](11-acceptance-process.md)作出。以下人工验收列只登记已有决定；实施与普通验证另列，不能据此代签其余里程碑。原“未开始”是初始值，不能继续代表已开展的开发。

| 里程碑 | 计划文件 | 内容 | 前置条件 | 人工验收记录 |
|---|---|---|---|---|
| BASE | [02-plan-base-rebuild.md](02-plan-base-rebuild.md) | 以 master 为基线重建，迁入备份快照中的必要修改和审计修复，得到 `AUDIT_BASE_SHA` | 无 | 用户已确认通过，见 [既有决定补记](records/base/acceptance.md) |
| MS0a | [04-plan-ms0a.md](04-plan-ms0a.md) | 最小安全修复：跨应用令牌混用、认证失败写入、账号状态、权限放行、认证配置 | BASE 通过 | 未登记用户签收 |
| MS0b | [05-plan-ms0b.md](05-plan-ms0b.md) | 会话 v2：版本化会话、撤销、单次兑换刷新 | MS0a 通过 | 未登记用户签收 |
| MS1 | [06-plan-ms1.md](06-plan-ms1.md) | 商家、员工、角色、merchantapi、MerchantContext | MS0b 通过（merchantapi 直接使用 v2 会话） | 未登记用户签收 |
| MS2 | [07-plan-ms2.md](07-plan-ms2.md) | 商品、规格、素材、运费模板、分类绑定隔离与 mer_id 迁移 | MS1 通过 | 未登记用户签收 |
| MS3 | [08-plan-ms3.md](08-plan-ms3.md) | 独立商家后台 `template/merchant`、平台审核页、顾客端店铺展示 | MS2 通过 | 未登记用户签收 |
| MS4 | [09-plan-ms4-ms6.md](09-plan-ms4-ms6.md) §MS4 | 单店购物车、订单、库存、发货、售后、评价 | MS3 通过 | 未登记用户签收 |
| MS5 | [09-plan-ms4-ms6.md](09-plan-ms4-ms6.md) §MS5 | 支付、退款、对账 | MS4 通过，且开放问题 Q4/Q5 已决定 | 未登记用户签收 |
| MS6 | [09-plan-ms4-ms6.md](09-plan-ms4-ms6.md) §MS6 | 发布、恢复、限流、防回滚 | MS5 通过 | 未登记用户签收 |

### 实施与普通验证概览（不替代验收）

目标是通用多商户商城，复用现有商品规格与价格体系。当前后续开发仍为 L2 本地实现和隔离验证，未推送、未部署；BASE 历史单独的 L3 授权保留在原报告，不扩展为后续授权。

| 阶段 | 已有实施或证据 | 尚未闭合的边界 |
|---|---|---|
| BASE | 重建与交付报告已形成；用户原文确认“确认 BASE 通过，授权 MS0a 按 L2 开发” | 仅登记该既有决定，不追加测试或扩大授权；原 SHA、历史结果和证据不改 |
| MS0a | 认证、账号状态与权限收紧已有实现；[报告](records/ms0a/report.md)记录主、独立 HTTP 各 106/106 | 普通/隔离结果不代表生产验证，V10 用户签收未登记 |
| MS0b | 会话 v2 及账号变更、清理、登录下载已开发；[最终普通冻结记录](records/ms0b/report.md)为 30 PASS / 1 NOT RUN | 完整安全验收、真实集成与人工签收未取得；停止项不恢复 |
| MS1 | 商家、员工/角色、上下文及管理接线已有实现；[普通报告](records/ms1/report.md)含 API、Worker 局部证据及聊天界面正常流程 | 原组合失败保留；整套回归、全部界面功能、真机与人工验收未完成 |
| MS2 | 结构/归属命令及商品、库存等已有实现与[普通记录](records/ms2/four-command-normal-summary.json) | 各局部通过不能汇总为完整 MS2；历史归属映射和完整迁移/商品链验收未闭合 |
| MS3 | 已有商家、平台页面源码及[构建检查记录](records/ms3/frontend-source-checkpoint.json) | 页面源码或构建不等于真实业务 UI；顾客店铺展示与完整阶段验收仍未闭合 |
| MS4 | 交易、履约、售后、评价已有实现；R24内部paid0、初始三读1stage/54calls（39owned＋15wrapper）、legacy0 | 首个发货动作及A外层均自然1；安全侧证定位native_readback/Service1439复合门，运行子谓词仍UNKNOWN。operation比较修复已Source独审、PHP两文件语法及37/37纯比较通过；fresh完整链和三端验收仍待，详见[当前计划](09-plan-ms4-ms6.md)及[集成记录](records/ms4/normal-early-structure-integration.md) |
| MS5 | 已有支付核心、测试支付消费者及三端订单查询验证相关实现/源码，见 [支付记录](records/ms5/payment-core-integration.md) | 真实账户、回调、退款、账单/费用及完整业务交付与验证尚未完成；测试驱动不代替真实渠道，外部条件与 L4 实测另列 |
| MS6 | 原计划和发布要求保留 | 发布、恢复、限流、防回滚与全量回归尚未完成，未获真实发布验收 |

最新进展（R24，2026-10-20）：原A10845与Task6失败不改署，售后/评价/Worker未启动，Service1439复合门的运行子谓词仍UNKNOWN。本轮正常close实际0，独立zero15项原生0、六fresh原始查询双空及106906原公件保全已由Root核实；候选两文件PHP语法与37/37纯typed比较也已通过，均不代表真实发货或A业务通过。完整fresh正常链、H5/商家/三端普通要求与人工验收仍待。**整体当前没有可信完成百分比**，继续分开报告实施、实际验证、未完成与待签收。

用户已停止的安全复现、负向、故障、并发/断连及恢复样本继续 **NOT RUN**，不得换名重跑或记为 PASS。本概览不改变原规格、覆盖要求或正式验收流程；后续只据实际证据更新，不以行数、构建成功或准备完成换算项目百分比。

## 3. 已定决策

| 编号 | 决策 | 来源 |
|---|---|---|
| D1 | 路线 A：在 `tianweiwang/tianli-mall` 现有 CRMEB 上改造；目标产品版本为多商家 v0.1；不做预约、检测、区域代理、定制会员、补贴，不合并 H5 Demo | 用户，2026-10-11 |
| D2 | 基线采用方案 B：从 `master@3e2a1a158149dbf35b4438f5d317b6ec2316e166` 重建，迁入备份快照中经审阅的修改和审计修复；备份分支 `codex/private-backup-20261007-tianli-crmeb` 只读保留，不合并 | 用户，2026-10-11 |
| D3 | MS0 拆为 MS0a（最小安全修复，复用现有令牌格式和会话白名单）和 MS0b（会话 v2）。MS0b 不是 MS0a 合入的前提 | 用户，2026-10-11 |
| D4 | `mer_id` 是唯一的经营商家归属字段；不新增同义的 `merchant_id`；`0` 不代表自营或全局；自营商家是显式创建的正整数 ID | 规格 v0.3 |
| D5 | 新商家体系用 `marketplace` 命名空间、`crmeb/app/merchantapi/` 应用、`template/merchant/` 前端；旧 `adminapi/merchant` 路由仍然表示门店/店员/核销 | 规格 v0.3 |
| D6 | MS0–MS3 的测试扩展 `help/audit-regressions/`，不引入 PHPUnit，不升级依赖树 | 规格 v0.3 |
| D7 | 商品分类为平台全局只读；运费模板、规格、素材归商家；评价的经营归属跟随原订单商品 | 规格 v0.3 |
| D8 | `openspec/changes/add-store-booking` 标记 PAUSED（路线 A 范围外），保留文件和勾选状态 | 规格 v0.3 |
| D9 | 本轮不改变 master 对 `crmeb/vendor`、`crmeb/public` 的跟踪方式；是否停止跟踪构建产物另行决定（Q2） | 本目录，默认值 |
| D10 | `crmeb/.env` 停止跟踪（master 中是空文件且已在 `.gitignore`）；备份快照中对上游演示凭据的清理（地图 key、短信 token、微信 appsecret、示例 token）迁入新基线 | 本目录，默认值 |

## 4. 开放问题（未决定前，相关里程碑不得开工或不得发布）

| 编号 | 问题 | 阻塞 | 默认处理 |
|---|---|---|---|
| Q1 | 审计证据文档（`openspec/changes/fix-audit-findings-20261010/tasks.md`、`help/audit-regressions/frontend.md`）中的本机绝对路径是否脱敏 | 不阻塞 | 私有仓库，保留原文 |
| Q2 | 是否停止跟踪 `crmeb/vendor`、`crmeb/public` 构建产物 | 不阻塞 MS0–MS2；MS6 发布前必须决定 | 保持 master 现状 |
| Q3 | master 历史中出现过的演示凭据（见 01 文档 F-20）是否属于本项目自有、是否需要轮换 | 阻塞 MS6 发布 | 视为上游公开演示值，发布前由用户确认 |
| Q4 | 收款产品、经营主体、商户账户方案 | 阻塞 MS5 开工 | 无 |
| Q5 | 平台费用的承担方、计算基数、结算时点、退款后费用是否退回 | 阻塞 MS5 结算功能 | 不开放自动结算与提现 |
| Q6 | 后台登录失败后服务端不强制验证码（01 文档 F-17）是否纳入 MS0b | 不阻塞 MS0a | 纳入 MS0b 候选，开工前确认 |
| Q7 | 管理端令牌允许经 URL `?token=` 传递（01 文档 F-18）是否收紧 | 不阻塞 MS0a | 纳入 MS0b 候选，开工前确认 |
| Q8 | 商家员工初始密码的发放方式 | 阻塞 MS1 上线（不阻塞开发） | 平台生成一次性设置链接，不发明文口令 |

## 5. 目录约定

```text
docs/multi-merchant/
  README.md                 本文件：索引、决策、状态
  00-…12-…                  规格、事实、计划、环境、矩阵、验收流程、执行规约
  records/                  每个里程碑的实际执行记录与验收签收（见 records/README.md）
AGENTS.md                   仓库根目录，Codex 自动加载的入口
```

规格类文件（00、01、03、10、11、12）的修改需要用户批准。执行中发现文档与代码不符时，按 12 文档 §5 停止并回报，不要自行改写规格来迁就实现。
