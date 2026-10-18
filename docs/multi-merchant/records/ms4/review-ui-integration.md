# Task8 三端评价页面整合

2026-10-18，沿 09 原 Task8 和 D19 继续本地 L2 实施，目标为通用多商户商城。本文记录实施决定与实际证据，不新增一套完成标准，不代替正常业务和三端页面验收。

## 接入前采用

采用仓库外 `ms4-review-task8-three-end-ui-source-r1/root-source-assembly-r1` 的最终 15 件、256671 字节源码，清单 4041 B，摘要 `f6d20fc5eb1166dfac7c3c9f1e816a3cc60212b5fc320895d4e6c641978189a5`。它包含原 15 件中的 14 件和已独审的顾客图片回读单页后继，原 R1 与所有供体均保留。Root 已读总交付报告、共享叶和顾客传输、关键读写接缝，以及两份非作者报告；最终外部 14 个 JS/Vue 的 22 个平台目标和 pages.json 解析实际通过，源码前后保持。

顾客只从实际 RootView.order.id 进入，再用现有已授权 Q2 的 root_items[].item.id 选择原保存商品项，逐项读取真实评价资格；不用购物车引用或子包裹序号冒充原项主键，不按退款可用量过滤已收货项。保存商品、规格、价格沿原 Item9。商家只按实际 product.read/write 读取本店及回复已审核记录；平台只按三个实际评价权限读取和审核。原顾客文字/评分/图片不提供修改、删除或虚拟评价入口。

公众保持原 12 字段，OwnReview 保持 16 字段；create exact5，reply/moderate exact2，不增加服务端幂等键或 actor。公开上传分别保存原 attachment_ref 与预览 URL；不混用售后私有票据。未知提交冻结原内容并先 GET，非空图片不能仅按数量或展开 URL 猜原引用相等；保原内容并由用户明确以原 wire 再 POST，让 Core 原 existing/sameBody 在新资格判断前精确确认。零图且其余正文完全匹配才可由 GET 收口，正常直接 POST 确认路径保持。没有运行故障、unknown 或其他停止样本。

原 R1 非作者报告 8294 B，摘要 `24ca23401f2933c2138257dc7d82889f2930f6c959c408f9cf6cae3fc3c7ae0e`；图片回读增量报告 4297 B，摘要 `3edf672c23b73fb98ba40f4b1a018553fbd5913b016d99876f43d7456b1f9c25`。前者唯一 P2 以该后继源码闭合，不把 SOURCE 结论称运行通过。

## 同片必要修订

另接入已核的两件 legacy 诊断 helper：仅在真实同句柄子进程自然1、完整有界公开 failure7/diag4 与原计数/摘要相符时，向父 STDERR 重建有限安全字段；不打印原输出、消息、栈、参数、环境或私密内容。原成功/nativezero/清理断言不改；warning 改用固定消息的 ErrorException 保实际文件位置，现诊断仍只输出 basename/line。Root 已实际核三组整文正逆，并在 PHP8.2 下对两候选语法检查通过；首次 Root lint 缺 `/tmp` 导致运行时锁文件失败，原记录保留，补独立 tmpfs 后通过，产品源码未因该环境问题修改。此片不能恢复旧 Run 已丢失的错误，也不声称已修复未知业务根因。

平台 public/index.html 的 manifest 引用改用原 favicon 已使用的 BASE_URL，防 history 深层地址按当前目录解析；保原 manifest 文件和其它资源。该一行属明确入口修复，后续完整构建核实际 `/admin/manifest.json`。

## 验证与保留项

接入前以实际 clean HEAD 核供体，保存每件原字节，按唯一最终清单接 15 页面源、两 helper 和一入口修订；接入后核相同最终字节、必要语法及源码检查，再做 scoped 本地提交。此前 929a16a9 的三端完整构建只覆盖售后版本，本片改变输入后必须另行完整构建，不替换旧证据里的 source head。

后继正常联调采用外部 `ms4-aftersale-normal-integration-plan-r1` 及 Root 采用说明：新完整结构必须先于首次 App，保原 Task6，再串行新的售后评价正向链、真实登录、两种非空图片、原生数据核对及正常收尾/独立核零。失败退休不代表正常业务通过。当前新真实业务/HTTP/非空图/Worker/三端 DOM、停止样本、真实金融、历史和发布均不据本片签收；无推送、部署或生产修改。

## 实际接入与检查

在实际 HEAD `929a16a9f937d1a3c6c449cc5b7a4b37a493f14e` 后接入18件产品/验证源码：上述15件评价页面、两件诊断 helper、一行 manifest 引用。原9件已存在文件逐件核实际原字节并保存，9件新增路径确认此前不存在；6件页面修改源均与固定 Root21 售后供体相同，未覆盖平台已接入的 request/router R2。商家评价页仅额外清除4处空白行的行尾空格，其余最终字节逐件与供体/明确单行替换一致。唯一最终清单为仓库外 `ms4-review-ui-main-integration-r1/SOURCE-MANIFEST-r2.json`，原清单及 `before-files/` 均保留。

Main 的14个JS/Vue文件实际完成22个平台目标模板/脚本语法检查，pages.json解析通过；商家原source-check实际通过39脚本及93个本地import。两个Main helper在既有PHP8.2镜像中真实语法native0/stderr空，临时无网络、只读语法容器前后无残留。Root最初路径匹配把Windows分隔符与JS固定正斜杠比较而停止，随后lint漏显式entrypoint进入镜像应用启动并因只读路径chown失败；两次均为Root检查脚本问题，原失败保留，修正检查调用后完成，产品源码没有因此改变。首次staged diff --check另发现新增商家页4处空白行尾空格，清除后对最终14件/22目标及pages再次检查通过，其余17件（含两PHP）保持；最终源码清单与检查为同目录SOURCE-MANIFEST-r2.json、FRONTEND-SYNTAX-r2.json和RESULT-r2.json。

本次已接入和语法验证，不代表新三端完整构建、真实登录/业务/图片或DOM通过。929a16a9三端构建保持原来源，仅可证明前一售后提交；新输入须另做完整构建。旧履约失败原因仍未知，有限诊断修改尚未运行到该业务阶段，不将语法通过称根因修复。
