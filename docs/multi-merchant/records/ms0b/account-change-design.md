# MS0b Task6：普通账号变更与外层事务接线设计

本记录基于 `credential-writer-inventory.md` 和仓库真实源。最初只读设计时 HEAD 为 `72c9c3017c5bebc0707438b96ad39f0d23bf5f9b`，实现前已按方法复核Task5后的最新源，不把历史行号视为接口。设计已用于8efff286的Guard及三服务接线；普通功能15PASS/1NOT RUN，运行摘要见 `task6-functional-summary.json`。按用户最新范围，不准备或执行安全复现、故障注入、并发/断连或 RDB 恢复试验。

## 框架实际支持与提交边界

- [PDOConnection::transaction](H:/天俪商城/tianli-mall--ms0b/crmeb/vendor/topthink/think-orm/src/db/PDOConnection.php:1370) 先 startTrans，再调用闭包，再 commit，最后返回闭包结果。闭包返回 false 仍会 commit；合法 void/null 结果则可正常提交。
- [startTrans/commit](H:/天俪商城/tianli-mall--ms0b/crmeb/vendor/topthink/think-orm/src/db/PDOConnection.php:1395) 用 protected transTimes。最外层才执行 PDO.beginTransaction/commit；嵌套层用 SAVEPOINT 或仅递减计数。当前 MySQL 的 [supportSavepoint](H:/天俪商城/tianli-mall--ms0b/crmeb/vendor/topthink/think-orm/src/db/connector/Mysql.php:109) 为 true，现 PHP8.2 兼容连接器不改此逻辑。
- 此安装版本没有 afterCommit/onCommit 注册能力，也没有公开事务深度 getter。DbManager 的 after_update/after_delete 是 SQL/模型事件，Model 的 AfterUpdate/AfterWrite 也可能发生在外层事务提交前，不能用作最终提交通知。Compat Db 只改变缓存懒加载，未增加提交回调。
- [PDOConnection::getPdo](H:/天俪商城/tianli-mall--ms0b/crmeb/vendor/topthink/think-orm/src/db/PDOConnection.php:585) 仅观察当前 linkID，可能为 false；[connect](H:/天俪商城/tianli-mall--ms0b/crmeb/vendor/topthink/think-orm/src/db/PDOConnection.php:496) 才创建并返回实际 PDO。当前 [database 配置](H:/天俪商城/tianli-mall--ms0b/crmeb/config/database.php:55) 为单服务器 deploy=0、rw_separate=false；其 initConnect 使用同一个缓存 PDO。可用实际 PDO.inTransaction 核对是否已有未受管外层事务、提交后是否真的退出事务，无须反射 protected transTimes。
- [DbManager::connect](H:/天俪商城/tianli-mall--ms0b/crmeb/vendor/topthink/think-orm/src/DbManager.php:234) 复用默认 connection。所查 SystemAdmin、OutAccount、User 模型未自定义 connection，BaseServices::transaction 也转同一个 Db facade；这是本设计的真实同库范围，不扩展为跨连接事务框架。

因此最小实现是：Guard 持有已知业务最外层 transaction；只在它的 transaction 返回且实际 PDO 已退出事务后，调用 completeChange。Division 的完整用户/管理员写入须在这个最外层闭包内；不能把 Guard 放进既有 Division transaction 的内层。

## AccountChangeGuard 的最小受管上下文

保持 05 已约定接口：

```php
public static function run(string $app, string $id, callable $dbChange): mixed;
public static function runMany(string $app, array $ids, callable $dbChange): mixed;
```

run 只转为 runMany。ids 先验证为真实正数 subject id，规范化、去重并稳定排序；来源为实际管理员 id / out id，不能用 division uid 或 appid 代替。

外层调用顺序：

1. 纯参数、目标存在性、原密码/确认密码等业务校验尽量先完成。解析实际默认 connection 与 PDO；没有当前 Guard 上下文却发现 PDO 已在事务中，则拒绝该接线，不能自行声称内层事务返回代表外层提交。
2. 用已有 AuthSessionStore::fromConfig/beginChange，为非空 ids 全部取得本次 owner；任何业务写入尚未开始。空集合不伪造 subject，不需调用 Redis begin，仍可包住原用户侧普通事务。
3. 调用该 connection 的 transaction。在数据库闭包期间设一个仅当前调用栈使用的 managed context：app、connection/PDO 身份、冻结 ids 和本次 owner 集合。执行原完整业务闭包，并在此闭包的 finally 清空上下文。
4. transaction 正常返回后，核实际 connection/PDO 身份及 PDO.inTransaction=false，再按保留的准确 id/owner 调 completeChange。owners 保留在外层局部变量中，不依赖已删除表重查。
5. 返回原业务结果。只有正常提交路径可 complete，不把 complete 放进 finally，也不以返回值的泛化真假判断事务是否成功；外层 finally 清除上下文，不缓存上一请求主体。

内层已命名服务 writer 可复用上下文：只允许同 app、同实际 connection、ids 为已冻结保护集合的子集，且 PDO 仍在当前受管事务中。满足时只执行本次数据库闭包，**不再 begin、不另开 Guard transaction、不 complete**。若请求新 subject / 另一 app / 另一连接，不在已经开始业务写入的事务中扩张保护集合，直接拒绝接线。这样支持明确的 Division 外层组合，又不做全站 SQL 拦截、全局 after-commit 队列或长期实例状态。

managed context 只包本次 DB 闭包；嵌套 Guard 不自行清掉外层 context，最外层闭包 finally 才释放。普通异常沿原事务机制回滚；底层异常沿已有固定受控认证异常处理，不输出 owner、凭据、SQL 参数或底层 previous。此处不增加诊断/复现接口。

## 服务 update/delete 与批量目标

2026-10-11 实施细化：save/updateAdmin只对实际敏感patch接Guard，纯资料路径省略未变的敏感字段；显式update携带敏感键时全部冻结目标进入Guard，包括合法同值更新。此规则保留DAO Model返回形态并避免没有保护的敏感重写；affected rows为0仍合法。不带敏感键的统计、文件或推送更新保留原DAO路径。内层Division敏感update复用外层已保护集合，不重复begin/complete。

当前 [BaseServices::__call](H:/天俪商城/tianli-mall--ms0b/crmeb/app/services/BaseServices.php:151) 直接转 DAO；[BaseDao::update/delete](H:/天俪商城/tianli-mall--ms0b/crmeb/app/dao/BaseDao.php:256) 已支持 scalar pk / array where / optional key。建议最小补齐：

- SystemAdminServices 显式覆盖现有调用签名 `update($id, array $data, ?string $key=null)` 与 `delete($id, ?string $key=null)`；OutAccountServices 显式覆盖同 update 签名。update 只对 pwd/status/is_del 或 appsecret/status/is_del 接 Guard，非敏感统计、文件凭据、推送设置等保持原 DAO 透传。新建 save/create 不因方法名相似被强行当作已有 subject。
- 先以原谓词枚举真实 ids；实际 SQL 一律用被冻结 ids，不重新按开放 division_id 谓词更新/删除。沿用 DAO/模型写入，保留当前 Model 层行为。内层 writer 在 managed context 下用同一冻结集合，避免第二次获取 owner。
- [getColumn](H:/天俪商城/tianli-mall--ms0b/crmeb/app/dao/BaseDao.php:245) 是现有公开能力，可取 `id`。where 构造沿原 DAO 规则，两个账号模型 pk 都是 id。不能擅加 is_del=0 缩小 Division 旧谓词。
- Model::update 会忽略 save 的 bool 并返回 Model 对象，不能用这个对象的 truthy 当匹配行数/实际保存证明。需要用明确的保存 bool 或写后核对被冻结行的目标字段来确认业务结果；affected_rows=0 可能只是字段已相同，不应当作失败。Model::updateData 对无脏字段本来就返回 true。
- 已知单 id writer 在 begin 前做真实目标检查。Division 可能匹配零个管理员，跳过管理员 SQL/Redis保护，但仍执行原用户侧业务事务。不要给空集合编造管理员 id。

## 全部既有与新建入口接线

| 清单 | 最小普通接线 |
| --- | --- |
| W01 admin save | 校验密码/确认/长度、账号唯一及状态值。pwd 或 status 实际变更时，Guard 围住整个保存。闭包内复读当前目标，再赋本次提交字段并 save，不能保存保护前的陈旧模型；仅资料变化保持原会话行为。 |
| W02 本人 updateAdmin | 原密码、确认、强度等保持原校验位置。改密才 Guard；闭包内再核当前摘要/原密码并用新模型保存。纯姓名/头像不 rotate。现有 CacheService::clear 保持普通 DB 提交后的调用位置，最小做法为 Guard 正常返回后执行，不能移到 Model 内层“提交”点或凭空当 SQL commit 证明。 |
| W03 admin 软删 | controller 现有敏感 update 经新增服务 update 接 Guard，仍只写原 is_del=1/status=0。 |
| W04 admin 状态 | 在服务敏感 writer 前处理合法 0/1；同一 Guard 流程承接关闭/恢复，不用独立“恢复旧 version”分支。 |
| W05 out 密钥 | controller 既有验证、apppwd 明文与 appsecret 哈希同步保持；新增服务 update 覆盖此调用，按真实 out id Guard。 |
| W06 out 状态 | 原 strict 字符串 0/1 检查保持，敏感服务 update 统一 Guard。 |
| W07 out 软删 | 敏感服务 update Guard，只保留原 is_del=1，不附加旧业务没有的 status 写入。 |
| W08 divisionSave 编辑 aid | 密码/确认、目标和必要业务校验前置。有 pwd 时在原 Division 外层事务位置使用 run(adminapi, aid, 完整业务闭包)，用户更新与管理员保存共同提交；内部 admin 模型保存不能解除保护。无 pwd 的资料编辑沿原事务。aid=0 新建分支见 N01。 |
| W09 setDivisionStatus | 先校验 uid/status并按原 division_id 谓词冻结所有 admin ids；runMany 围住用户 division_status 与冻结管理员集合 status 的**单一**事务，替代当前两个独立提交与 && 短路。内层 adminServices.update 只接冻结 id 条件并复用 context。 |
| W10 delDivision | 先真实读取 userInfo，按其 division_type 选择分支。type1 冻结所有 division_id 匹配 admin ids，再在原外层位置用 runMany 包完整用户清理、物理管理员删除、关联用户清理；内部服务 delete 复用 context。type2/3 保持原不删除管理员分支，不额外 rotate。 |
| N01 新 admin / Division 新建 | 保留原 create 与 Model insert 事务；Division 新建整个用户/管理员业务事务仍由 Division 持有。新 id 不预分配、不加已有账号 begin。普通保存失败应抛出，不能让 divisionSave 的 false 被 controller 忽略后报成功。 |
| N02 新 out | 保留原验证、重复 appid 检查、默认状态、apppwd/appsecret 同步与 insert 行为；新 id 不套旧 subject 撤销。 |

W09/W10：uid 是用户 uid，冻结集合元素才是管理员 id。批量 begin 全部完成前不能先写 user；批量 complete 依据外层局部 owners，不能在每条 Model 保存返回时解除。W10 原为物理 DELETE，保持该业务语义；W09 原匹配包含已软删管理员，不擅改谓词。

W08 原 `divisionSave` callback 可返回 false，controller 却直接 success；应把确实保存失败转为普通业务异常，再让最外层 transaction 决定结果。W10 原 callback 为 void/null，Guard 必须允许该合法返回；不能写成 `if (!$result)` 一概当失败。

## 最小改动范围与普通完成标准

新增 AccountChangeGuard；修改两个账号服务的现有敏感 writer、Division 三个业务方法，以及确需前置 0/1 校验的 controller/验证点。沿用既有 default Db/PDO/DAO，不改 vendor、兼容连接器、全站模型或任意 SQL 通道。更新当前 05 Task6 对真实 managed context/外层提交的说明后，才进入实现。

普通完成标准是 10 个既有 writer 与 2 个新建路径逐项有明确持有者：合法变更的所有业务字段按原语义提交；Guard 只在真实最外层提交后完成；资料、统计、文件凭据及推送配置不误旋转；Division 批量使用准确管理员集合、用户关联一致、空集合和新建路径保持正常业务。当前记录不包含被用户跳过的安全或故障试验，也不把它们记为通过。任意运维 SQL / 历史备份导入不纳入这些具名业务 writer，原清单记录的运维边界继续保留。
