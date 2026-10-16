# 顾客H5跨进程环境配置的键顺序修复

2026-10-16，实施前依据。R4来源 `40f8b07c1cbdc6f3f92ba5b2513274a076bad5a5` 的Run `2026101613490780a575` 已部分正常关闭并经Root独立核零；原失败不重开。目标仍为完整正常顾客H5、原32表与浏览器验收，不缩小原清单。

## 已核原因与范围

原copy、DML prepare、files登记均实际正常0；files原CID在h5_files_before自然exit1，代码为M4C_H5_BOOTSTRAP_ORIGINAL_NATIVE_DML_ZERO_AND_PUBLIC_BINDING，尚未进入其migrator。Root只读两份公开来源核实：实际五个环境键、字符串值和类型完全相同，仅原生环境投影进入JSON时的键顺序不同，PHP数组严格等号将其判为不同。

Root及独立源审确认三处跨producer受影响：R4外部bootstrap的dmlEnvironment/source与sealed baseline/source两处；Main lib/ms4-customer-h5-normal.php的m4ch_stage_sealed通用身份循环将environment与其他标量一起严格比较。同一prepare派生的baseline/oracle完整身份比较保持，不能借此把所有数组比较改宽松。Main入口已有m4ch_http_same_environment：分别ksort后使用严格等号，完整键集合、值与类型仍须相同。

## 实施决定与验收

产品业务代码不改。Main仅修改 `help/audit-regressions/isolated/lib/ms4-customer-h5-normal.php` 的上述跨producer环境字段，其他身份逐项比较及同producer完整比较保持。外部新candidate-h5-r5由完整R4的27文件create-only派生，bootstrap仅两处改用既有helper；两项来源说明随之更新，其余24文件与原7、host/close/zero/browser及所有manifest保持。新来源没有复用旧Run或修改旧失败文件。

Root核最小diff与整文件逆回、JSON与源码字节、两处实际PHP语法；旧/新profile的完整来源不变。R5源检查通过后，以新clean HEAD/freeze和全新隔离实例运行原完整流程。正常运行、真实HTTP/DOM、27原JPEG、B0–B4/Final32表、正常关闭与独立核零均须另取证；已停止样本仍NOT RUN。只修对象键顺序，不减少任何源hash、原生CID/挂载/环境值、金额库存、归属、页面或SQL断言。唯一清单仍在09的EK条目。

## 实际源码检查点

Root实施Main一处条件比较，91453B/摘要 `bb38cc6b9808ddcc1ec95aab79928e09272c81b90f9d4455ba88e645fea22b9d`；保留原文件EOF字节，整文件逆回严格等于原40f8源。独立只读复核Main、既有helper与R5组装程序，确认仅跨producer环境按键比较、同producer完整比较不改；Root随后唯一执行8770B组装源 `45e0c6333312d1cceb6af835cdaa56626eb9bb45f72e800921aa35458d5ac17d`，native0。

实际R5仍27文件，仅bootstrap两替换及两项来源说明变化，其他24与原7保持；新bootstrap73805B/摘要 `81376b4122eedcfa40de298f6035192c5bc4c8634ce92a3c0974e1ccb3a79485`，整文件逆回原R4。Root核实际生成diff、完整旧/新来源及Main前后字节；固定PHP8.2.33/network none/只读两挂载/原tmpfs/cap-drop/no-new-privileges，仅两php-l子进程均native0/stderr空。11890B收据摘要 `38160f296e56ff666283ff983ee3b296f7dc2b5ea69560e35ad3b74a7471c6d6`。这里只证明SOURCE/PARSE，尚无R5 freeze/Run/HTTP/DOM，EK3和完整验收保持未完成。
