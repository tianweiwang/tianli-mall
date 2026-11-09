# 多商户开发测试版安装

准备 Git、Python 3.10 以上和带 `docker compose` 插件的本机 Docker 引擎。安装器根据引擎架构选择 `linux/amd64` 或 `linux/arm64` 镜像。首次安装需要联网下载镜像和 PHP 构建依赖。

## Windows x64

启动 Docker Desktop，使用 Linux 容器模式，在 PowerShell 中执行以下命令。

```powershell
git clone --config core.autocrlf=false --branch feature/portable-preview-install-20261109 https://github.com/tianweiwang/tianli-mall.git mall-dev
Set-Location mall-dev
Copy-Item help/preview/portable/config.example.json ../mall-preview.json
```

## Apple 芯片 Mac（包括 M6）

安装并启动 Apple 芯片版 Docker Desktop。打开终端，确认 Docker 服务端是 `linux/arm64`、`docker compose version` 能返回版本，再拉取安装分支。[Docker Desktop for Mac 官方安装说明](https://docs.docker.com/desktop/setup/install/mac-install/)

M6 Mac 真机安装仍待验证。

```sh
docker version --format '{{.Server.Os}}/{{.Server.Arch}}'
docker compose version
python3 --version

mkdir -p "$HOME/mall-portable" &&
cd "$HOME/mall-portable" &&
git clone --config core.autocrlf=false --branch feature/portable-preview-install-20261109 https://github.com/tianweiwang/tianli-mall.git mall-dev &&
cd mall-dev &&
cp help/preview/portable/config.example.json ../mall-preview.json
```

## 配置和启动

在 `mall-dev` 目录中编辑上一级的 `mall-preview.json`。保留默认值也可以启动。

```json
{
  "root": "./mall-preview-instance",
  "port": 18216
}
```

`root` 是专用实例目录，相对路径以配置文件所在目录为准。首次安装请选择 Git 检出之外尚不存在的目录。`port` 请选择空闲的本机端口。用现有检出体验新版时，先将安装包分支快进到最新版，再选择新的实例目录和端口。

Windows 使用以下命令。

```powershell
python -B help/preview/portable/install.py start --config ../mall-preview.json
```

Mac 使用以下命令。

```sh
python3 -B help/preview/portable/install.py start --config ../mall-preview.json
```

安装器使用仓库中的三端构建、固定公共镜像和 Composer 锁文件，在本机准备 PHP 镜像。每个实例分配独立 Docker 项目、网络和数据卷，入口绑定本机环回地址。首次启动自动建表、写入合成演示数据和随机账号，并启用跨店合单及模拟支付。无需导入原电脑的数据库、订单、上传文件或账号。

| 角色 | 默认入口 | 测试账号键 |
| --- | --- | --- |
| 顾客 | [顾客商城](http://127.0.0.1:18216/) | `accounts.customer` |
| 平台 | [平台后台](http://127.0.0.1:18216/admin/login) | `accounts.admin` |
| 商家 | [商家后台](http://127.0.0.1:18216/merchant/#/login) | `accounts.merchant`、`accounts.merchant_second` |

改过端口时，把上表地址中的 `18216` 换成配置中的端口。安装完成后，四个测试账号及密码保存在实例目录的 `private/preview-accounts.json`。默认配置下，Mac 可在 `mall-dev` 中执行 `open -e ../mall-preview-instance/private/preview-accounts.json` 查看。安装回执保存在实例目录的 `commands/`。

顾客在 H5 把两家演示店的商品加入购物车，选择「跨店结算」，选择地址后提交一张合单，再点击「测试支付」。顾客在合单中查看各店订单，两家店各自处理发货，顾客可分别收货并按店申请售后。平台「测试交易」会显示一笔合单收款及各店分摊。合单支付和退款均为本机模拟操作。

```text
python -B help/preview/portable/install.py status --config ../mall-preview.json
python -B help/preview/portable/install.py stop --config ../mall-preview.json
python -B help/preview/portable/install.py start --config ../mall-preview.json
```

Mac 将上述命令的 `python` 换成 `python3`。停止操作保留实例数据，再次启动沿用首装时的 Git 版本、配置和数据卷。首装中断时，保留实例和命令回执，按输出检查失败步骤。
