# 执行与验收记录

每个里程碑一个子目录：`base/`、`ms0a/`、`ms0b/`、`ms1/` … `ms6/`。目录在执行时创建，本文件只规定格式。

| 文件 | 作者 | 内容 |
|---|---|---|
| `report.md` | 执行方 | 交付报告，覆盖 [11-acceptance-process.md](../11-acceptance-process.md) §2 的 P1–P9 |
| `results.jsonl` | 执行方 | 每个子场景一行，格式见 [03-test-environment.md](../03-test-environment.md) §5.2 |
| `acceptance.md` | 验收人 | 验收记录，模板见 11 文档 §8 |
| 其他 `.md` | 执行方 | 计划中要求的盘点、矩阵、清单（例如 `route-permission-matrix.md`） |

专项记录：[Task6 legacy producer 挂载合同诊断与最小修订](ms4/fulfillment-legacy-producer-repair.md)（当前候选验证与未完成边界）。

专项记录：[商品媒体本地联调](ms4/product-typed-media-integration.md)（源码接入与局部检查，完整业务待验）。

## `report.md` 模板

```markdown
# <里程碑> 交付报告

## P1 基本信息
- 分支：        起点 SHA：        交付 SHA：        是否推送：

## P4 变更文件
| 路径 | 对应任务 | 计划内 |
|---|---|---|

## P5 实际运行的命令
| 命令 | 退出码 | 说明 |
|---|---|---|

## 结果统计
| 层级 | PASS | FAIL | NOT RUN | BLOCKED |
|---|---|---|---|---|

## 关键用例
| 用例 | 子场景 | baseline 实际 | after 实际 | finding_kind | 证据 |
|---|---|---|---|---|---|

## P6 证据位置
## P7 迁移记录（如有）
## P8 未运行项
| 用例 / 子场景 | 原因 | 所缺条件 |
|---|---|---|

## P9 遗留风险与下一停止点
```

记录中不得出现令牌、密码、密钥、连接串和个人信息。
