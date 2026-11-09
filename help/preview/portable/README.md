# 多商户开发测试版安装

准备 Git、Python 3.10 以上和带 Compose v2 的本机 Linux amd64 Docker 引擎。Windows 可使用 Docker Desktop 的 Linux 容器模式。首次安装需要访问锁定的镜像仓库，以及 PHP 镜像构建所用的 Debian、PECL 和 Composer 依赖源。

在 Windows PowerShell 中执行：

```powershell
git clone --config core.autocrlf=false --branch feature/portable-preview-install-20261109 https://github.com/tianweiwang/tianli-mall.git mall-dev
Set-Location mall-dev
Copy-Item help/preview/portable/config.example.json ../mall-preview.json
```

在其他支持的平台可用 `cd mall-dev` 和 `cp help/preview/portable/config.example.json ../mall-preview.json`。已有检出请先确认换行符和 Git 工作树状态，安装器会核对前端构建使用的源文件字节。

编辑 `../mall-preview.json`。`root` 是专用实例目录，相对路径以配置文件所在目录为准。首次安装选择尚不存在且位于 Git 检出之外的目录。`port` 选择空闲本机端口，默认 18216。用现有检出更新时，先将安装包分支快进到最新版，再给新版本配置新的实例目录。

```text
python -B help/preview/portable/install.py start --config ../mall-preview.json
```

安装器使用仓库中的三端构建、固定公共镜像和 Composer 锁文件，在本机准备 PHP 镜像。每个实例分配独立 Docker 项目、网络和数据卷，入口绑定本机环回地址。首次启动自动建表、写入合成演示数据和随机账号，并启用跨店合单及模拟支付。无需导入原电脑的数据库、订单、上传文件或账号。

| 角色 | 默认入口 |
| --- | --- |
| 顾客 | http://127.0.0.1:18216/ |
| 平台 | http://127.0.0.1:18216/admin/login |
| 商家 | http://127.0.0.1:18216/merchant/#/login |

安装完成后，顾客、平台及两家商家的账号保存在实例目录的 `private/preview-accounts.json`，各阶段回执保存在 `commands/`。首页沿用隔离预览装修，初始化后可在平台后台配置。

顾客在 H5 把两家演示店的商品加入购物车，选择「跨店结算」，选择地址后提交一张合单，再点击「测试支付」。顾客在合单中查看各店订单，两家店各自处理发货，顾客可分别收货并按店申请售后。平台「测试交易」会显示一笔合单收款及各店分摊。合单支付和退款均为本机模拟操作。

```text
python -B help/preview/portable/install.py status --config ../mall-preview.json
python -B help/preview/portable/install.py stop --config ../mall-preview.json
python -B help/preview/portable/install.py start --config ../mall-preview.json
```

停止操作保留实例数据。再次启动沿用首装时的 Git 版本、配置和数据卷。体验新版本可指定新的实例目录和端口，已有便携实例不会自动迁移。首装中断时保留实例和命令回执，按输出检查失败步骤。安装器会拒绝在未完成的实例上重复初始化。
