// +---------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +---------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.crmeb.com All rights reserved.
// +---------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +---------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +---------------------------------------------------------------------

import LayoutMain from '@/layout';
import setting from '@/setting';
let routePre = setting.routePre;

const pre = 'cms_';

export default {
  path: routePre + '/cms',
  name: 'cms',
  header: 'cms',
  redirect: {
    name: `${pre}article`,
  },
  component: LayoutMain,
  children: [
    {
      path: 'article/index/:id?',
      name: `${pre}article`,
      meta: {
        auth: ['cms-article-index'],
        title: '文章管理',
        keepAlive: true,
      },
      component: () => import('@/pages/cms/article/index'),
    },
    {
      path: 'article_category/index',
      name: `${pre}articleCategory`,
      meta: {
        auth: ['cms-article-category'],
        title: '文章分类',
      },
      component: () => import('@/pages/cms/articleCategory/index'),
    },
    {
      path: 'article/add_article/:id?',
      name: `${pre}addArticle`,
      meta: {
        auth: ['cms-article-creat'],
        title: '文章添加',
        activeMenu: routePre + '/cms/article/index',
      },
      component: () => import('@/pages/cms/addArticle/index'),
    },
  ],
};
