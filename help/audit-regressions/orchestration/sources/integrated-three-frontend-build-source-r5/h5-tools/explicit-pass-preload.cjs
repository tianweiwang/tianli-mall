'use strict';

// External build-tool adapter only. The original compiler owns emit and exit.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const cliRoot = '/hbx/plugins/uniapp-cli';
const compilerRoot = cliRoot + '/node_modules';
const optimizeRoot = compilerRoot + '/@dcloudio/vue-cli-plugin-uni-optimize';
const receiptFile = '/evidence/adapter-receipt.json';
const generatedNames = ['api.js', 'app-components.js', 'app-mixins.js', 'components.js',
  'invoke-api.js', 'protocol.js', 'subscribe.js', 'system-routes.js'];
const guards = [
  ['node_modules/webpack/lib/webpack.js', '28e13ed959fdaa32f90b2bbc9ce5da674dd6921dd09306636627a08eddaebb1f'],
  ['node_modules/webpack/lib/Watching.js', 'bd75d383e25a0fb7265c2d9b111140b91d799f5811ec1ae8201d10a1336634c9'],
  ['node_modules/webpack/lib/MultiWatching.js', 'b4be56d942027521b155827de5fa60597c88ec1b92ebef55ddabfa82a842b79e'],
  ['node_modules/@dcloudio/vue-cli-plugin-uni-optimize/packages/webpack-optimize-plugin/index.js',
    'f8fedd62267eb0f33819ac96ea0bda4dcfe33c66e0220720e2b7b0462f42a5b4'],
  ['node_modules/webpack/lib/Compiler.js', '06acae4203d59213995d9eba33b859a857adfdf002db7ded51565a384d7b72e9'],
  ['node_modules/webpack/lib/MultiCompiler.js', '51a1d0f8fb2b1f1ddda9bf37f00eafa6d670b0bd93ee6f7820405bb09ae1e7f6'],
  ['node_modules/@dcloudio/vue-cli-plugin-uni/commands/build.js', 'cfd78f2b0f9618795501f820ab5026a61f7d9c2f93f60e56af518777d709853c'],
  ['bin/uniapp-cli.js', '0c71853cb91effbeab019c4bb05660fd171764e84c42ee7046ee28bab0e4320c'],
];
const receipt = { schema: 1, status: 'PRELOADED', source_guards: [], factory_count: 0,
  passes: [], after_emit_passes: [], after_emit_inventories: [], callback_count: 0, first_callback_success: false,
  generated_modules: [], purged_module_count: 0, explicit_invalidations: 0,
  phase_counts: { watchRun: 0, beforeCompile: 0, afterCompile: 0, emit: 0, afterEmit: 0, failed: 0, invalid: 0 },
  last_phase: 'preload', callback_observations: [], error_observations: [],
  failure_close_requested: false, failure_close_callback: false, failure_close_error_present: false,
  failure_watchers_closed: false, native_failure_exit_requested: false };
const sha = (value) => crypto.createHash('sha256').update(value).digest('hex');
function persist() {
  fs.writeFileSync(receiptFile + '.tmp', JSON.stringify(receipt) + '\n', { mode: 0o600 });
  fs.renameSync(receiptFile + '.tmp', receiptFile);
}
function expect(value, code) {
  if (value) return;
  receipt.status = 'ADAPTER_FAILED'; receipt.failure_code = code; persist();
  throw new Error('UNI_EXPLICIT_PASS_' + code);
}
function publicError(error) {
  const label = (value) => typeof value === 'string' && /^[A-Za-z0-9_.+-]{1,64}$/.test(value) ? value : null;
  const value = error && typeof error.path === 'string' ? error.path : null;
  let location = null;
  if (value !== null) {
    const roots = [['optimize_tmpfs', optimizeRoot + '/.tmp'], ['compiler_tmpfs', compilerRoot + '/.cache'],
      ['temporary_tmpfs', '/tmp'], ['input', '/input'], ['output', '/output'], ['compiler', cliRoot],
      ['compiler_stylus', '/hbx/plugins/compile-stylus'], ['compiler_sass', '/hbx/plugins/compile-dart-sass'],
      ['compiler_about', '/hbx/plugins/about'], ['compiler_helpers', '/hbx/plugins/uni_helpers']];
    const known = roots.find(([, root]) => value.startsWith(root + '/'));
    const relative = known ? value.slice(known[1].length + 1) : '';
    if (known && /^[\p{L}\p{N}._@/+ -]+$/u.test(relative) &&
      !relative.split('/').some((part) => part === '.' || part === '..' || part === '')) {
      location = { kind: 'known', root: known[0], relative };
    } else location = { kind: 'unknown', sha256: sha(value) };
  }
  // Deliberately never access message, stack, environment or nested causes.
  return { name: label(error && error.name), code: label(error && error.code),
    errno: error && Number.isSafeInteger(error.errno) ? error.errno : null,
    syscall: label(error && error.syscall), path: location };
}
function outputInventory() {
  const rows = [];
  function visit(directory, relative) {
    for (const name of fs.readdirSync(directory).sort()) {
      const file = path.join(directory, name), part = relative ? relative + '/' + name : name;
      const stat = fs.lstatSync(file);
      expect(!stat.isSymbolicLink(), 'OUTPUT_REGULAR_FILES');
      if (stat.isDirectory()) visit(file, part);
      else {
        expect(stat.isFile(), 'OUTPUT_REGULAR_FILES');
        const bytes = fs.readFileSync(file);
        rows.push({ path: part, bytes: bytes.length, sha256: sha(bytes) });
      }
    }
  }
  visit('/output', '');
  rows.sort((a, b) => a.path < b.path ? -1 : (a.path > b.path ? 1 : 0));
  return { file_count: rows.length, bytes: rows.reduce((sum, row) => sum + row.bytes, 0),
    manifest_sha256: sha(rows.map((row) => row.path + '\0' + row.bytes + '\0' + row.sha256 + '\n').join('')) };
}
for (const [relative, expected] of guards) {
  const actual = sha(fs.readFileSync(cliRoot + '/' + relative));
  expect(actual === expected, 'COMPILER_SOURCE_CHANGED');
  receipt.source_guards.push({ path: relative, sha256: actual });
}
const webpackKey = require.resolve(compilerRoot + '/webpack');
const originalWebpack = require(webpackKey);
const OptimizePlugin = require(optimizeRoot + '/packages/webpack-optimize-plugin');
expect(originalWebpack.version === '4.46.0', 'WEBPACK_VERSION');
persist();

// A sole apply trap forwards all properties, getters, descriptors and prototypes.
require.cache[webpackKey].exports = new Proxy(originalWebpack, {
  apply(target, thisArg, args) {
    const [configs, callback] = args;
    if (process.env.NODE_ENV !== 'production' || process.env.UNI_PLATFORM !== 'h5' ||
      !process.env.UNI_OPT_TREESHAKINGNG) return Reflect.apply(target, thisArg, args);
    expect(args.length === 2 && Array.isArray(configs) && configs.length === 1 &&
      configs[0] && configs[0].mode === 'production' && configs[0].watch === true &&
      Array.isArray(configs[0].plugins) &&
      configs[0].plugins.filter((plugin) => plugin instanceof OptimizePlugin).length === 1 &&
      typeof callback === 'function' && receipt.factory_count === 0, 'SINGLE_H5_OPTIMIZE_CONFIG');
    receipt.factory_count++; receipt.status = 'FACTORY_MATCHED'; persist();
    let watching, compiler, multiWatching, firstCompilation, scheduled = false, lastFailedError;
    const callArgs = args.slice();
    callArgs[1] = function (error, stats) {
      receipt.callback_count++;
      const childStats = stats && Array.isArray(stats.stats) && stats.stats.length === 1 ? stats.stats[0] : null;
      const ready = !error && childStats && childStats.compilation === firstCompilation &&
        !childStats.hasErrors() && receipt.passes.length === 1 && !receipt.passes[0].has_errors;
      if (receipt.callback_count === 1) receipt.first_callback_success = Boolean(ready);
      const failedSameError = Boolean(error && lastFailedError === error && receipt.phase_counts.failed > 0);
      receipt.callback_observations.push({ index: receipt.callback_count, error_present: Boolean(error),
        has_stats: Boolean(stats), has_child_stats: Boolean(childStats), known_first_compilation: Boolean(childStats && childStats.compilation === firstCompilation),
        observed_done_count: receipt.passes.length, phase: receipt.last_phase, failed_same_error: failedSameError });
      if (error) {
        receipt.error_observations.push({ source: 'callback', index: receipt.callback_count, phase: receipt.last_phase, error: publicError(error) });
        receipt.status = 'CALLBACK_ERROR_OBSERVED';
      }
      persist();
      const returned = Reflect.apply(callback, this, arguments);
      if (failedSameError && !receipt.failure_close_requested) {
        receipt.failure_close_requested = true; receipt.status = 'FAILURE_CLOSE_REQUESTED'; persist();
        multiWatching.close((closeError) => {
          receipt.failure_close_callback = true; receipt.failure_close_error_present = Boolean(closeError);
          receipt.failure_watchers_closed = !closeError && watching.closed === true && compiler.running === false && multiWatching.compiler.running === false;
          if (closeError) receipt.error_observations.push({ source: 'close', index: receipt.callback_count, phase: receipt.last_phase, error: publicError(closeError) });
          receipt.native_failure_exit_requested = true; receipt.status = 'FAILURE_CLOSE_CALLBACK';
          process.exitCode = 1; persist();
        });
      }
      // Watching invokes the real callback after every AsyncSeries done hook.
      if (ready && !scheduled) {
        scheduled = true;
        setImmediate(() => {
          if (receipt.passes.length !== 1 || watching.closed || watching.running) return;
          const directory = path.join(optimizeRoot, '.tmp');
          expect(fs.readdirSync(directory).sort().join(',') === generatedNames.join(','), 'GENERATED_MODULE_SET');
          const files = generatedNames.map((name) => path.join(directory, name));
          receipt.generated_modules = files.map((file, index) => {
            expect(fs.lstatSync(file).isFile(), 'GENERATED_MODULE_FILE');
            const bytes = fs.readFileSync(file);
            return { name: generatedNames[index], bytes: bytes.length, sha256: sha(bytes) };
          });
          expect(typeof compiler.inputFileSystem.purge === 'function' &&
            receipt.explicit_invalidations === 0, 'PUBLIC_INVALIDATION_API');
          compiler.inputFileSystem.purge(files);
          receipt.purged_module_count = files.length;
          receipt.explicit_invalidations++; receipt.status = 'SECOND_PASS_REQUESTED'; persist();
          watching.invalidate();
        });
      }
      return returned;
    };
    const returned = Reflect.apply(target, thisArg, callArgs);
    expect(returned && Array.isArray(returned.watchings) && returned.watchings.length === 1,
      'REAL_SINGLE_MULTI_WATCHING');
    multiWatching = returned; watching = returned.watchings[0]; compiler = watching.compiler;
    expect(compiler && typeof watching.invalidate === 'function' &&
      compiler.hooks.done.taps.some((tap) => tap.name === 'WebpackOptimizePlugin'), 'ORIGINAL_OPTIMIZE_HOOK');
    for (const name of ['watchRun', 'beforeCompile', 'afterCompile', 'emit', 'failed', 'invalid']) {
      compiler.hooks[name].tap({ name: 'ExternalUniFinitePhaseObservation', stage: -100000 }, (error) => {
        const prior = receipt.last_phase; receipt.phase_counts[name]++; receipt.last_phase = name;
        if (name === 'failed') {
          lastFailedError = error;
          receipt.error_observations.push({ source: 'failed', index: receipt.phase_counts.failed, phase: prior, error: publicError(error) });
          receipt.status = 'COMPILER_ERROR_OBSERVED';
        }
        persist();
      });
    }
    // Its original beforeCompile promise keeps compilation asynchronous; tap
    // before its done hook so second-pass metadata survives its own exit(0).
    compiler.hooks.done.tap({ name: 'ExternalUniExplicitPassObservation', before: 'WebpackOptimizePlugin' }, (stats) => {
      const assets = Object.keys(stats.compilation.assets).sort();
      const pass = receipt.passes.length + 1;
      if (pass === 1) firstCompilation = stats.compilation;
      receipt.passes.push({ pass, has_errors: stats.hasErrors(), has_warnings: stats.hasWarnings(),
        asset_count: assets.length, asset_names_sha256: sha(assets.join('\n')) });
      // done follows completion of every afterEmit hook. Observe final bytes
      // here, still before the unmodified optimize plugin's own second exit.
      if (receipt.after_emit_passes.includes(pass)) receipt.after_emit_inventories.push({ pass, ...outputInventory() });
      receipt.status = pass === 1 ? 'FIRST_PASS_OBSERVED' : 'SECOND_PASS_OBSERVED'; persist();
    });
    compiler.hooks.afterEmit.tap('ExternalUniExplicitPassObservation', () => {
      receipt.phase_counts.afterEmit++; receipt.last_phase = 'afterEmit';
      const pass = receipt.passes.length + 1;
      receipt.after_emit_passes.push(pass);
      persist();
    });
    return returned;
  },
});
