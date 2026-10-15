'use strict';

// Ordinary saved DTO / component state and official source compilers only. No App, auth actors or HTTP.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const cp = require('node:child_process');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../../..');
const modules = process.env.UNI_CHECK_MODULES ||
  'H:/天俪商城/artifacts/ms3-storefront-h5-build/source477732-r1/toolchain/plugins/uniapp-cli/node_modules';
const sassModule = process.env.UNI_SASS_MODULES ||
  'H:/天俪商城/artifacts/ms3-storefront-h5-build/source477732-r1/toolchain/plugins/compile-dart-sass/node_modules/sass';
const compiler = require(path.join(modules, 'vue-template-compiler'));
const Vue = require(path.join(modules, 'vue'));
const parser = require(path.join(modules, '@babel/parser'));
const preprocess = require(path.join(modules, '@dcloudio/vue-cli-plugin-uni/packages/webpack-preprocess-loader/preprocess/lib/preprocess'));
const contexts = require(path.join(modules, '@dcloudio/uni-cli-shared/lib/preprocess'));
const sass = require(sassModule);
const files = {
  platform: 'template/admin/src/pages/order/orderList/handle/orderDetails.vue',
  order: 'template/uni-app/pages/admin/orderDetail/index.vue',
  refund: 'template/uni-app/pages/admin/refund_order_detail/index.vue',
  form: 'template/uni-app/pages/admin/components/customForm/index.vue'
};
const compiled = [], components = {}, asts = {}, sources = {}, templates = {};
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const globals = fs.readFileSync(path.join(root, 'template/uni-app/uni.scss'), 'utf8');
for (const [name, relative] of Object.entries(files)) {
  const file = path.join(root, relative), raw = fs.readFileSync(file, 'utf8');
  assert.equal(raw.includes('\r'), false);
  sources[name] = {path: relative, bytes: Buffer.byteLength(raw), sha256: sha(raw)};
  for (const platform of name === 'platform' ? ['h5'] : ['h5', 'mp-weixin', 'app-plus']) {
    const context = contexts(platform, ['h5', 'app-plus', 'mp-weixin']).vueContext;
    const sfc = compiler.parseComponent(raw);
    const template = preprocess.preprocess(sfc.template.content, context, {type: 'html'});
    const script = preprocess.preprocess(sfc.script.content, context, {type: 'js'});
    const result = compiler.compile(template, {outputSourceRange: true});
    assert.deepEqual(result.errors, [], relative + ' template');
    assert.deepEqual(result.tips, [], relative + ' template tips');
    const syntax = cp.spawnSync(process.execPath, ['--check', '--input-type=module'], {input: script, encoding: 'utf8'});
    assert.equal(syntax.status, 0, syntax.stderr);
    const ast = parser.parse(script, {sourceType: 'module'});
    const exported = ast.program.body.find(node => node.type === 'ExportDefaultDeclaration');
    const methods = exported.declaration.properties.find(node => node.key.name === 'methods');
    const names = methods ? methods.value.properties.map(node => node.key.name || node.key.value) : [];
    assert.equal(new Set(names).size, names.length);
    let cssBytes = 0;
    for (const style of sfc.styles) cssBytes += sass.renderSync({
      data: globals + '\n' + preprocess.preprocess(style.content, context, {type: 'css'}),
      logger: {warn() {}, debug() {}}
    }).css.length;
    compiled.push({file: relative, platform: name === 'platform' ? 'platform-admin-vue' : platform,
      templateErrors: 0, templateTips: 0, jsExit: syntax.status, cssBytes, duplicateMethods: 0});
    if (platform !== 'h5') continue;
    const sandbox = {require: value => value}; // Asset path only; no asset/module or API is executed.
    const replacements = [];
    for (const statement of ast.program.body) {
      if (statement.type !== 'ImportDeclaration') continue;
      for (const specifier of statement.specifiers) sandbox[specifier.local.name] = () => {
        throw new Error('API/import execution outside ordinary saved component scope');
      };
      replacements.push({start: statement.start, end: statement.end, value: ''});
    }
    replacements.push({start: exported.start, end: exported.declaration.start, value: 'globalThis.component = '});
    let evaluated = script;
    replacements.sort((a,b) => b.start-a.start).forEach(row => {
      evaluated = evaluated.slice(0,row.start)+row.value+evaluated.slice(row.end);
    });
    vm.createContext(sandbox);new vm.Script(evaluated,{filename:file}).runInContext(sandbox);
    components[name] = sandbox.component;asts[name] = result.ast;templates[name] = template.trim();
  }
}
let assertions = 0;
const pending = [];
const ordinary = (actual,expected,label) => {
  assert.deepEqual(JSON.parse(JSON.stringify(actual)),expected,label);++assertions;
};
const saved = {marketplace_trade_version: 1, custom_form: [
  {product_id:17,product_name:'保存商品甲',definition_sha256:'a'.repeat(64),fields:[
    {field_index:0,title:'同名资料',label:'text',status:true,value:'原答案\n第二行'},
    {field_index:1,title:'同名资料',label:'number',status:false,value:'0'},
    {field_index:2,title:'图片',label:'img',status:false,value:['saved-one.png','saved-two.png']}]},
  {product_id:19,product_name:'保存商品乙',definition_sha256:'b'.repeat(64),fields:[
    {field_index:0,title:'同名资料',label:'text',status:false,value:''},
    {field_index:1,title:'同名资料',label:'text',status:false,value:'另一原答案'}]}
]};
const original = JSON.stringify(saved);
const form = new Vue({props:components.form.props,propsData:{tradeVersion:1,customForm:saved.custom_form},
  data:components.form.data,computed:components.form.computed,methods:components.form.methods});
const platform = new Vue({data:()=>({orderDatalist:{orderInfo:saved}}),computed:components.platform.computed});
for (const page of [form,platform]) {
  ordinary(page.savedMarketplaceV1,true,'Saved version selects grouped read-only output');
  ordinary(page.savedGroups.map(group=>group.product_name),['保存商品甲','保存商品乙'],'Saved product groups remain distinct');
  ordinary(page.savedGroups[0].fields.map(field=>field.title),['同名资料','同名资料','图片'],'Duplicate titles remain separate fields');
  ordinary(page.savedGroups[0].fields[1].value,'0','Optional string zero is retained');
  ordinary(page.savedGroups[1].fields.map(field=>field.field_index),[1],'Empty answers do not renumber saved positions');
  ordinary(page.savedGroups.map(group=>group.definition_sha256),['a'.repeat(64),'b'.repeat(64)],'Original definition hashes remain unchanged');
  ordinary(page.savedGroups[0].fields[2].value,['saved-one.png','saved-two.png'],'Saved image order is retained');
  ordinary(page.savedGroups[0].fields[2].value===saved.custom_form[0].fields[2].value,false,'Display image arrays do not alias saved data');
}
ordinary(JSON.stringify(saved),original,'Read-only display leaves saved DTO bytes unchanged');
for (const version of ['1',0,'0',null]) {
  form.tradeVersion=version;platform.orderDatalist={orderInfo:{...saved,marketplace_trade_version:version}};
  ordinary(form.savedMarketplaceV1,version==='1','String and legacy versions choose the intended component branch');
  ordinary(platform.savedMarketplaceV1,version==='1','Platform uses the saved version rather than group shape');
}
const legacyDIY=[{name:'text',titleConfig:{value:'历史字段'},value:'历史答案'}];
form.tradeVersion=0;form.customForm=legacyDIY;components.form.watch.customForm.call(form,legacyDIY);
ordinary(form.isShow,1,'Historical DIY watcher remains active');
ordinary(form.customForm,legacyDIY,'Historical DIY schema is not flattened or converted');
ordinary(form.savedGroups,[],'Historical DIY values never enter grouped V1 rendering');
const nodes = ast => {
  const out=[];const visit=node=>{if(!node||node.type!==1)return;out.push(node);(node.children||[]).forEach(visit);
    (node.ifConditions||[]).slice(1).forEach(condition=>visit(condition.block));};visit(ast);return out;
};
const condition = (expression,context) => Function('context','with(context){return Boolean('+expression+');}')(context);
for (const name of ['order','refund']) {
  const component=components[name];
  const page=new Vue({data:()=>({orderInfo:saved,userInfo:{},giveData:{},goname:'',confirmShow:true,change:false,order:false,status:''}),
    computed:component.computed,methods:component.methods});
  ordinary(page.savedMarketplaceV1,true,'Mobile V1 uses the customer saved DTO');
  page.getUserInfo();ordinary(page.userInfo,{},'V1 performs no cross-UID user-info import/API call');
  page.applyOrderBenefits(saved);
  ordinary(page.giveData,{give_coupon:[],give_integral:0},'V1 resets the hidden legacy benefit widget without requiring unsupported DTO fields');
  const elements=nodes(asts[name]);
  for (const fragment of ['footer','remarks','user-box','giveGoods']) {
    const node=elements.find(row=>(row.attrsMap.class||'').split(/\s+/).includes(fragment));
    assert.ok(node&&node.if,fragment+' must have a compiled conditional');
    ordinary(condition(node.if,page),false,'Saved mobile '+fragment+' is not an active legacy management surface');
  }
  const price=elements.find(row=>row.tag==='PriceChange');assert.ok(price&&price.if);
  ordinary(condition(price.if,page),false,'Saved mobile order does not mount the legacy write dialog');
  const display=elements.find(row=>row.tag==='customForm');
  ordinary(display.attrsMap[':tradeVersion'],'orderInfo.marketplace_trade_version','Saved version is passed to the actual form component');
  const readOnlyMethods=['goDelivery','more','modify','objOrderRefund','savePrice','offlinePay'];
  if(name==='order')readOnlyMethods.push('verify');
  for(const method of readOnlyMethods){
    const result=page[method]({price:'12.34',remark:'保存备注'});
    if(result&&typeof result.then==='function')pending.push(Promise.resolve(result));
    ordinary([page.change,page.order,page.status],[false,false,''],'Saved read-only '+method+' leaves management state unchanged');
  }
  page.orderInfo={marketplace_trade_version:0};ordinary(page.savedMarketplaceV1,false,'Legacy mobile mode remains unchanged');
  const oldBenefits={marketplace_trade_version:0,give_coupon:[{coupon_title:'历史赠券'}],give_integral:3};
  page.applyOrderBenefits(oldBenefits);
  ordinary(page.giveData,{give_coupon:oldBenefits.give_coupon,give_integral:3},'Historical benefit values retain their old producer contract');
}
// Compile and render the real two amount rows as VNodes; no mount, DOM, imports or transport.
const amountRows=nodes(asts.order).filter(row=>row.tag==='view' &&
  (row.children||[]).some(child=>child.type===1&&(child.children||[]).some(text=>
    text.type===2 && /商品金额|会员优惠（已计入商品金额）/.test(text.text))));
assert.equal(amountRows.length,2);
const amountTemplate='<view>'+amountRows.map(row=>templates.order.slice(row.start,row.end)).join('')+'</view>';
const amountCompiled=compiler.compile(amountTemplate);
assert.deepEqual(amountCompiled.errors,[]);assert.deepEqual(amountCompiled.tips,[]);
const amountRender=new Function(amountCompiled.render);
const amountStatics=amountCompiled.staticRenderFns.map(source=>new Function(source));
const vnodeTexts=node=>node.text ? (node.text.trim()?[node.text.trim()]:[]) : (node.children||[]).flatMap(vnodeTexts);
const displayAmounts=(orderInfo,statusType='')=>{
  const page=new Vue({data:()=>({orderInfo,statusType}),computed:components.order.computed,
    render:amountRender,staticRenderFns:amountStatics});
  return vnodeTexts(page._render());
};
for(const version of [1,'1']){
  const amount={marketplace_trade_version:version,total_price:'24.68',vip_true_price:'2.46'};
  const before=JSON.stringify(amount);
  ordinary(displayAmounts(amount),['商品金额','￥24.68','会员优惠（已计入商品金额）','-￥2.46'],
    'Saved V1 transaction amount and included membership saving retain their original strings');
  ordinary(JSON.stringify(amount),before,'Read-only membership display does not alter saved amounts');
}
ordinary(displayAmounts({marketplace_trade_version:1,total_price:'9999999999.99',vip_true_price:'0.01'}),
  ['商品金额','￥9999999999.99','会员优惠（已计入商品金额）','-￥0.01'],
  'Maximum saved money remains exact and the membership saving is never added back');
ordinary(displayAmounts({marketplace_trade_version:1,total_price:'24.68',vip_true_price:'0.00'}),
  ['商品金额','￥24.68'],'An ordinary zero membership saving leaves the amount visible without an empty discount row');
ordinary(displayAmounts({marketplace_trade_version:0,total_price:'24.68',vip_true_price:'2.46'}),
  ['商品总价','￥27.14','会员商品优惠','-￥2.46'],'Historical amount and membership formulas retain their previous output');
ordinary(displayAmounts({marketplace_trade_version:0,total_price:'24.68',vip_true_price:'2.46'},-3),
  ['商品总价','￥24.68','会员商品优惠','-￥2.46'],'Historical refund amount keeps its existing direct-price branch');
const brokerage=nodes(asts.platform).find(row=>row.attrsMap.class==='section'&&
  (row.children||[]).some(child=>child.type===1&&(child.children||[]).some(text=>text.text==='返佣信息(金额｜用户UID)')));
assert.ok(brokerage&&brokerage.if);
ordinary(condition(brokerage.if,{savedMarketplaceV1:true}),false,'Unsupported V1 brokerage is hidden');
ordinary(condition(brokerage.if,{savedMarketplaceV1:false}),true,'Historical brokerage section is preserved');
const result={schema:1,scope:'ORDINARY_STAFF_SAVED_DETAIL_SOURCE_STATE_ONLY',node:process.version,
  files:sources,checks:compiled,ordinaryAssertions:assertions,actualVueComputedAndMethods:true,
  vueCompiler:require(path.join(modules,'vue-template-compiler/package.json')).version,
  savedDefinitionRehashed:false,currentCatalogQueried:false,editableFormHelperUsed:false,
  DOMCreated:false,applicationStarted:false,sqlExecuted:false,HTTPExecuted:false,authenticationActorsCreated:false,
  browserVisualAcceptance:false,actualRoleOrDetailAPIValidated:false,listsProjected:false,
  negativeConflictConcurrencyFaultRecoverySamplesRun:false};
Promise.all(pending).then(()=>{
  if(process.argv[2])fs.writeFileSync(process.argv[2],JSON.stringify(result,null,2)+'\n',{flag:'wx'});
  console.log(JSON.stringify(result));
}).catch(error=>{console.error(error);process.exitCode=1;});
