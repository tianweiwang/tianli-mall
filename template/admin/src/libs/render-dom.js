// +---------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +---------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.crmeb.com All rights reserved.
// +---------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +---------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +---------------------------------------------------------------------

export default {
  name: 'RenderDom',
  functional: true,
  props: {
    render: Function,
  },
  render: (h, ctx) => {
    return ctx.props.render(h);
  },
};
