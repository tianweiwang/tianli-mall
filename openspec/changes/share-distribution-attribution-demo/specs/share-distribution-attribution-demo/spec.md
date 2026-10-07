## ADDED Requirements

### Requirement: 分享令牌与推荐绑定

系统 SHALL 为新分享生成唯一令牌和二维码，并允许模拟好友通过落地页接受推荐。

#### Scenario: 接受有效分享

- **WHEN** 好友打开有效分享令牌并接受推荐
- **THEN** 系统 SHALL 创建一个持续有效的分销绑定和待首单推荐记录
- **THEN** 系统 SHALL 将当前消费者切换为该好友
- **THEN** 好友用户中心 SHALL 显示推荐人、绑定状态和首单状态
- **THEN** 好友用户中心 SHALL NOT 将推荐人的分享、邀请或佣金数据展示为好友数据
- **THEN** 同一浏览器对同一令牌 SHALL 只记录一次访问
- **THEN** 重复接受同一令牌 SHALL NOT 创建重复绑定或推荐记录

#### Scenario: 打开无效分享

- **WHEN** 分享令牌不存在或缺少有效门店/分销员
- **THEN** 系统 SHALL 显示无效链接且 SHALL NOT 修改归因状态

#### Scenario: 切换消费者账号

- **GIVEN** 分享人已经绑定一个好友
- **WHEN** 演示者在消费者账号间切换
- **THEN** 系统 SHALL 切换消费者姓名和编号
- **THEN** 系统 SHALL 仅在切回绑定好友时恢复该好友的推荐绑定
- **THEN** 系统 SHALL 保留两个消费者各自的数据

### Requirement: 归因订单与推荐奖励

系统 SHALL 为有效绑定产生的订单记录分享来源，并按分销员档案比例生成佣金。

#### Scenario: 消费者订单隔离

- **GIVEN** 分享人已有历史订单且新绑定好友尚未下单
- **WHEN** 好友进入我的订单
- **THEN** 系统 SHALL 显示空订单状态
- **WHEN** 好友完成一笔订单
- **THEN** 好友 SHALL 只看到自己的订单
- **WHEN** 切回分享人
- **THEN** 分享人 SHALL 看到自己的历史订单且 SHALL NOT 看到好友订单

#### Scenario: 完成首笔归因订单

- **WHEN** 已绑定好友完成支付
- **THEN** 系统 SHALL 按实付金额的 8% 创建一条待结算佣金
- **THEN** 系统 SHALL 将分享订单数增加一次
- **THEN** 系统 SHALL 将推荐记录标记为已奖励并发放一次 8 元推荐奖励

#### Scenario: 完成后续归因订单

- **WHEN** 同一绑定好友再次完成支付
- **THEN** 系统 SHALL 继续创建 8% 待结算佣金
- **THEN** 系统 SHALL NOT 再次发放首单推荐奖励

#### Scenario: 查看团队明细

- **GIVEN** 分享人位于分销中心
- **WHEN** 分享人点击“我的团队”统计卡片
- **THEN** 系统 SHALL 进入当前门店的推荐明细页
- **THEN** 页面 SHALL 展示邀请成员、手机号、邀请时间、首单状态和奖励金额

#### Scenario: 普通订单与重复支付

- **WHEN** 订单没有有效绑定
- **THEN** 系统 SHALL NOT 生成分销佣金
- **WHEN** 同一支付请求重复提交
- **THEN** 系统 SHALL NOT 重复生成订单、积分、佣金或推荐奖励

### Requirement: 佣金结算与提现

系统 SHALL 在订单分账完成时同步结算该订单的分销佣金。

#### Scenario: 品牌完成分账

- **WHEN** 品牌把归因订单的分账推进为已完成
- **THEN** 对应佣金 SHALL 从待结算变为已结算
- **THEN** 分销员可提现余额 SHALL 增加相同金额

#### Scenario: 提现并审核

- **WHEN** 分销员提交不超过可提现余额的申请
- **THEN** 品牌 SHALL 能将申请推进为处理中、已到账或驳回
