# Modern 主题

独立 Vue 3 + TypeScript + Vite 商店主题，沿用 Laravel 原控制器和路由。生产环境只部署静态资源，不需要常驻 Node 服务。

## 构建与启用

在项目的 frontend 目录执行：

~~~sh
npm ci
npm run typecheck
npm test
npm run build
~~~

构建输出到 public/assets/modern，包含 manifest.json 和带内容哈希的 JS/CSS。将该目录与 resources/views/modern、config/dujiaoka.php 的 modern 选项一起部署。在后台站点配置中选择 Modern[Vue 商店] 并保存。现有站点若启用了配置/视图缓存，按原部署流程刷新缓存；无需修改默认主题或控制器。回退时在后台选择原主题。

构建基于 Node 22 验证。不要部署 frontend/node_modules。发布时先上传新哈希资源，再切换 manifest；保留上一版资源直到访问旧页面的会话结束。Vite 本地构建会清理 public/assets/modern，因此不要直接对线上正在服务的目录执行构建。

资源清单或对应文件缺失时，Blade 显示“店铺页面正在准备中”的提示。JS 加载失败时保留加载提示；关闭 JavaScript 时显示启用提示。

## 页面与接口

- 首页读取分组和商品精选字段，在客户端组合分类与关键词筛选，空结果可以一键清除筛选。
- 购买页通过原生 POST /create-order 提交 _token、gid、email、by_amount、payway，以及按配置启用的 coupon_code、search_pwd、img_verify_code、动态代充字段。库存/限购整数约束仅作前端辅助，最终以服务端校验和结账金额为准。
- 图形验证码由原 captcha_src('buy') 提供，按钮刷新独立时间戳；极验使用原 /check-geetest、官方 gt.js 和 geetest_challenge/geetest_validate/geetest_seccode 字段。未完成极验时阻止提交，网络失败提供刷新提示。
- 结账页只拼接原 pay-gateway 链接，展示优惠与附加信息；订单详情保持交付内容为只读文本并提供复制/手动选择回退。
- 查询页保留 /search-order-by-sn、/search-order-by-email、/search-order-by-browser 三种原生 POST 与 CSRF；邮箱查询密码按原开关启用。
- 扫码页用 qrcode 生成二维码，不依赖 PHP 图片扩展。向原 check-order-status 路由进行串行请求：400000 等待、200 前往详情、400001 停止并提示过期。正常等待每 5 秒查询；网络失败按 5/10/20/30 秒退避，成功响应后恢复 5 秒。隐藏页面暂停发起查询，恢复可见时立即检查；手动查询可跳过退避且不重叠请求。单次请求 10 秒超时；卸载时取消请求、定时器与可见性监听。
- 支付渠道核查：Alipay/Payjs/Wepay 调用 static_pages/qrpay，均覆盖；当前其他渠道直接重定向、返回 SDK 响应或控制器生成的支付内容，没有其他主题专用 render 模板需要复制。保留各渠道现有跳转行为。

## 数据和兼容边界

Blade 逐项组装允许输出的字段，不序列化整个商品/订单/支付配置。JSON 使用 JSON_HEX_TAG、JSON_HEX_APOS、JSON_HEX_AMP、JSON_HEX_QUOT 和无效 UTF-8 替换选项，写入 application/json 节点，由预编译 Vue 组件读取；生产包不需要运行时模板编译。

公告、商品详情、购买提示与页脚富文本经 DOMPurify 净化。原先依赖富文本 script/内联事件的自定义交互不会执行；普通排版、图片和链接保留。错误消息及卡密始终按文本显示。链接过滤危险协议，支付应用仅额外允许 alipays/weixin。极验保持原有官方外部 SDK，其余前端依赖全部本地打包，不引入外部字体。

后台价格、优惠计算、订单状态定义、支付签名、库存锁与发货逻辑均未改动。页面有键盘焦点、跳转主内容、空态、请求状态与手机断点；不添加登录或订单 API。

## 本次验证

- npm run typecheck：通过。
- npm test：14 项测试通过，包含分类/搜索组合、富文本净化、POST/CSRF/动态字段/批发价、限购与重复提交、极验字段、三种查询、交付复制、过期停轮询及网络重试/卸载清理。
- npm run build：通过，产生 manifest 与哈希 JS/CSS。
- PHP 回归 31 tests / 132 assertions 通过；Chrome 桌面和移动页面、购买字段、查询模式、复制、模拟支付成功/过期跳转通过。新增退避、隐藏暂停和手动重试在组件测试中使用虚拟时钟验证。真实验证码服务、真实支付及 MySQL 并发仍未实测。

建议集成验收路径：首页 → 购买（普通/批发/动态代充/验证码）→ 结账 → 模拟扫码待付/成功/过期 → 订单交付复制；另覆盖三种查询、错误页、资源缺失提示、320px 窄屏与键盘焦点。真实支付验证避免意外创建付费订单。
