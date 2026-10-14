// Pure decimal and Cartesian composition checks; no API fixture, runtime DTO or claimed database identity.
import assert from 'node:assert/strict';
import { decimal, generateCombinations, blankProduct } from '../src/domain/product.js';
import { blankFreight, cityOptions } from '../src/domain/freight.js';
assert.equal(decimal('0'), '0.00');
assert.equal(decimal('10.2'), '10.20');
assert.equal(decimal('9999999999.99'), '9999999999.99');
assert.deepEqual(generateCombinations([{ detail: [{ value: '白' }, { value: '黑' }] }, { detail: [{ value: '小' }, { value: '大' }] }]), [['白', '小'], ['白', '大'], ['黑', '小'], ['黑', '大']]);
assert.equal(Object.hasOwn(blankProduct().attrs[0], 'id'), false);
assert.equal(Object.hasOwn(blankProduct().attrs[0], 'unique'), false);
assert.equal(Array.isArray(blankFreight().region_info[0].region), false);
assert.deepEqual(blankFreight().region_info[0].region, { city_id: 0, name: '默认全国' });
assert.deepEqual(cityOptions([]), []);
process.stdout.write(JSON.stringify({ pure_positive_checks: 9, API_or_credential_or_database_fixture: false }) + '\n');
