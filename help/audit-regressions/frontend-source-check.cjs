'use strict';

const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
// 这些 vendor 文件是项目源码，不是可用 npm install 重建的依赖目录。
const requiredSources = ['Blob.js', 'Export2Excel.js', 'newToExcel.js'];
const missing = requiredSources.filter((name) => {
  const source = path.join(root, 'template/admin/src/vendor', name);
  return !fs.existsSync(source) || !fs.statSync(source).isFile() || fs.statSync(source).size === 0;
});

if (missing.length) {
  console.error(`后台必需源码缺失：${missing.join(', ')}。请从完整源码恢复 template/admin/src/vendor；npm install 无法补齐。`);
  process.exitCode = 1;
} else {
  console.log('后台必需 vendor 源码完整（3/3）。');
}
