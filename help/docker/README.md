# 天俪商城优化版验收环境

优化版将 PHP 源码、Composer 依赖和前端静态文件构建进 Docker 镜像，避免 Windows 绑定盘的小文件性能问题。它启用了 OPcache，并修复了 Workerman 在容器重启后因残留 PID 无法启动的问题。

镜像现基于 **PHP 8.2**（`php:8.2-fpm-bookworm`），JIT 显式关闭。

> 仅用于本地开发和验收。完成生产安全加固、TLS 和备份恢复演练之前，不得直接用于公网生产环境。遗留阻塞项见文末「已知问题」。

## 首次配置

```powershell
Copy-Item help\docker\.env.runtime.example help\docker\.env.runtime
Copy-Item help\docker\.env.application.example help\docker\.env.application
```

修改两个本地配置文件，确保 MySQL 和 Redis 口令一致。它们已加入 `.gitignore`，不得提交真实口令。

现有验收库迁移时，应保留当前本地配置中的数据库口令，避免已有 MySQL 数据卷与新容器凭据不一致。

## 构建与启动

```powershell
docker compose --env-file help\docker\.env.runtime -f help\docker\docker-compose.optimized.yml config --quiet
docker compose --env-file help\docker\.env.runtime -f help\docker\docker-compose.optimized.yml build
docker compose -f help\docker\docker-compose.yml down
docker compose --env-file help\docker\.env.runtime -f help\docker\docker-compose.optimized.yml up -d --wait
pwsh -NoProfile -File help\docker\verify-optimized.ps1
```

商城地址：`http://127.0.0.1:8011/`

优化栈只向宿主机开放 `8011`。MySQL、Redis、PHP-FPM 和 Workerman 仅在 Compose 网络内访问。

## 修改代码后重建

优化版不再实时挂载 PHP 源码。源码或打包后的静态文件发生变化后执行：

```powershell
docker compose --env-file help\docker\.env.runtime -f help\docker\docker-compose.optimized.yml up -d --build --wait
pwsh -NoProfile -File help\docker\verify-optimized.ps1
```

## PHP 8.2 兼容性验证

镜像构建后、切换容器前，先做静态验证。`Candidate` 模式检查运行时版本与扩展、平台依赖、应用 lint、运行期诊断、图像输出比对，以及九项离线集成用例：

```powershell
pwsh -NoProfile -File help\docker\verify-php82.ps1 -Mode Candidate -Image tianli/crmeb-php:acceptance
```

期望末行为 `CANDIDATE_STATUS=GREEN`。

单独复跑某项集成用例（用 `www-data` 运行，与 FPM 进程用户一致；用 root 会使只读断言失败）：

```powershell
docker run --rm --network none --user www-data `
  -v ${PWD}\help\docker\probes:/probes:ro tianli/crmeb-php:acceptance `
  sh -c "cd /var/www && php /probes/php82-integration-compatibility.php jwt"
```

可用用例：`jwt`、`spreadsheet`、`order-export`、`product-export`、`easywechat`、`payment`、`sms`、`uploads`、`service-discovery`。

JDOSS 惰性 `S3Client` 的 AWS SDK 诊断单独验证：

```powershell
docker run --rm --network none tianli/crmeb-php:acceptance `
  sh -c "cd /var/www && php /probes/php82-aws-s3client.php"
```

常驻进程必须检查重启次数，不能只看状态。`startsecs=3` 意味着崩溃后 3 秒内重启仍报告 `RUNNING`：

```powershell
docker exec crmeb_php supervisorctl status
docker logs crmeb_php 2>&1 | Select-String -Pattern "Implicit conversion|process terminated" | Measure-Object
```

四个程序都应为 `RUNNING`，且计数为 0。

## 停止与回滚

停止优化栈：

```powershell
docker compose --env-file help\docker\.env.runtime -f help\docker\docker-compose.optimized.yml down
```

### 回滚到 PHP 7.4

`tianli/crmeb-php:php74-rollback` 是切换前保留的 7.4 镜像。Compose 引用 `:acceptance` 标签，所以回滚通过改标签完成：

```powershell
docker tag tianli/crmeb-php:acceptance tianli/crmeb-php:php82-verified
docker tag tianli/crmeb-php:php74-rollback tianli/crmeb-php:acceptance
docker compose --env-file help\docker\.env.runtime -f help\docker\docker-compose.optimized.yml up -d --wait --no-build
```

恢复 8.2：

```powershell
docker tag tianli/crmeb-php:php82-verified tianli/crmeb-php:acceptance
docker compose --env-file help\docker\.env.runtime -f help\docker\docker-compose.optimized.yml up -d --wait --no-build
```

必须加 `--no-build`，否则会用当前源码重新构建并覆盖 `:acceptance` 标签。

本次迁移不含任何库表结构或数据变更，因此回滚不需要恢复数据库。`help/docker/backup/` 下的转储是防御性备份，不是常规回滚手段。

回滚到旧栈（非优化版）：

```powershell
docker compose --env-file help\docker\.env.runtime -f help\docker\docker-compose.optimized.yml down
docker compose -f help\docker\docker-compose.yml up -d
```

以上命令都不得添加 `-v`。MySQL 示例数据位于 `help/docker/mysql/data`，上传文件位于 `crmeb/public/uploads`；删除卷或清空这些目录会造成数据损失。

## 已知问题（仍阻塞公网生产）

PHP 8.2 兼容迁移已完成，但以下问题未解决，上线前必须单独处理：

- **依赖安全公告 24 条，涉及 9 个包。** 已从迁移前的 33 条 / 11 个包降低，但未清零。
- **两个已废弃包**：`guzzle/guzzle`（Guzzle 3，应迁移到 `guzzlehttp/guzzle`）和 `topthink/think-factory`（无替代）。`overtrue/wechat` 3.x 亦已停止维护。
- **无第一方自动化测试套件。** `app/adminapi/controller/Test.php` 是业务控制器，不是测试。本次迁移依靠专用探测脚本验证，不能替代回归测试。
- **`think-image` `gif/Encoder.php:77`** 处理静态 GIF 时报 `Undefined array key 0`。该缺陷在 PHP 7.4 上即已存在（当时为 Notice），输出字节不受影响，故不计为迁移引入的问题。修复它需要改动帧数组逻辑，属于功能修复。
- TLS、生产密钥管理、备份保留策略与恢复演练均未配置。

### 项目自有兼容覆盖类

以下 vendor 类因无法在 PHP 8.2 运行且上游无可用修复版本，由项目自有实现覆盖，通过 `composer.json` 的 `exclude-from-classmap` 与 `classmap` 注册，位于 `crmeb/crmeb/compat/` 和 `crmeb/crmeb/services/`。升级相关依赖时必须同步复查：

| 覆盖对象 | 原因 |
| --- | --- |
| `think\image\gif\Decoder` / `Encoder` | 花括号偏移语法在 PHP 8.0 被移除 |
| `think\Image` | 文字水印坐标隐式浮点转换 |
| `EasyWeChat\Support\Collection` | `__set_state()` 参数数量、接口返回类型、序列化契约 |
| `think\Paginator`、`think\Model`、`Conversion`、`think\db\Where` | 接口返回类型声明 |
| `Pimple\Container` | 接口返回类型声明 |
| `Workerman\Events\Select` | `stream_select()` 微秒参数隐式浮点转换 |

---

# 安装docker
## docker 官网下载
https://www.docker.com/products/docker-desktop
## 命令安装
```
curl -sSL https://get.daocloud.io/docker | sh
```

# 方式一：一键启动CRMEB 系统

```
docker run -d \
  --name crmeb_app \
  -p 8111:80 \
  -v $(pwd)/crmeb/runtime:/var/www/crmeb/runtime \
  -v $(pwd)/crmeb/uploads:/var/www/crmeb/public/uploads \
  -v $(pwd)/crmeb_mysql:/var/lib/mysql \
  -v $(pwd)/crmeb_redis:/var/lib/redis \
  -e TZ=Asia/Shanghai \
  ccr.ccs.tencentyun.com/zbkj/crmebky:latest
 ``` 
 
# 方式二：docker-compose 快速运行项目


## 1、安装docker-compose
https://www.runoob.com/docker/docker-compose.html

## 2、下载CRMEB程序
建议去下载最新开源代码 https://gitee.com/ZhongBangKeJi/CRMEB
程序放到docker 同级目录下

## 3、启动项目
```
进入docker-compose目录 cd /docker

运行命令：
```
docker-compose up -d

```
## 4、访问CRMEB 系统
移动端访问地址：http://localhost:8011/
PC端访问地址：http://localhost:8011/admin


## 5、安装CRMEB
### Mysql数据库信息：
```
Host:crmeb_mysql
Post:3306
user:crmeb
pwd:123456
```
### Redis信息：
```
Host:crmeb_redis
Post:6379
db:0
pwd:123456
```

## 6、常见错误及解决方案

### 6.1 MySQL 启动失败
**错误现象**：MySQL 容器启动失败，日志显示 "--initialize specified but the data directory has files in it. Aborting."

**解决方案**：
1. 停止所有容器：`docker-compose down`
2. 清空数据目录：`rm -rf mysql/data/*`
3. 重新启动服务：`docker-compose up -d`

**原因**：MySQL 数据目录不为空，导致初始化失败。

### 6.2 数据目录映射问题
**错误现象**：数据库无法启动或数据无法持久化

**解决方案**：
1. 确保 `mysql/data` 目录存在：`mkdir -p mysql/data`
2. 确保 `mysql/data` 目录为空
3. 确保 docker-compose.yml 中正确配置了数据卷映射：
   ```yaml
   volumes:
     - ./mysql/data:/var/lib/mysql
   ```

**原因**：数据目录未映射或映射不正确，导致数据库无法创建或数据丢失。

## 6.3 常见需要映射的目录说明

### 6.3.1 MySQL 数据目录
- **本地路径**：`mysql/data`
- **容器路径**：`/var/lib/mysql`
- **用途**：存储 MySQL 数据库的数据文件
- **注意事项**：必须为空目录，否则 MySQL 初始化会失败

### 6.2 MySQL 日志目录
- **本地路径**：`mysql/log`
- **容器路径**：`/var/log/mysql`
- **用途**：存储 MySQL 的日志文件
- **注意事项**：确保目录存在且有读写权限

### 6.3.2 PHP 应用目录
- **本地路径**：`../../crmeb`
- **容器路径**：`/var/www`
- **用途**：存储 CRMEB 应用代码
- **注意事项**：确保目录存在且包含完整的 CRMEB 代码

### 6.3.3 PHP 运行时目录
- **本地路径**：`../../crmeb/runtime`
- **容器路径**：`/var/www/runtime`
- **用途**：存储 PHP 应用的运行时文件，如缓存、日志等
- **注意事项**：确保目录存在且有读写权限

### 6.3.4 Nginx 配置目录
- **本地路径**：`./nginx/vhost.conf`
- **容器路径**：`/etc/nginx/conf.d/default.conf`
- **用途**：Nginx 虚拟主机配置文件
- **注意事项**：确保配置文件存在且格式正确

### 6.3.5 Nginx 日志目录
- **本地路径**：`./nginx/log`
- **容器路径**：`/etc/nginx/log`
- **用途**：存储 Nginx 的日志文件
- **注意事项**：确保目录存在且有读写权限

### 6.3.6 目录创建命令
```bash
# 创建所有必要的目录
mkdir -p mysql/data mysql/log nginx/log

# 确保 CRMEB 应用目录存在
mkdir -p ../crmeb ../crmeb/runtime
```


