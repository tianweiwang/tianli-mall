<template><section class="page-panel"><h1 class="page-heading">商品列表</h1><el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="feedback" /><el-alert v-if="notice" :title="notice" role="status" aria-live="polite" aria-atomic="true" type="success" :closable="false" show-icon class="feedback" /><div class="toolbar"><el-input v-model="keyword" placeholder="商品名称或关键词" maxlength="128" @keyup.enter.native="search" :id="fieldId('product-search')" :label="'商品名称或关键词'" /><el-select v-model="reviewStatus" placeholder="审核状态" clearable :id="fieldId('review-filter')" v-control-label="'审核状态'"><el-option v-for="(label, index) in statuses" :key="index" :value="index" :label="label" /></el-select><el-button :disabled="loading || busy" @click="search">查询</el-button><el-button v-if="can('product.write')" type="primary" @click="$router.push('/products/new')">新建商品</el-button></div><el-table v-loading="loading" :data="rows" border><el-table-column prop="id" label="编号" width="90" /><el-table-column label="商品" min-width="240"><template slot-scope="scope"><div class="product-name"><img :src="scope.row.image" class="table-image" alt="商品图片"><span>{{ scope.row.store_name }}</span></div></template></el-table-column><el-table-column prop="price" label="售价（元）" width="115" /><el-table-column prop="stock" label="库存" width="90" /><el-table-column label="审核状态" width="125"><template slot-scope="scope">{{ statuses[scope.row.review_status] }}</template></el-table-column><el-table-column label="上架状态" width="95"><template slot-scope="scope">{{ scope.row.is_show ? '已上架' : '未上架' }}</template></el-table-column><el-table-column label="操作" min-width="245"><template slot-scope="scope"><div class="table-actions"><el-button size="mini" @click="$router.push('/products/' + scope.row.id + '/edit')">{{ can('product.write') ? '编辑' : '查看' }}</el-button><el-button v-if="can('product.submit_review') && [0,3].includes(scope.row.review_status)" size="mini" :disabled="busy" @click="act('submit', scope.row)">提交审核</el-button><el-button v-if="can('product.write') && (scope.row.is_show || scope.row.review_status === 2)" size="mini" :disabled="busy" @click="act('show', scope.row)">{{ scope.row.is_show ? '下架' : '上架' }}</el-button><el-button v-if="can('product.write')" size="mini" :disabled="busy" @click="act('delete', scope.row)">删除</el-button></div></template></el-table-column></el-table><div class="pagination"><el-pagination :current-page="page" :page-size="limit" :pager-count="5" :total="count" layout="prev, pager, next" @current-change="changePage" /></div></section></template>
<script>
import { fieldNames } from '../../accessibility';
import { api } from '../../api';
export default {
  mixins: [fieldNames],
  data: () => ({ keyword: '', reviewStatus: '', page: 1, limit: 20, rows: [], count: 0, loading: false, busy: false, error: '', notice: '', statuses: ['草稿', '待审核', '审核通过', '已驳回'] }),
  created() { this.load(); },
  methods: {
    can(permission) { return this.$store.getters.can(permission); },
    search() { this.page = 1; this.load(); }, changePage(page) { if (!this.loading) { this.page = page; this.load(); } },
    async load(propagate = false) { if (this.loading) return; this.loading = true; this.error = ''; try { const query = { keyword: this.keyword.trim(), page: this.page, limit: this.limit, is_del: 0 }; if (this.reviewStatus !== '') query.review_status = this.reviewStatus; const data = await api.products(query); if (!Array.isArray(data.list) || !Number.isInteger(data.count)) throw new Error('商品列表返回不完整'); this.rows = data.list; this.count = data.count; } catch (error) { this.error = error.message; if (propagate) throw error; } finally { this.loading = false; } },
    async act(kind, row) {
      if (this.busy || this.loading) return;
      if (kind === 'delete') { try { await this.$confirm('删除商品“' + row.store_name + '”？', '删除商品', { type: 'warning' }); } catch (action) { return; } }
      this.busy = true; this.error = ''; this.notice = '';
      let changed = false;
      try { if (kind === 'submit') await api.submitReview(row.id); if (kind === 'show') await api.showProduct(row.id, row.is_show ? 0 : 1); if (kind === 'delete') await api.deleteProduct(row.id); changed = true; await this.load(true); this.notice = kind === 'submit' ? '商品已提交审核' : kind === 'show' ? (row.is_show ? '商品已下架' : '商品已上架') : '商品已删除'; }
      catch (error) { this.error = (changed ? '操作已提交，列表刷新未完成：' : '') + error.message; } finally { this.busy = false; }
    },
  },
};
</script>
