MS0b Task6：凭据、状态与删除写入清单及事务证据

只读源核对，未修改工作树、运行PHP/Docker、故障控制或提交。仓库为H:/天俪商城/tianli-mall--ms0b，读取时HEAD为5e32f80b55e471a3bf552516be2a99b1436ecc50。Task5接线仍由root推进，后续Out服务行号可能变化；下文方法名与调用路径用于实现前复核。依据现有05 Task6，不新增全站数据库拦截、SQL解析或Vendor事务机制。

已找到10个既有账号变更入口，2个新建持久化入口；Division新增路径复用管理员create。敏感v2字段为system_admin.pwd/status/is_del和out_account.appsecret/status/is_del，物理删除也属于既有subject撤销。Out的apppwd只是与appsecret同步的存明文列，push_password属于外部推送凭据，file_pwd属于独立文件凭据；后二者不是本阶段v2 credential_stamp输入。账号、角色、姓名/头像/规则变更列入同一行的完整性核对，但不凭空增加其单独版本撤销要求。

以下文件路径均以仓库根为起点；HTTP路径省略统一/adminapi前缀。路由当前由既有管理端登录/角色检查保护。本清单不替代权限验收或证明实际部署在用。

| ID | 实际文件与方法/关键行 | HTTP及服务调用链 | 实际写入字段/目标 | 当前事务边界 | Task6必须处理的边界 |
| --- | --- | --- | --- | --- | --- |
| W01 | crmeb/app/services/system/admin/SystemAdminServices.php:411 save(int id,array data)，434赋pwd/446模型save | PUT setting/admin/:id → v1/setting/SystemAdmin.php:104 update → services.save(id,data) → SystemAdminDao.get(id) → 现有SystemAdmin模型save | 指定id；有pwd才写新哈希，同时status必写；还写account/real_name/roles | 服务未包显式transaction；Model::save→updateData自身开事务 | 纯参数校验先于begin；涉及pwd/status变化的确切id必须block+rotate，真正DB提交后才能complete，DB失败保持blocked；不要保存保护前的陈旧凭据模型 |
| W02 | SystemAdminServices.php:459 updateAdmin，478新pwd，481/482姓名头像，483 save，484 CacheService.clear | PUT setting/update_admin → SystemAdmin.php:163 update_admin → updateAdmin(current adminId,data) → DAO.get → model.save | 本人id；旧pwd验证后可写new_pwd哈希；仅姓名头像路径不写pwd/status/is_del | 服务未包显式transaction；Model内部事务，随后执行crmeb tag缓存清理 | 改密必须保护本人所有sid；仅姓名头像保留现有会话。旧密码纯校验失败不能先rotate。保护后再核当前摘要以避免校验与写入间陈旧证明。tag清理是额外真实依赖，应明确其在DB commit/complete前后位置并测试失败，不把它当SQL提交证据 |
| W03 | crmeb/app/adminapi/controller/v1/setting/SystemAdmin.php:129 delete，132 services.update | DELETE setting/admin/:id → inherited services.update → BaseServices.__call → BaseDao.update → SystemAdmin::update(data,where) | 指定id软删除is_del=1、status=0 | controller无事务；Model静态update调用内部save/updateData事务 | 不能只保护SystemAdminServices.save方法；此继承update入口同样必须保护并在DB提交后complete |
| W04 | SystemAdmin.php:144 set_status，146 services.update | PUT setting/set_status/:id/:status → inherited update链 | 指定id的status | controller无事务；Model内部事务 | 关闭/恢复均需旋转旧版本，恢复状态不能使此前旧sid复活；目前无严格0/1校验，非法输入边界须按同一状态定义在写入/begin前处理 |
| W05 | crmeb/app/adminapi/controller/v1/setting/SystemOutAccount.php:113 update，123/124明文+哈希，126 services.update | POST setting/system_out_account/update/:id → StoreOutAccountValidate.update → getOne(id)存在检查 → inherited OutAccountServices.update → OutAccountDao/BaseDao.update → OutAccount::update | 指定id；appsecret哈希及apppwd明文同步；同时title/rules | controller无事务；Model内部事务 | appsecret变更保护outapi确切subject全部sid；保留apppwd原同步写法与其余CRUD行为，不能只靠authLogin/parseToken隐藏解决writer撤销 |
| W06 | SystemOutAccount.php:58 set_status，60严格参数检查，61 services.update | PUT setting/system_out_account/set_status/:id/:status → inherited update链 | 指定id的status | 无外层事务；Model内部事务 | 0和1两方向都受保护；原strict字符串0/1及id非空拒绝先于DB/Redis状态写入，非法值不写库、不先rotate |
| W07 | SystemOutAccount.php:70 delete，73 services.update | DELETE setting/system_out_account/:id → inherited update链 | 指定id的is_del=1；当前未同时改status | 无外层事务；Model内部事务 | 保留原软删除列行为；版本撤销不依赖附加改status，不改变历史CRUD数据语义 |
| W08 | crmeb/app/services/agent/DivisionServices.php:135 divisionSave，177外层transaction，190编辑aid分支，203 model.pwd，208 model.save | POST agent/division/save → v1/agent/Division.php:101 divisionSave → DivisionServices.divisionSave(data) → userServices.update(uid,agentData) → SystemAdminServices.get(aid)取得模型 → 直接模型save | 编辑aid分支：可写管理员pwd哈希，同时account/real_name/roles；用户的事业部字段也更新。aid是admin.id，uid是user.uid，不能混用为auth subject | 明确外层Db::transaction涵盖用户与管理员写入；模型save自身为嵌套事务 | Guard必须覆盖整个业务外层事务；只给管理员model.save或内部service.save套guard会在外层未commit时解除。若后续用户/管理员SQL失败或外层commit失败，管理员保护保持blocked |
| W09 | DivisionServices.php:316 setDivisionStatus，322 user update，323 admin update | PUT agent/division/set_status/:status/:uid → Division.php:178 setDivisionStatus → service → userServices.update(uid,division_status) → adminServices.update(['division_id'=>uid],['status'=>status]) | 用户division_status，所有division_id等于uid的system_admin行status；原where没有is_del过滤 | 当前两步没有外层transaction，各模型各自提交；&&短路可留下前一用户写入 | 必须先枚举、保护全部实际管理员id，再用单一事务更新用户和冻结id集合；不能先写user再begin，也不能每个id单独事务complete。0/1检查必须前置；不要把uid直接当adminapi id |
| W10 | DivisionServices.php:337 delDivision，339外层transaction，360 user update，362 admin delete，363关联user update | DELETE agent/division/del/:type/:uid → Division.php:191 delDivision → service事务；仅当前用户division_type==1分支调用SystemAdminServices.delete(['division_id'=>uid]) → inherited BaseDao.delete | 全部division_id==uid管理员物理DELETE，不是软删；同时清理多个用户事业部字段。分支选择由实际userInfo.division_type决定，不由route type决定 | 已有外层事务覆盖用户清理、管理员删除、关联用户清理 | 在第一笔用户写入前保护全部冻结管理员id，全部SQL真正提交后逐owner complete；内层delete完成不是commit。type2/3分支不删除管理员，不额外对不存在的admin subject旋转 |
| N01 | SystemAdminServices.php:357 create，377 transaction，378 DAO.save | POST setting/admin → SystemAdmin.php:66 save → services.create；另DivisionServices.divisionSave aid==0分支第188行复用create | 新自动生成admin.id；初始pwd/status/level/roles/division_id等 | create显式transaction+Model insert内部transaction；Division新增路径又有更外层transaction | 新subject没有既有sid需要撤销。列出创建路径但不猜测/预分配下一个id，不引入ID预留机制；若未来创建后要做账户状态完成，必须等最外层提交。新增调用仍需事务回滚测试 |
| N02 | SystemOutAccount.php:84 save，94 apppwd，95 appsecret哈希，98 inherited services.save | POST setting/system_out_account/save → validate/重复appid检查 → BaseServices.__call→OutAccountDao/BaseDao.save→OutAccount::create | 新自动生成out id，初始appsecret/apppwd/title/rules/add_time；status由原表默认值 | controller无外层transaction；Model insertData内部transaction | 保留新增默认状态与明文原储存行为；不把新appid当auth numeric id，不给无既有subject强行加旧版本撤销。与已存在id更新分开测试 |

DAO和事务调用证据：

1. BaseServices.php:151 __call直接call_user_func_array到当前dao。SystemAdminServices和OutAccountServices原来都没有自己的update/delete实现，故controller与Division调用能绕过它们已命名save/auth方法。SystemAdminDao.php:25和OutAccountDao.php:31分别绑定两个实际模型；BaseDao.php:150 getModel使用app容器。AdminAuthDao也绑定SystemAdmin，但当前AdminAuthServices只有解析读取，没有其他在线凭据writer。
2. BaseDao.php:287 update：id是数组即按整个where更新，否则按pk或传入key；返回Model::update(data,where)。Model.php:856静态update新建模型、设updateWhere、exists(true)->save(data)，并返回模型对象，不能拿truthy模型当实际匹配行数。BaseDao.php:256 delete：相同where分支、直接query.delete；SystemAdmin模型未使用SoftDelete，Division这里是物理删。BaseDao.php:319 batchUpdate与340 saveAll是可用继承能力，但精确调用搜索未找到这些账号服务现有额外调用者，不为不存在入口做全站扩展。
3. BaseServices.php:80 transaction只转Db::transaction。Model.php:525 save→591 updateData；638附近db->transaction执行update；667 insertData同样在自身事务执行insert。因此没有显式service事务不等于没有SQL事务，但模型的内部事务不能替代更大的业务事务边界。
4. PDOConnection.php:1370 transaction在callback返回后commit，只有异常才rollback。callback返回false也会commit；Division编辑aid分支208–211、SystemAdminServices.save/updateAdmin目前均可能返回false。Division controller还忽略divisionSave返回值，直接JSON success，不能通过false控制失败。Guard的DB阶段必须明确把真正业务保存失败转成异常，保持blocked；同时不能误把合法的统计update affected_rows=0当失败（Task5统计另属非敏感写入）。纯参数/旧密码验证等原有可预先拒绝的失败应尽量在begin前完成，保护后的当前DB复读再防陈旧校验。
5. PDOConnection.php:1395 startTrans递增protected transTimes；首次PDO.beginTransaction，嵌套使用SAVEPOINT。1426 commit只有transTimes==1时调用PDO.commit，否则只是递减计数。1443 rollback在外层PDO.rollBack，在内层回滚SAVEPOINT。实际Mysql连接器supportSavepoint()在vendor connector/Mysql.php:109返回true，PHP8.2兼容连接器仅覆盖SQL显示方法，没有改变事务行为。SystemAdmin、OutAccount、User模型没有自定connection，使用现有同一默认MySQL配置。
6. 所查Think ORM连接/管理器没有afterCommit/onCommit回调或public transTimes getter；不要凭猜测编码或新增Vendor改造。公开getPdo()只返回已有PDO，不建立连接；若要检查原生inTransaction必须先确认真实PDO已建立。针对已知调用链，在最外层业务方法外放guard，并让其数据库阶段覆盖完整原事务，足以避免另建全局commit回调架构。
7. AuthSessionStore.php:153 beginChange返回真实owner，158 completeChange按owner CAS。DB事务成功不能放在finally中complete；业务失败、commit失败、begin成功但应答丢失都不能补偿恢复旧version或盲目unblock。

最外层事务归属的具体推论：

- W01/W02/W03/W04/W05/W06/W07是controller直接调用，没有搜索到额外外层service事务调用者。Guard应作为这些既有subject变更的最外层数据库事务持有者，内部Model事务完成后仍等Guard最外层commit才complete；错误不能被controller转成成功字符串。
- W08现有外层先写user，再写admin。begin/rotate必须在第一笔user写入前，且真正complete必须在Division整个transaction返回并已提交后。应避免内层service再次begin获取不同owner；编辑aid原直接模型是确切旁路，单独修SystemAdminServices.save不覆盖它。
- W09目前根本不存在两表一致的外层提交点。必须把用户+全部管理员更新放入同一事务；保护全部匹配id在任何业务写入前完成。若枚举后SQL仍按开放division_id重新匹配，可能写到未保护的新匹配行；应让实际DB变更使用冻结且去重的id集合，完整保留原谓词目标（包括原where匹配的已软删行），不要擅加is_del=0缩小集合。
- W10已有外层事务；匹配id须在删除前冻结并保护，用户清理和物理删除任一失败都回滚业务SQL，全部已保护subject保持blocked。成功后不能通过重查已删除表取得owner列表，必须用保护阶段保留的准确id/owner；删除后的complete会解除保护但不会重建DB账户，旧version一直不可用。
- W09/W10可能实际匹配零个管理员。不能把user.uid伪造为adminapi subject，也不能对空集合调用单个begin。原有用户侧操作仍可按其既有语义在事务中执行、管理员变更限定空集合；这个边界应有针对性测试，不为新增账号或删除后重查空集合误报遗漏保护。
- N01的Division新增：用户事务→create事务→model insert事务至少三层。内层返回true不是最外层已提交。由于新ID不需要旧subject撤销，不应为了复用guard给create凭空分配ID；保留这一边界以防后续实现误套所有save。

故障测试设计需要区分这些事实，不能笼统断言所有故障全零状态：

| 故障位置 | DB结果 | Redis授权状态 | 返回与审计要求 |
| --- | --- | --- | --- |
| begin执行前失败 | 业务闭包未执行，DB不变 | 没有本次保护写入 | 503，脱敏，不记录owner/凭据/token |
| begin已执行但应答丢失；批量前几个已保护后下一目标失败 | 任何业务SQL不得开始 | 已执行目标blocked且旧version失效；后续目标可能未触及 | 503/人工复核，不complete此前目标、不重试、不恢复旧version |
| DB闭包保存失败、后续SQL失败或commit执行前失败 | 成功执行的外层rollback使业务SQL回滚；不把callback false当commit成功 | 保护过的全部目标保持blocked，旧version失效 | 失败，不在finally解除；审计不能泄露底层SQL参数 |
| commit执行结果不明确或其应答丢失 | 可能已提交，不能只凭异常或rollback尝试声称DB未变；须独立观察实际DB结果 | 全部已保护目标保持blocked，不执行complete | 503/人工复核，不重试或反向补偿DB，仍不报告成功 |
| DB已提交，complete执行前失败 | 新凭据/状态/删除已经提交 | 该目标仍blocked | 明确503需人工复核，不能报告成功或反向还原DB |
| DB已提交，complete执行后应答丢失 | 已提交 | 该目标可能已解除；批量其他目标可能blocked或已解除，不能声称统一状态 | 503需人工复核，旧version仍永久失效，不盲重试/补偿 |

成功测试至少真实覆盖W01/W02改密、W03/W04删除与关闭/恢复、W05/W06/W07密钥与状态/删除、W08事业部直写密码、W09至少两个匹配管理员批量、W10至少两个管理员物理删除并检查关联用户一致性。每个受影响subject准备两个旧sid，另准备其他subject/session，证明全部旧sid失效且无关账号/缓存/期限保留；状态恢复后旧sid仍401。W02纯姓名头像、Out推送设置、统计写入应作为不旋转正向对照。W09/W10在第二个目标begin失败和外层SQL最后阶段失败都要观察完整集合与全部业务表，不能只验证第一行。W08/N01还需外层晚失败回滚，证明不会在内层提交点提前解除。

精确搜索覆盖与排除证据：

- 无.codegraph，先读05已知源，再对crmeb/app、crmeb/crmeb、crmeb/public/install精确搜索SystemAdminServices/SystemAdminDao/AdminAuthDao/OutAccountServices/OutAccountDao、system_admin/out_account、两个模型完整类名和static调用、Division三个方法调用。当前在线调用者和直接表名未找到上述之外的pwd/appsecret/status/is_del写入或账号删除。
- SystemAdminServices.login:187直接Db更新last_time/last_ip/login_count，仅成功统计；保留的verifyLogin:73/83和verifyFileLogin:115/130也只设置这些统计字段后模型save，未给pwd/status/is_del赋值。Out当前authLogin/refresh更新last_time/ip，Task5拟改为精确Db统计写入。这些不是凭据/状态writer，不能通过通用update包装让每次登录/刷新旋转自己版本。
- SystemAdminServices.setFilePassword:497仅file_pwd；SystemFileServices独立配置filesystem.password认证路径不使用本次v2管理员pwd摘要。OutAccountServices.outSetUpSave:243仅DAO更新推送字段；SystemOutAccount.outSetUpSave:139输入无appsecret/status/is_del。两者保留原业务，不以敏感字段名称相似而增加v2撤销。
- UserExtractServices:624和StoreOrderController:629只调用adminNewPush；SystemLog查询管理员名；SystemRole.delete:119只checkRoleUse后删除角色；MCP:52只读out_account；OutPushListener读取启用账户及推送配置，写的是pushToken业务缓存，没有修改out凭据/状态。AdminAuthServices当前只有Token回调DAO读取。
- SystemClearServices.replaceSiteUrl:132及crmeb/command/Util:128直接SQL只替换system_admin.head_pic，不改本任务字段。SystemClearServices.clearData:32接受表名，但现有SystemClearData::index封闭分派和各固定表名单均未列system_admin/out_account；systemData:438只清system_notice_admin/system_log。不能把这类通用能力误报为已发现在线账号删除调用者。
- SystemCrudServices:48禁止生成表名单含两个表；make生成检查:686实际拒绝这两张系统表。UpgradeController中的匹配是路由/菜单/文件校验元数据，不是账号状态writer。所查继承batchUpdate/saveAll无这两个账号服务的额外现有调用路径。
- 运维恢复边界：public/install/index.php:414显式TRUNCATE system_admin、415重新INSERT账号，是安装流程；顶端install.lock阻止已安装重入，且当前PHP8.2安装页面还会版本拒绝，不是正常已安装管理端变更入口。SystemDatabackup::import:153→MysqlBackupService::import:288从备份逐条执行任意SQL（306 execute），理论上可覆盖账号两表或重用历史ID/摘要，且不通过Task6具名业务writer。清单明确记录这一能力，不声称Task6阻止任意DB恢复/人工SQL。该恢复必须在发布/恢复运行手册中隔离认证并处理世代/会话失效；不为此扩展全站SQL解析器或运行恢复测试。Task7/9文档应保留此运维边界，Task6完成报告仅声明覆盖已核在线账号变更入口。

此文件是源码事实和后续测试设计输入，不是Task6已实现/已通过的证据。源更新后须按方法重新核对行号、调用链及outer commit位置，再设计失败基线与实现。
