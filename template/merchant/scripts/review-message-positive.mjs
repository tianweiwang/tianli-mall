// Pure message-display replay; no HTTP, credentials, storage or business execution.
import assert from 'node:assert/strict';
import { errorText as customerErrorText } from '../../uni-app/utils/marketplace-review.js';
import { errorText as adminErrorText } from '../../admin/src/libs/marketplace-review.js';
import { errorText as merchantErrorText } from '../src/domain/review.js';

// Only the public status/msg fields observed in the existing response are replayed.
const observedEnvelope = Object.freeze({ status: 503, msg: '认证服务暂不可用' });
const copies = [customerErrorText, adminErrorText, merchantErrorText];
for (const errorText of copies) {
  assert.equal(errorText(observedEnvelope), observedEnvelope.msg);
  assert.equal(errorText(new Error('原错误说明')), '原错误说明');
  assert.equal(errorText('原字符串提示'), '原字符串提示');
  assert.equal(errorText({ message: '原 message 优先', msg: '接口 msg' }), '原 message 优先');
}
process.stdout.write(JSON.stringify({ pure_message_display_checks: 12, app_local_copies: copies.length,
  HTTP_or_credentials_or_storage_or_business: false, backend_503_resolved: false }) + '\n');
