# 平台商品图片十项权限迁移

承接商品图片列表/上传及分类与整理，并新增在线图片，共十项API。新增独立API leaf：

| 方法 | API规则 | unique_auth |
| --- | --- | --- |
| GET | product/product/<product_id>/media | legacy-product-media-list |
| POST | product/product/<product_id>/media | legacy-product-media-upload |
| GET | product/product/<product_id>/media/categories | legacy-product-media-categories-list |
| POST | product/product/<product_id>/media/categories | legacy-product-media-categories-create |
| PUT | product/product/<product_id>/media/categories/<category_id> | legacy-product-media-categories-update |
| DELETE | product/product/<product_id>/media/categories/<category_id> | legacy-product-media-categories-delete |
| PUT | product/product/<product_id>/media/<att_id>/name | legacy-product-media-rename |
| PUT | product/product/<product_id>/media/move | legacy-product-media-move |
| POST | product/product/<product_id>/media/delete | legacy-product-media-delete |
| POST | product/product/<product_id>/media/online_upload | legacy-product-media-online-upload |

独立CLI使用原MS1私有配置解析，只做九leaf及角色rules的有限DML；不启动框架，不DDL，不开启marketplace，不修改任何商家/商品/素材或旧菜单。先稳定结构账户授权，再由独立main创建全新非持久PDO；该账户须能以SCHEMA_PRIVILEGES正向证明当前数据库直接SELECT和TRIGGER。完整菜单22列、真实角色5列（id/role_name/rules/level/status）、索引/约束/trigger观察使用精确BINARY schema并前后核CURRENT_USER/authority。不能把空trigger集合当无条件的完整证明，不扩App权限、GRANT/USE/SET ROLE或借授权前旧会话完成资格。

```text
php help/migrations/marketplace-ms4-product-media-menus.php check --config-file=<专用私有配置文件>
php help/migrations/marketplace-ms4-product-media-menus.php apply --config-file=<专用私有配置文件>
php help/migrations/marketplace-ms4-product-media-menus.php check --config-file=<专用私有配置文件>
```

check只读，不申请named lock。apply先完整检查；在确认不是caller原同session锁后申请本有限锁，执行前记UNKNOWN；任何可能已获取的结果均由同PDO确认，必要时做最多一次session条件RELEASE_LOCK，不重试/RELEASE_ALL。两个表在同事务锁定，缺失leaf使用真实lastInsertId并全行读回；旧完整菜单保持。每个角色只要具有实际有效旧GET file/file或POST file/upload/<upload_type?> grant，即追加对应新leaf，保原rules文本前缀、原有grant次序和其它全部列。包含停用角色，status本身不改；空/null rules无授权保持原值；malformed rules在任何写入前停止。没有新角色、硬编码菜单ID或给空角色补权。重跑已齐leaf/复制后零写。

运行时并非只看复制后的leaf：当前有效管理员的启用角色并集必须同时有本项leaf、对应旧FILE grant、任一真实POST product/product/<id>保存grant，允许三者分布在不同角色；最高管理员保有限action原行为。migration不把“同角色同时有save”作为复制条件，亦不主动授予save。

公开回执不含原rules、私有配置、session id或原始行。changed/roles_modified在未确认提交或回滚时可以为null；commit_observed只记真实成功返回，锁释放失败与最初failure_phase分列，不以资源正常收尾冒称DML提交。不能用未来预填回执代替实际重复零变化、完整原表和权限当前生效验证。

此文件属于Source候选；PHP语法、check/apply、权限实际生效、新接口/页面及完整T4均NOT RUN。正常验证由Root在后继隔离窗口安排，L2本地，未推送或部署。

分类四动作分别复制旧GET/POST file/category与PUT/DELETE file/category/<id>；改名复制PUT file/file/update/<id>，移动复制PUT file/file/do_move，删除复制POST file/file/delete。原两leaf完整字段及nativeID保持；同一CLI重复apply也不改原rules前缀或非rules四列。

商业图片CRUD保实际商品范围、完整分类6/附件14，name SQL LIKE/collation及旧tree/lazy DTO；rename仅real_name，move仅pid，完全相同后态零目标DML。移动body为images逗号串+pid，删除body为ids单ID或逗号串，最多50正ID去重排序。叶分类删除先拒真实子类/不兼容pid链接，实际附件pid迁合法父或0后再删目录，物理文件不动。

两删除能力依赖T3三表已实际完整安装；同writer PDO先实际probe customer/platform asset的legacy_att_id和platform category的legacy_category_id列，再作当前ID匹配，缺表列/未知不可跳过。局部probe不是完整schema/trigger资格，不调用AssetSchema inspect或扩App权限。素材删除随后实例调用原持久路径alias引用检查，真实locator/identity去重后复用Storage.remove；失败六键receipt保持未知，不cleanupNew、不宣称物理rollback。原分类表单GET与前端submit URL接线、视频/扫码/在线/导入/最终引用门继续后继，九API不代表完整T4。

九图片入口统一metadata图片MIME资格：沿现ReviewDao的image/jpg、image/jpeg、image/gif、image/png、image/x-icon、image/vnd.microsoft.icon、image/webp七值；列表/count同BINARY att_type过滤，native14和旧DTO/排序保持。整理动作先锁全选逐行核MIME，视频/未知不能被漏掉后部分成功；category叶删所有pid引用同样核。upload仍原jpg/jpeg/png/gif/webp五扩展与GD/MIME，不增icons上传；这只是metadata资格，非历史原文件全文字节证明，不重标原员工MP4 type0记录。

在线图片扩为第十leaf：只按每角色实际POST file/online_upload旧grant追加新leaf，原九完整leaf/native IDs、原rules字符串前缀与schema authority/锁/提交算法保持；运行时仍newleaf+旧FILE+实际save当前角色并集三AND。原九方法与旧17 collector/grant控制流完整保持。

POST online_upload仅接pid和有序1..50项images，保重复。先真实Request授权，再独立有界HTTP/S预取与全批实际bytes资格，网络不占商品writer锁；随后一次原writer重验actual owner/category，同PDO先全物理，再全native14/locator/admin audit。成功保“上传成功”并返回实际list[{att_id,src}]。原URL/query不入metadata或审计；scan_token为空、module1/type0。

每图受Config上传大小及50MiB上限约束，总响应体64MiB；有限手动跳转、TLS/DNS固定地址与peer资格、单调60s逐步预算，系统同步DNS不能伪称硬中断。仅精确配置site origin下规范/uploads路径可例外访问非公网。Storage共同bytes叶子须与本Source一起采用，沿原六键未知收据，批级true物理事实优先unknown；DML/提交未知后不cleanupNew或假恢复。在线HTTP/云渠道/菜单迁移/UI和手机staging→PCclaim仍待实际正常验证，十leaf不等于完整T4。


## 商品扫码五项追加 leaf（总数15）

| 方法 | API规则 | unique_auth | 原 FILE grant |
| --- | --- | --- | --- |
| POST | product/product/<product_id>/media/scan | legacy-product-media-scan-issue | GET file/scan_upload/qrcode |
| GET | product/product/<product_id>/media/scan/<lease_id> | legacy-product-media-scan-poll | GET file/scan_upload/image/<scan_token> |
| GET | product/product/<product_id>/media/scan/<lease_id>/items/<stage_id>/content | legacy-product-media-scan-preview | GET file/scan_upload/image/<scan_token> |
| POST | product/product/<product_id>/media/scan/<lease_id>/claim | legacy-product-media-scan-claim | PUT file/file/do_move |
| DELETE | product/product/<product_id>/media/scan/<lease_id> | legacy-product-media-scan-close | DELETE file/scan_upload/qrcode |

原十 leaf 和整段迁移算法保持；preview 是独立第五权限，不复用 poll 的新 leaf。手机 POST image/product_scan_upload 不登记管理员 leaf，只接受 file/lease_id/scan_cap，到 private staging 为止。每个 PC 动作仍验证原发行者真实会话、当前新 leaf+原 FILE+商品保存三 AND；匿名手机不能写 system_attachment/商品或选择商家/分类。

claim 支持明确 destination_pid，有序 stage_ids 原顺序与目标一起冻结。初次仅由真实 writer 同 PDO保存服务端32hex claim marker、完整14字段和真实审计；原通用 add 的空 scan_token 门保持。DONE 通过专用实际 PDO 的完整只读 rows/object/audit 核验返回原结果，不进入原 writer 产生新 product audit。处理中、未知或私人清理未证不得返回“上传成功”。扫码 PHP/HTTP/菜单首次与重复/GC及 UI 正常验收均待实际执行，不计作 T4 完成。


## R85 平台单件视频/音频两项追加 leaf（总数17）

本节追加原15项；前述各阶段的十项、十五项说明均为当时历史范围，原entries顺序、完整字段和已有native菜单ID保持。

| 方法 | API规则 | unique_auth | 原 FILE grant |
| --- | --- | --- | --- |
| POST | product/product/<product_id>/media/videos/upload | legacy-product-media-video-upload | POST file/upload/<upload_type?> |
| POST | product/product/<product_id>/media/audio/upload | legacy-product-media-audio-upload | POST file/upload/<upload_type?> |

原具名菜单CLI只在oldFileGrants与menuRows末尾各追加以上两项，实际枚举15→17。原schema authority、锁/事务、完整22列menu和5列role、真实lastInsertId/full-row读回、逐角色FILE-grant有限复制、原rules文本前缀、repeat零写算法均不改。仅持有真实原单件multipart FILE grant的角色可获对应新leaf；空/null rules仍原值，不新增角色或授予product-save，不修改安装业务种子。

运行时继续要求当前有效角色并集同时具备新canonical leaf、对应原POST file/upload/<upload_type?>以及实际legacy-product-save三项AND；level0保原有限action规则。菜单登记不会代替真实Request、product0/实际商品锁、owner、typed完整内容和native14/PDO/审计/提交资格。

本节仅接平台单件multipart路由。原图片/online/扫码15项、分片/云直传finalize、商家、typed列表/选择器和UI/platform29的后续责任保持；不能以两leaf登记代表这些功能完成。

状态：仅外部Source overlay；必要PHP语法、后继专用ISO实例原check/apply/check/repeat、实际有限角色权限与单件HTTP/native14保存回读均NOT RUN。Root后续在专用隔离实例执行原CLI；本次未连接真实或隔离数据库、未运行CLI/PHP/SQL、未修改W/Val或已固定平台四件，未推送/合并/部署。


Root后继检查点：17叶CLI整份PHP语法已在固定隔离PHP8.2中通过；check/apply/check/repeat、角色实际生效和接口业务仍未运行。本地联调状态见[商品媒体记录](../../docs/multi-merchant/records/ms4/product-typed-media-integration.md)。


## R97 平台图片显式导入（第18项）

保原17完整菜单/native IDs/marker/算法，仅追加POST marketplace/products/<id>/import-platform-image，unique_auth=marketplace-products-import-platform-image，旧兼容grant固定GET file/file。仅向status=1角色追加此新leaf；原17复制停用角色等历史行为不变，原rules前缀及其它列保持。运行时仍import leaf+旧file GET+真实product save三AND，另核platform-assets-list当前真实grant。scope来自实际双route标记而非构造，target0仍真实创建资格，正ID原writer实际owner。此Source未运行迁移，正常check/apply/check/repeat和权限实际生效单独待验。

## 2026-10-26 新安装兼容

原安装种子没有登记 online/scan 四种历史 FILE 路由，不能以全部旧路由存在作为18个新权限登记的前提。检查允许对应旧授权集合为空，复制结果自然为零；已有旧授权仍按原算法复制，运行时新权限、旧 FILE 权限、商品保存权限三项检查保持，不补造旧权限。独立预览新库的正常 apply/check/reapply 已实际通过，18项登记完成，原菜单/角色保持，重复执行 changed=false。证据为仓库外 `artifacts/multi-merchant-preview-20261026/commands/012`；这不代替有限角色的接口验收或已停止样本。
