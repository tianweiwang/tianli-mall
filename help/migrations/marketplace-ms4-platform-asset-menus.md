# 平台 site_image 三项菜单兼容注册

本具名CLI只维护真实system_menus完整22列和system_role完整5列；不读写商家、商品、平台资产/分类或业务文件。以原product-media菜单迁移完整实现为前继，独立marker=marketplace-ms4-platform-asset-menus-v1，独立session条件named lock，固定3leaf：GET marketplace/platform-assets(platform-assets-list)、POST同路径(platform-assets-upload)、GET marketplace/platform-asset-categories(platform-asset-categories-list)。

check只读；apply沿原完整schema/index/trigger元数据权威、独立实际PDO、原表锁、真实lastInsertId/完整native行回读、逐项期望、commit/rollback未知与单次同session锁释放。必须在Root准入的正常隔离环境才执行check/apply/check与重复apply零变化，本Source未运行任何CLI。

新leaf仅给具有对应原GET file/file、POST file/upload/<upload_type?>、GET file/category的启用角色(status=1)追加；原role rules文本前缀/原grant次序/其它全部列保留，空/null保持，不新增角色/硬编码ID，不授予product-save。停用角色仍完整核native规则语法，保持原值。平台菜单登记不代替当前管理员token/session/数据库角色+scope的每次真实检查。

商品显式导入使用原product-media菜单CLI第18leaf marketplace-products-import-platform-image，独立以旧GET file/file向启用角色复制；原17动作及原复制语义保留。运行时import仍须当前有效角色并集同时满足import leaf+旧file GET+真实商品save，并额外平台assets-list，最高管理员沿原有限行为。平台资产独立目的域，原件不会因菜单注册变成商业商品附件。

命令形状沿原：php help/migrations/marketplace-ms4-platform-asset-menus.php check|apply --config-file=<专用私有配置>；不在文档储存配置/凭据。SOURCE/语法/正常实际运行/浏览器验收分别记录，未运行的provider、device与完整T4责任仍保留。
