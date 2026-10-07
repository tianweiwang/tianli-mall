# 天俪加盟门店平台 H5 Demo 部署说明

## 部署边界

- 只部署 `demo/franchise-platform-h5/dist/` 内的静态文件。
- 不需要 PHP、MySQL、Redis、CRMEB API 或其他后端服务。
- 使用独立的 `/franchise-demo/` 子目录，不覆盖或代理当前 `8011` CRMEB 服务。
- 摄像头扫码需要 HTTPS；非 HTTPS 环境仍可使用二维码图片识别和备用编号。
- 业务状态保存在当前浏览器的 `localStorage`，不同设备不会实时同步。

## 构建

在项目目录执行：

```powershell
cd demo/franchise-platform-h5
npm install
npm run test
npm run build
```

将 `dist/` 目录中的全部内容上传到服务器的 `/srv/tianli/franchise-demo/`。不要把 `dist` 目录本身再嵌套一层。

## Nginx 配置

以下配置放入启用 HTTPS 的 `server` 块：

```nginx
location /franchise-demo/ {
    root /srv/tianli;
    index index.html;
    try_files $uri $uri/ /franchise-demo/index.html;
    add_header Cache-Control "public, max-age=300";
}

location ~* ^/franchise-demo/.*\.(js|css|png|jpg|jpeg|svg|woff|woff2|ttf)$ {
    root /srv/tianli;
    expires 7d;
    add_header Cache-Control "public, immutable";
}
```

应用使用 hash 路由，因此消费者、门店端和品牌方后台都由同一个 `index.html` 承载。构建后的 JS、CSS 和图片使用相对地址，可以部署在该子目录中。

检查并重新加载配置：

```bash
sudo nginx -t
sudo systemctl reload nginx
```

## 访问地址

消费者默认入口：

```text
https://demo.example.com/franchise-demo/#/c/store/TL1001
```

直接演示入口：

```text
https://demo.example.com/franchise-demo/#/store/dashboard
https://demo.example.com/franchise-demo/#/brand/dashboard
```

二维码会根据当前部署地址生成。正式演示前应在 HTTPS 地址重新打开消费者端并生成二维码，不要使用本地 `127.0.0.1` 地址生成的旧码。

## 上线检查

1. 消费者首页、门店端和品牌方后台均能加载，页面无横向溢出或空白主区域。
2. 浏览器网络面板中不存在 `/api`、`8011` 或其他跨域业务请求。
3. 下单、模拟支付、预约、核销、门店指标、品牌分账使用同一份本地状态。
4. 手机在 HTTPS 地址允许摄像头权限后能够扫码；拒绝权限后仍能使用图片和备用编号。
5. 重置演示数据后返回 `#/c/store/TL1001`，订单、预约和分账恢复为固定示例数据。

## 构建体积基线

2026-07-30 的生产构建主包约为 954 KB（gzip 约 269 KB）。经营分析的 ECharts 约为 495 KB（gzip 约 169 KB），扫码模块约为 346 KB（gzip 约 104 KB），两者均按路由延迟加载。Vite 会提示主包超过 500 KB；对本次静态演示不构成功能阻塞，后续如需面向公网长期运营，再按实际首屏指标拆分 Element UI。
