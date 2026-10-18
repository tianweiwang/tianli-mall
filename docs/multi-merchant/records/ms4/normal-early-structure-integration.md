# 新正常链的早期结构驱动接入

本记录对应09原Task6/7/8，沿已采用的售后/评价正常联调设计实施，不替代原执行清单。目标仍为通用多商户商城；商品、规格、价格沿原有通用能力。仅本地L2和专用隔离实例，无推送、部署或真实资金操作。

## 目的与必要顺序

售后及评价产品已接入，但旧隔离驱动在首次App之后才做履约迁移，不能用于要求最终售后结构的新产品。后继保原九条初始化和MS5四命令，先原履约四命令、售后四命令及两套菜单各四命令，再files-bootstrap、顾客准备、测试付款、原Task6完整正常链和新的Task7/8正常业务。

原履约四命令的24列完整oracle只用于升级前时点；售后二十步升级后按独立完整stage20验证25列operation及其余原生结构。两菜单迁移只注册实际7+3叶，不授予角色权限。所有命令须取得真实同句柄终端、双流和完整结构/行/Redis结果，不能用源码或命令清单代运行。APP仍只用DML账号；专用migrator负责既定DDL。

## 来源与接入边界

UI构建固定在Main提交0d5499ecf95ec9c9c29d7f6e011cecb69a2b81c1，因此在独立detached工作树ms4-aftersale-normal-check准备验证源码。Main、旧ec814e74快照及其失败记录均保持。原失败Run202610180549014acc18已实际关闭并独立核零，原business=null/legacy native1/三读stage0不升级为通过。

早期驱动R1为仓库外ms4-aftersale-early-migration-source-r1的唯一候选，160150 B，SHA256 0d05fabcd9b3b25f8182fd3ec3b533ae7af1e581340452421fdcb906adac03d4。原65524 B供体与此工作树旧文件按实际字节核对；原四CLI、二十二个原完整函数及断言保留。八组完整raw正逆、十九份依赖和十五运行来源已获非作者复核；独审报告9224 B，摘要f4ffe1fe16ac0c46f1b71f412347f070faf73dd1d7046065e8ac8c9f80a2614c。Root已实际PHP8.2语法native0/stderr空、临时容器无残留；未运行DDL或App。

最终选用仅修诊断位置的R2：160796 B，SHA256 74a0550825ef7ec58ebe1761c4e37b1d2eeba037de7de20eefa126d9d60e09c3。两组raw整件正逆和非作者独审均通过；增量独审报告2331 B，摘要7aae82f43b5dcc651269fa054b00425a8448d813146a485a22562cb32471a38e。保class/file/line/phase四字段，只在本文件的确切RuntimeException、m4fm_expect首frame及同文件正整数行号时报告真实caller line；不输出消息、参数或trace，其余断言、十六命令和成功结果不变。Root对实际工作树文件再次执行固定PHP8.2语法检查native0/stderr空，临时容器前后为空，文件字节前后保持。这里只证明源码及语法，不证明实际诊断故障样本或迁移业务通过。

## 仍待验证

新驱动固定八个真实初始化/MS5前置，输入binding10键、输出binding13键、父结果27键、十五来源、四原children与十二successor children。新的七卷Host/profile、initializer/MS5和后继Task6消费者仍在接线，旧六卷r4不能直接运行此片。新正常角色须等原Task6真实自然0且精确移除后串行创建，保原Task6的29/business33/shutdown15及历史task7 NOT RUN。

新二十步、菜单、HTTP、非空私有/公开图片及完整业务/库存/DB/Redis尚未据本片验证；Worker、DOM、非空历史、真实金融和发布责任仍在原09。用户停止的拒绝、安全、无效、冲突、并发、故障、掉线、刷新、恢复和到期样本继续NOT RUN。只有完成实际来源提交、profile接线与Root原生验证后，才能更新对应正常结果。

## 本地接入结果

本独立来源基于实际0d5499ec，在feature/ms4-aftersale-normal-source本地分支保留。当前Main的cd513a96只补三端构建与旧失败收尾记录；应用源码与0d5499ec相同，三端完整构建均通过，构建冻结已解除。R1原件、原65524 B供体及原失败均保持。实际接入仅本早期驱动和本记录两件，git diff --check通过；未推送、部署或启动新的业务Run。外部root-r2-application保存真实语法和来源收据，提交后的完整SHA另在仓库外检查点记录。
