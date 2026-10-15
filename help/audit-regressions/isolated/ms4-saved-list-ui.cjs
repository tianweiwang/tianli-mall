'use strict';

// Ordinary saved DTOs, Vue VNodes/component state and official source compilers only.
// No App, HTTP, authentication actors, SQL, DOM or stopped samples are run.
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const cp=require('node:child_process'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../../..');
const modules=process.env.UNI_CHECK_MODULES || 'H:/天俪商城/artifacts/ms3-storefront-h5-build/source477732-r1/toolchain/plugins/uniapp-cli/node_modules';
const compiler=require(modules+'/vue-template-compiler'),Vue=require(modules+'/vue'),parser=require(modules+'/@babel/parser');
const preprocess=require(modules+'/@dcloudio/vue-cli-plugin-uni/packages/webpack-preprocess-loader/preprocess/lib/preprocess');
const contexts=require(modules+'/@dcloudio/uni-cli-shared/lib/preprocess');
const sass=require(process.env.UNI_SASS_MODULES || 'H:/天俪商城/artifacts/ms3-storefront-h5-build/source477732-r1/toolchain/plugins/compile-dart-sass/node_modules/sass');
const files={customerOrder:'template/uni-app/pages/goods/order_list/index.vue',
  customerRefund:'template/uni-app/pages/users/user_return_list/index.vue',
  platformOrder:'template/admin/src/pages/order/orderList/components/tableList.vue',
  platformRefund:'template/admin/src/pages/order/refund/index.vue',
  mobileOrder:'template/uni-app/pages/admin/orderList/index.vue',
  mobileRefund:'template/uni-app/pages/admin/refund_order_list/index.vue'};
const globals=fs.readFileSync(path.join(root,'template/uni-app/uni.scss'),'utf8');
const compiled=[],fingerprints={},components={},renderers={},methodSources={};
let assertions=0,navigation;
const ordinary=(actual,expected,label)=>{assert.deepEqual(JSON.parse(JSON.stringify(actual)),expected,label);assertions++;};
for(const [name,relative] of Object.entries(files)){
  const raw=fs.readFileSync(path.join(root,relative),'utf8');assert.equal(raw.includes('\r'),false);
  fingerprints[name]={path:relative,bytes:Buffer.byteLength(raw),sha256:crypto.createHash('sha256').update(raw).digest('hex')};
  for(const platform of name.startsWith('platform')?['h5']:['h5','mp-weixin','app-plus']){
    const context=contexts(platform,['h5','app-plus','mp-weixin']).vueContext,sfc=compiler.parseComponent(raw);
    const template=preprocess.preprocess(sfc.template.content,context,{type:'html'});
    const script=preprocess.preprocess(sfc.script.content,context,{type:'js'});
    const result=compiler.compile(template,{outputSourceRange:true});
    assert.deepEqual(result.errors,[],relative+' template');assert.deepEqual(result.tips,[],relative+' tips');
    const syntax=cp.spawnSync(process.execPath,['--check','--input-type=module'],{input:script,encoding:'utf8'});
    assert.equal(syntax.status,0,syntax.stderr);
    const ast=parser.parse(script,{sourceType:'module'}),exported=ast.program.body.find(node=>node.type==='ExportDefaultDeclaration');
    const methods=exported.declaration.properties.find(node=>node.key.name==='methods').value.properties.filter(node=>node.type!=='SpreadElement');
    const names=methods.map(node=>node.key.name||node.key.value);
    assert.equal(new Set(names).size,names.length,relative+' duplicate methods: '+names.filter((name,index)=>names.indexOf(name)!==index).join(','));
    let cssBytes=0;
    for(const style of sfc.styles)cssBytes+=sass.renderSync({data:globals+'\n'+preprocess.preprocess(style.content,context,{type:'css'}),
      includePaths:[path.dirname(path.join(root,relative)),path.join(root,'template/uni-app')],logger:{warn(){},debug(){}},
      importer(url){return url.startsWith('@/')?{file:path.join(root,'template/uni-app',url.slice(2))}:null;}}).css.length;
    compiled.push({file:relative,platform:name.startsWith('platform')?'platform-admin-vue':platform,
      templateErrors:0,templateTips:0,jsExit:0,cssBytes,duplicateMethods:0});
    if(platform!=='h5')continue;
    const local={require:value=>value,uni:{navigateTo(value){navigation=value.url;}},
      mapState:()=>({}),mapMutations:()=>({}),mapGetters:()=>({})},edits=[];
    for(const node of ast.program.body)if(node.type==='ImportDeclaration'){
      for(const spec of node.specifiers)local[spec.local.name]=()=>{throw new Error('No application/API imports are executed');};
      edits.push({start:node.start,end:node.end,value:''});
    }
    local.mapState=local.mapMutations=local.mapGetters=()=>({});local.Setting={apiBaseURL:''};local.timeOptions={};
    edits.push({start:exported.start,end:exported.declaration.start,value:'globalThis.component = '});
    let evaluated=script;edits.sort((a,b)=>b.start-a.start).forEach(edit=>{evaluated=evaluated.slice(0,edit.start)+edit.value+evaluated.slice(edit.end);});
    vm.createContext(local);new vm.Script(evaluated,{filename:relative}).runInContext(local);
    components[name]=local.component;renderers[name]={render:new Function(result.render),staticRenderFns:result.staticRenderFns.map(code=>new Function(code))};
    methodSources[name]=Object.fromEntries(methods.map(node=>[node.key.name,script.slice(node.start,node.end)]));
  }
}
// Codec examples, not runtime confirmations, database orders or identity actors.
const cart={id:'9007199254740995',product_id:17,cart_num:2,refund_num:0,truePrice:'12345.10',
  productInfo:{id:17,image:'/saved.png',store_name:'保存商品',price:'15000.00',attrInfo:{image:'/saved-sku.png',suk:'原规格',price:'15000.00'}}};
const saved={marketplace_trade_version:1,id:17,order_id:'9007199254740993',store_order_id:19,
  store_order_order_id:'9007199254740991',store_order_status:'未发货',type:0,status:0,paid:0,
  is_cancel:0,is_del:0,is_gift:0,shipping_type:1,total_num:2,pay_price:'24690.20',total_price:'24690.20',
  refund_price:'24690.20',refund_type:0,refund_status:0,refund_num:0,refund:[],is_all_refund:false,
  _status:{_type:0,_title:'待付款'},status_name:{status_name:'待付款',pics:[]},
  add_time:'2026-10-15 09:00:00',_add_time:'2026-10-15 09:00:00',_pay_time:'',
  split:[],refund_img:[],refund_reason:'保存原因'};
const legacy={...saved,marketplace_trade_version:0,id:18,order_id:'legacy-order',refund_type:0,refund_status:0,
  _status:1,uid:0,gift_uid:0,status_name:{status_name:'待支付',pics:[]},pay_type:'offline',refund:[],split:[]};
const copy=value=>JSON.parse(JSON.stringify(value));
const rowFor=(name,version=1)=>{
  const row=copy(version===1?saved:legacy),order=name.endsWith('Order');
  if(name==='customerOrder')row.cartInfo=[copy(cart)];
  else if(name==='customerRefund')row.cart_info=[copy(cart)];
  else row._info=[{cart_info:copy(cart)}];
  if(order){
    row.refund_type=0;row.refund_status=0;
    row._status=name==='customerOrder'?{_type:0,_title:'待付款'}:1;
    if(name!=='customerOrder')row.cart_id=[cart.id];
  }else{
    row.refund_type=1;row.refund_status=1;row.refund_num=2;row.paid=1;
    row._status={_type:-1,_title:'申请退款中'};
    delete row.status_name;
  }
  if(name.startsWith('platform')){row.uid=7;row.nickname='平台列表保存姓名';}
  return row;
};
const warnings=[];
Vue.config.warnHandler=message=>warnings.push(message);
const page=name=>new Vue({beforeCreate(){this.$t=value=>value;this.$permission=()=>true;
  this.$util={getWXStatusHeight:()=>({barTop:0,barHeight:0}),Tips(){throw new Error('No refusal sample is run');}};},
  data(){return {...components[name].data.call(this),colorStyle:'',orderChartType:{},isMobile:false};},
  computed:components[name].computed,methods:components[name].methods,...renderers[name]});
const nodes=vnode=>{const all=[];const visit=value=>{if(Array.isArray(value)){value.forEach(visit);return;}
  if(!value||typeof value!=='object')return;all.push(value);(value.children||[]).forEach(visit);};visit(vnode);return all;};
const textOf=vnode=>nodes(vnode).filter(node=>typeof node.text==='string').map(node=>node.text).join('');
const scopeSlot=(vnode,label)=>{const column=nodes(vnode).find(node=>node.tag==='el-table-column'&&node.data&&node.data.attrs&&node.data.attrs.label===label);
  assert.ok(column&&column.data.scopedSlots&&column.data.scopedSlots.default,label+' actual compiled scoped slot');return column.data.scopedSlots.default;};
async function main(){
  for(const name of Object.keys(files)){
    const p=page(name);
    ordinary([p.isSavedMarketplaceV1(rowFor(name)),p.isSavedMarketplaceV1({...rowFor(name),marketplace_trade_version:'1'}),
      p.isSavedMarketplaceV1(rowFor(name,0)),p.isSavedMarketplaceV1({...rowFor(name,0),marketplace_trade_version:null})],[true,true,false,false],
      name+' selects per-row saved versions without converting identifiers');
  }
  const customer=page('customerOrder'),customerRow=rowFor('customerOrder'),oldCustomer=rowFor('customerOrder',0);
  customer.orderList=[customerRow,oldCustomer];
  const customerTree=customer._render(),customerText=textOf(customerTree);
  ordinary(customerText.includes('已保存订单，仅供查看'),true,'Customer V1 has a static read-only notice');
  ordinary(customerText.includes('24690.20'),true,'Customer saved amounts keep their original text');
  ordinary(customerText.includes('12345.10'),true,'Customer V1 displays the saved effective truePrice rather than the SKU base price');
  ordinary(nodes(customerTree).filter(node=>node.tag==='button').map(textOf),['查看详情'],'Customer V1 action is the real detail button');
  customer.goOrderDetails(customerRow.order_id);ordinary(navigation,'/pages/goods/order_details/index?order_id='+customerRow.order_id,'Customer saved detail keeps the actual order key');
  customer.goOrderDetails(oldCustomer.order_id);ordinary(navigation,'/pages/goods/order_details/index?order_id='+oldCustomer.order_id,'Historical customer detail keeps its original route');
  const returns=page('customerRefund'),returnRow=rowFor('customerRefund');returns.orderList=[returnRow,rowFor('customerRefund',0)];
  ordinary(textOf(returns._render()).includes('已保存退款，仅供查看'),true,'Customer refund keeps its existing cart_info display');
  ordinary(textOf(returns._render()).includes('12345.10'),true,'Customer refund V1 retains the saved effective unit price');
  returns.goOrderDetails(returnRow.order_id,returnRow);ordinary(navigation,'/pages/goods/order_details/index?order_id='+returnRow.order_id+'&isReturn=1','Customer refund preserves the real refund key and mode');
  for(const name of ['mobileOrder','mobileRefund']){
    const p=page(name),row=rowFor(name),old=rowFor(name,0);p.list=[row,old];const rendered=p._render();
    ordinary(nodes(rendered).filter(node=>node.tag==='button').map(textOf),['查看详情'],name+' exposes a real saved-row detail button');
    ordinary(nodes(rendered).filter(node=>node.tag==='BaseMoney').length,1,name+' keeps BaseMoney on the historical row only');
    ordinary(nodes(rendered).filter(node=>node.tag==='text'&&node.data&&node.data.staticClass==='saved-list-money').map(textOf),['￥24690.20'],name+' displays saved decimal text directly');
    p.toDetail(row);ordinary(navigation,name==='mobileOrder'?'/pages/admin/orderDetail/index?id='+row.order_id+'&types=':
      '/pages/admin/refund_order_detail/index?id='+row.order_id,name+' retains its real saved detail key');
    p.toDetail(old);ordinary(navigation,name==='mobileOrder'?'/pages/admin/orderDetail/index?id='+old.order_id+'&types=':
      '/pages/admin/refund_order_detail/index?id='+old.order_id,name+' retains its original historical detail route');
    p.orderInfo=copy(row);p.confirmOrder=copy(row);p.confirmShow=true;
    ordinary(nodes(p._render()).filter(node=>node.tag==='PriceChange').length,0,name+' does not mount the legacy write dialog for saved selection');
  }
  const refundTab=page('mobileOrder'),savedRefund=rowFor('mobileRefund');
  refundTab.where.status=-3;refundTab.list=[savedRefund];
  ordinary(textOf(refundTab._render()).includes('已保存订单，仅供查看'),true,'Mobile order-list refund tab accepts its valid refund DTO without cart_id');
  refundTab.toDetail(savedRefund);ordinary(navigation,'/pages/admin/orderDetail/index?id='+savedRefund.order_id+'&types=-3','The existing mobile order detail receives the real refund key and refund mode');
  for(const name of ['platformOrder','platformRefund']){
    const p=page(name),row=rowFor(name),old=rowFor(name,0);if(name==='platformOrder')p.orderList=[row,old];else p.tbody=[row,old];
    const tree=p._render(),slot=scopeSlot(tree,'操作');
    const active=slot({row}),historical=slot({row:old});
    ordinary(nodes(active).filter(node=>node.tag==='el-dropdown').length,0,name+' saved row does not mount the legacy operation dropdown');
    ordinary(nodes(historical).filter(node=>node.tag==='el-dropdown').length,1,name+' historical row keeps its original operation dropdown');
    ordinary(nodes(active).filter(node=>node.tag==='el-button').map(textOf),name==='platformOrder'?['详情']:['退款详情','原单详情'],name+' has only real saved detail actions');
    let detail;
    p.getData=(...args)=>{detail=args;};p.changeMenu(row,'2');ordinary(detail,name==='platformOrder'?[row.id]:[row.order_id,2],name+' uses the intended saved detail key');
    p.changeMenu(old,'2');ordinary(detail,name==='platformOrder'?[old.id]:[old.order_id,2],name+' keeps the historical detail key');
    if(name==='platformRefund'){p.changeMenu(row,'3');ordinary(detail,[row.store_order_id,3],'Platform refund parent detail uses its actual parent database key');}
    else{
      ordinary([p.canSelectLegacyRow(row),p.canSelectLegacyRow(old)],[false,true],'Mixed page selection is available to the legacy row only');
      let selection;
      p.$refs.table={objData:[row,old]};p.getisDelIdListl=value=>{selection=value;};
      p.handleSelectRow([old]);await Vue.nextTick();ordinary(selection,[old.id],'Ordinary legacy selection retains the actual ID only');
      ordinary(p.selectedIds,[old.id],'Legacy selection state remains an array of actual IDs');
      const expanded=nodes(tree).find(node=>node.tag==='el-table-column'&&node.data&&node.data.attrs&&node.data.attrs.type==='expand');
      assert.ok(expanded&&expanded.data.scopedSlots.default);
      ordinary(nodes(expanded.data.scopedSlots.default({row})).filter(node=>node.tag==='expandRow').length,0,'V1 does not mount the private legacy expansion');
      ordinary(nodes(expanded.data.scopedSlots.default({row:old})).filter(node=>node.tag==='expandRow').length,1,'Legacy expansion remains available on its original row');
    }
  }
  // Guard presence is inspected in source; no stopped command/refusal/fault samples are executed.
  const guards={customerOrder:['cancelOrder','goPay','delOrder'],platformOrder:['changeMenu','shipmentClear','edit','delOrder','sendOrder','delivery','bindWrite'],
    platformRefund:['changeMenu','delOrder'],mobileOrder:['verify','goDelivery','modify','objOrderRefund','savePrice','offlinePay','confirmPay'],
    mobileRefund:['goDelivery','modify','objOrderRefund','savePrice','offlinePay','goLogistics']};
  for(const [name,names]of Object.entries(guards))for(const method of names){
    const source=methodSources[name][method];assert.ok(source&&source.includes('if (this.isSavedMarketplaceV1(')&&source.includes('return;'),name+'.'+method+' keeps its V1 source guard');
  }
  const result={schema:1,scope:'ORDINARY_SAVED_MIXED_LIST_UI_SOURCE_STATE_ONLY',node:process.version,files:fingerprints,checks:compiled,
    ordinaryAssertions:assertions,actualVueComputedMethodsAndVNodes:true,vueCompiler:require(modules+'/vue-template-compiler/package.json').version,
    vueWarnings:warnings,guardBranchesSourceInspectedOnly:true,globalActionsOrExportsVerified:false,
    applicationStarted:false,HTTPExecuted:false,sqlExecuted:false,DOMCreated:false,browserVisualAcceptance:false,
    authenticationActorsCreated:false,actualPaginationOrRoleValidated:false,negativeConflictConcurrencyFaultRecoverySamplesRun:false};
  if(process.argv[2])fs.writeFileSync(process.argv[2],JSON.stringify(result,null,2)+'\n',{flag:'wx'});
  console.log(JSON.stringify(result));
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;});
