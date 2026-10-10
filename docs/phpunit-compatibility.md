# PHPUnit 9 / 11 共存验收（第32轮历史）

本页保留第32轮PHP8.2/PHPUnit11比较台阶的证据；第33轮已升PHP8.5.11/PHPUnit13.4.1并完整2128/16529通过；第34轮当前Laravel13/Dcat X2完整2140/16574严格通过，见[当前维护期框架、依赖变化与重放](laravel13-compatibility.md)，[第33轮平台证据](php-runtime-compatibility.md)另保留。以下版本、计数和运行包不变结论均属于第32轮，原共享属性/static provider方案继续保留。

## 版本与依赖边界

[Laravel12升级指南](https://laravel.com/framework/docs/12.x/upgrade)建议 PHPUnit11；[PHPUnit官方版本表](https://phpunit.de/supported-versions.html)列出11需要PHP8.2、12需要PHP8.3、13需要PHP8.4。因此11.5是当前PHP8.2候选的兼容台阶。官方表同时列出11常规bugfix截止2026-02-06；本批工具迁移不代表最终受维护PHP/测试平台的选型已经完成。

[候选清单](../tests/Compatibility/Laravel12/composer.json)使用稳定约束 ^11.5；[候选锁](../tests/Compatibility/Laravel12/composer.lock)实际1新增/21更新/2移除，移除doctrine/instantiator和sebastian/resource-operations，新增staabm/side-effects-detector。全部变动属于测试工具链；102个运行依赖的名称、版本、source reference逐项与升级前相同。开发包30→29，总包132→131；根仍112包，本轮未更新根依赖。

实际使用 Composer require --dev phpunit/phpunit:^11.5 --with-all-dependencies --minimal-changes --no-scripts --no-plugins --no-interaction，先dry-run确认边界再安装。未忽略平台要求，validate和check-platform-reqs通过，发行二维码2.0.0精确约束提示保留；安装报告候选无公告，不延伸为根旧依赖审计已清零。

## 共享测试适配

29文件中167个数据驱动测试方法对应133个不同provider；原67个static，剩余66个本轮改为public static。其中52个为纯数据，14个引用实例helper，必要helper改为静态并保留原oracle断言。原doc metadata全部保留，另加单行全限定属性：

```php
/** @dataProvider cases */
#[\PHPUnit\Framework\Attributes\DataProvider('cases')]
public function testExample($input): void
{
    // Existing assertions remain here.
}
```

PHP7.4把单行属性当作#注释，PHPUnit9读取原doc metadata；PHP8.2 / PHPUnit11读取属性而不产生旧元信息弃用。29份文件全部PHP7.4语法通过，两套完整实际运行证明兼容。属性保持单行，避免PHP7.4注释只覆盖首行。

初次真实运行在发现阶段先后复现 [Payjs](../tests/Unit/Payments/PayjsClientTest.php) 和 [WechatV2](../tests/Unit/Payments/WechatV2ClientTest.php) 的result helper与新版final TestCase::result冲突；改为纯静态gatewayResponse并更新调用。两个签名oracle中的assertSame保留为self::assertSame；未改业务客户端。[Geetest](../tests/Unit/Captcha/GeetestClientTest.php) provider构造ConnectException时用真实PSR-7 Request替代实例createMock，维持原失败数据；[Epusdt](../tests/Unit/Payments/EpusdtLegacyClientTest.php) / [TokenPay](../tests/Unit/Payments/TokenPayLegacyClientTest.php) 静态化纯receipt/wire helper。

[候选XML](../tests/Compatibility/Laravel12/phpunit-core.xml)引用实际安装schema，关闭结果缓存，启用failOnAllIssues、displayDetailsOnAllIssues和空suite失败；警告、弃用、notice、risky、跳过及未完成均使运行失败。Unit/Feature与六项SMTP全部保留，无过滤或排除。隔离运行目录的一项E_USER_DEPRECATED负对照实际返回子进程exit1，并显示精确sentinel消息，验证严格开关生效；该负对照不在普通suite内。根XML及原coverage配置保留，本批未采集代码覆盖率。

## 完整验证与计数解释

| 运行组合 | 测试 | 断言 | 结果 |
| --- | ---: | ---: | --- |
| PHP7.4.33 / Laravel6.20.42 / PHPUnit9.6.38 | 2118 | 16948 | exit0，16.864秒 |
| PHP8.2.31 / Laravel12.69.3 / PHPUnit11.5.57 | 2124 | 16493 | strict exit0，22.821秒 |
| PHP8.2.31 / 根PHPUnit9，仅独立Payments | 1360 | 9744 | exit0，旧Pay2弃用提示保留 |

完整JUnit：[根](../tests/.runtime/phpunit9-root-final-32.xml) / [候选](../tests/.runtime/laravel12/phpunit11-final-32.xml)。候选严格模式结果无告警/弃用/跳过/risky/未完成；两个框架的Injected queue/notification failure日志来自既有失败恢复测试，CLI仍exit0，未作为未处理故障忽略。独立Payments只证明这些类可在根旧运行器的PHP8中解析属性，不表示旧Laravel6应用已支持PHP8。

升级前以原9运行器保存 [发现清单](../tests/.runtime/laravel12/phpunit9-list-32.xml)，升级后保存 [新版清单](../tests/.runtime/laravel12/phpunit11-list-32.xml)。新版XML改为testClass/testMethod及id，旧版为testCaseClass/testCaseMethod及dataSet；规范化class、method和数字/命名dataset后，2124项逐项相同，无缺失、新增或重复，见[对照报告](../tests/.runtime/laravel12/phpunit-discovery-32.json)。本轮没有新增业务测试。

候选断言16490→16493的三个差异，逐项JUnit均定位为Coinbase testInvalidTransportSeamIsRedacted、Epusdt testInvalidConfigurationAndOrdersFailBeforeTransport、TokenPay testInvalidEndpointCredentialsAndOrdersFailBeforeTransport，各增加1。这三项原mock均有with(handler)；实际安装PHPUnit11 Parameters::doVerify在参数evaluate前增加断言计数，而9仅evaluate。这是运行器计数变化，三个原参数验证和其余2121个用例的断言语义保留。

真实应用probe仍报告158 routes、Laravel12.69.3、Dcat X1.2.2、隔离SQLite :memory:/arraycache/独立storage及ArrayTransport。102运行包与app代码本轮不变；前一轮后台浏览器和MySQL报告保留原阶段证据，没有把它们写成新版运行器重新执行的结果。

## 当前重放

当前锁已是PHPUnit13；原PHP8.2命令与旧锁不再用于现有候选。使用[维护版平台重放](php-runtime-compatibility.md)，旧根继续使用独立PHP7.4。第32轮清单/JUnit保留历史证据，不以现有运行器覆盖它们。

正式框架选型、实际部署数据库结构、worker/scheduler生命周期及扩展后台操作仍待验收，整体目标保持进行。

