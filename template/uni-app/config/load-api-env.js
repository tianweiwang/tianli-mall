// 仅构建阶段使用。HBuilderX 的 Vue CLI context 指向编译器目录，需补读项目配置。
const fs = require('fs');
const path = require('path');

module.exports = function loadApiEnv(projectDir, env = process.env) {
  if (env.VUE_APP_API_URL !== undefined) return;
  const files = [`.env.${env.NODE_ENV}.local`, `.env.${env.NODE_ENV}`, '.env.local', '.env'];
  for (const file of files) {
    const filename = path.join(projectDir, file);
    if (!fs.existsSync(filename)) continue;
    // dotenv 是 Vue CLI 的现有依赖，兼容 HBuilderX 与 npm CLI 两种目录布局。
    const dotenv = require(require.resolve('dotenv', {
      paths: [projectDir, env.UNI_CLI_CONTEXT || env.VUE_CLI_CONTEXT || process.cwd()],
    }));
    const parsed = dotenv.parse(fs.readFileSync(filename));
    if (Object.prototype.hasOwnProperty.call(parsed, 'VUE_APP_API_URL')) {
      env.VUE_APP_API_URL = parsed.VUE_APP_API_URL;
      return;
    }
  }
};
