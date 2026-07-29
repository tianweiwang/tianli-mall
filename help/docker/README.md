# 天俪商城优化版验收环境

优化版将 PHP 源码、Composer 依赖和前端静态文件构建进 Docker 镜像，避免 Windows 绑定盘的小文件性能问题。它启用了 OPcache，并修复了 Workerman 在容器重启后因残留 PID 无法启动的问题。

> 当前镜像仍基于 PHP 7.4，仅用于本地开发和验收。完成 PHP 8.2 兼容升级、生产安全加固、TLS 和备份恢复演练之前，不得直接用于公网生产环境。

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

## 停止与回滚

停止优化栈：

```powershell
docker compose --env-file help\docker\.env.runtime -f help\docker\docker-compose.optimized.yml down
```

回滚到旧栈：

```powershell
docker compose --env-file help\docker\.env.runtime -f help\docker\docker-compose.optimized.yml down
docker compose -f help\docker\docker-compose.yml up -d
```

以上命令都不得添加 `-v`。MySQL 示例数据位于 `help/docker/mysql/data`，上传文件位于 `crmeb/public/uploads`；删除卷或清空这些目录会造成数据损失。

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


