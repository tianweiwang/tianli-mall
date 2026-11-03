# Windows 3.14 读取器有限修复：r2 设计与任务

本节在任何 read() 代码变更前建立。唯一功能变更是 build_common.py::read()；r1 整包保持不可变。r2 保留其余全部原路径原字节，并在 source-proof-r2 保留将替换的 build_common、PINS、SOURCE-REPORT 完整原件和 r1 全26文件/8目录清单。原设计、来源、README、QUALIFICATION 与十五个 H5 叶维持 raw 原件。

## 已采用问题与边界

Root 在实际 Windows/Python3.14 合法普通文件观察到 lstat 与 fstat 的 st_ctime_ns 语义不同；随后在真实离线缓存 .cmd 文件还观察到 path st_mode=33279 与 FD st_mode=33206，但两者 S_IFMT 均 regular。其 dev/ino/nlink/uid/gid/size/mtime/birth 全同，两个 API 自己的前后值全部稳定。原 read() 将跨 API 的完整9字段强等，导致合法输入拒绝。该证据不是构建成功、故障样本或运行资格。

Root 精确 v2 采用件：H:/天俪商城/artifacts/project-organization-20261023/integrated-continuation-20261026-r1/BUILD-WINDOWS-READER-V2-ADOPTION.json

完整来源指纹：3191/cb9d9d4284bb79d76eb3aa41b6eac978792aa0e07e5c408aee8c143280054501。本包保存原件为 source-proof-r2/ROOT-READER-V2-ADOPTION.json，不改写旧采用历史。

## 最小算法

1. read() 内从同一次 regular(path) 的原生 lstat 取得 path-before 完整9字段：dev/ino/mode/nlink/uid/gid/size/mtime/ctime，并显式读取整数 st_birthtime_ns。缺失 birth 不猜值、不借用 ctime，拒绝。native9(path) 自身原9字段和顺序完全不改。
2. 打开同一文件后从真实 FD 取得 opened 完整9字段与 birth。跨 API 只比较共同7字段 dev/ino/nlink/uid/gid/size/mtime 和显式 birth；两端 stat.S_IFMT(mode) 必须相等并均为 stat.S_IFREG。Windows 合成权限位和 ctime 不作跨 API 强等。
3. 保原完整 raw 读取、额外 EOF 读取以及 len(raw)==opened.st_size。FD-after 的完整9字段（含自身 mode/ctime）必须等 FD-opened，birth 也必须相等。
4. 关闭后通过 regular(path) 再次真实 lstat，保 no link/reparse/ordinary regular。path-after 的完整9字段（含自身 mode/ctime）必须等 path-before，birth 也必须相等。返回原 raw，原 pin()/whole hash/namespace/copy/native9/资格所有调用者不变。

所有其它函数和调用者逐字保留；Windows/H5 构建入口、原PID/wait/fullraw、源码/依赖/输出/完整空间保护、固定命令和退出合同不改。不得为此重新设计 reader 框架、增加通用 fallback 或改依赖树规则。

## 任务与验收

- [x] 先保存此有限设计、Root v2 采用来源、完整 r1 清单和三项修改前原件。
- [ ] 仅替换 read() 完整函数正文，保存 before/after 与有限差量。
- [ ] 纯 Source 反向替换须整份恢复原14412字节 build_common；native9 和其余全部函数/四个 driver/十五叶/QUALIFICATION 均 raw。
- [ ] 仅更新当前 SOURCE-REPORT/PINS 身份，保旧报告原件的历史意义；全部真实资格 None/UNBOUND、四实际 manifest 缺失。
- [ ] 冻结后交非作者独立审；纯语法、普通定义导入或合法文件行为验证由 Root 按授权另行实际执行，作者不执行/解析/编译/import 候选。

本任务不运行 Node/npm/HBuilderX/PHP/Docker/SQL/HTTP/浏览器/目标，不创建 Run/DIST/current17/public-copy manifest，不改 Repo/C-D、不提交推送，不自签 Source0 或构建成功。任何额外实证缺陷先报 Root，不能顺手扩修。恢复采用新的不可变 Source 代际；旧 r1 与冻结 r2 不续改为可执行资格。
