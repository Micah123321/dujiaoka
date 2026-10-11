<?php

namespace App\Admin\Forms;

use App\Admin\Support\FormExperience;
use App\Models\BaseModel;
use Dcat\Admin\Widgets\Form;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class SystemSetting extends Form
{
    private const SENSITIVE_FIELDS = [
        'password', 'server_jiang_token', 'telegram_bot_token', 'bark_token', 'qywxbot_key', 'geetest_key',
    ];
    /**
     * Handle the form request.
     *
     * @param array $input
     *
     * @return mixed
     */
    public function handle(array $input)
    {
        $settings = (array) Cache::get('system-setting', []);
        foreach (self::SENSITIVE_FIELDS as $field) {
            if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
                $input[$field] = $settings[$field] ?? '';
            }
        }
        if (array_key_exists('encryption', $input) && $input['encryption'] === null) {
            $input['encryption'] = '';
        }
        $settings = array_merge($settings, $input);
        $validator = Validator::make($settings, [
            'driver' => 'required|string',
            'port' => 'nullable|integer|between:1,65535',
            'manage_email' => 'nullable|email',
            'from_address' => 'nullable|email',
        ]);
        if ($validator->fails()) {
            return $this->response()->error('请检查邮件驱动、端口（1–65535）和邮箱地址格式。');
        }
        $dependencies = [
            'is_open_server_jiang' => ['Server 酱', ['server_jiang_token']],
            'is_open_telegram_push' => ['Telegram', ['telegram_bot_token', 'telegram_userid']],
            'is_open_bark_push' => ['Bark', ['bark_server', 'bark_token']],
            'is_open_qywxbot_push' => ['企业微信机器人', ['qywxbot_key']],
            'is_open_geetest' => ['极验', ['geetest_id', 'geetest_key']],
        ];
        foreach ($dependencies as $flag => [$label, $fields]) {
            if ((int) ($settings[$flag] ?? 0) !== BaseModel::STATUS_OPEN) continue;
            foreach ($fields as $field) {
                if (!is_scalar($settings[$field] ?? null) || trim((string) $settings[$field]) === '') return $this->response()->error('开启 ' . $label . ' 前请补齐该服务的账户参数，或先关闭开关保存。');
            }
        }
        Cache::put('system-setting', $settings);
        return $this
				->response()
				->success(admin_trans('system-setting.rule_messages.save_system_setting_success'));
    }

    /**
     * Build a form here.
     */
    public function form()
    {
        FormExperience::intro($this, '系统设置', '按需填写基础信息、推送与邮件配置。密码和密钥仅在更换时填写，留空保留已有值。保存后按提示重启 PHP 工作进程，再测试实际效果。');
        $this->tab(admin_trans('system-setting.labels.base_setting'), function () {
            FormExperience::section($this, '网站与订单', '设置客户看到的网站信息，以及订单过期时间和查询方式。');
            $settings = (array) Cache::get('system-setting', []);
            $templates = (array) config('dujiaoka.templates');
            $languages = (array) config('dujiaoka.language');
            if (!empty($settings['template']) && !isset($templates[$settings['template']])) {
                $templates[$settings['template']] = $settings['template'] . '（已有模板）';
            }
            if (!empty($settings['language']) && !isset($languages[$settings['language']])) {
                $languages[$settings['language']] = $settings['language'] . '（已有语言）';
            }
            $this->text('title', admin_trans('system-setting.fields.title'))->placeholder('例如：独角数卡商店')->required();
            $this->image('img_logo', admin_trans('system-setting.fields.img_logo'));
            $this->text('text_logo', admin_trans('system-setting.fields.text_logo'))->placeholder('例如：独角数卡')->help('用于网站文字标识和通知中的商店名称。');
            $this->text('keywords', admin_trans('system-setting.fields.keywords'))->placeholder('例如：软件授权,游戏充值');
            $this->textarea('description', admin_trans('system-setting.fields.description'))->placeholder('用一句话介绍商店及主要商品');
            $this->select('template', admin_trans('system-setting.fields.template'))
                ->options($templates)->help('选择网站展示模板，保留当前已有的自定义模板选项。')
                ->required();
            $this->select('language', admin_trans('system-setting.fields.language'))
                ->options($languages)
                ->required();
            $this->email('manage_email', admin_trans('system-setting.fields.manage_email'))->placeholder('例如：admin@example.com')->rules('nullable|email')->help('人工订单的新订单提醒发送到此邮箱。');
            $this->number('order_expire_time', admin_trans('system-setting.fields.order_expire_time'))
                ->default(5)->help('单位：分钟；例如 5 表示订单在 5 分钟后过期。')
                ->required();
            $this->switch('is_open_anti_red', admin_trans('system-setting.fields.is_open_anti_red'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->switch('is_open_img_code', admin_trans('system-setting.fields.is_open_img_code'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->switch('is_open_search_pwd', admin_trans('system-setting.fields.is_open_search_pwd'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->switch('is_open_google_translate', admin_trans('system-setting.fields.is_open_google_translate'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->editor('notice', admin_trans('system-setting.fields.notice'));
            $this->textarea('footer', admin_trans('system-setting.fields.footer'));
        });
        $this->tab(admin_trans('system-setting.labels.order_push_setting'), function () {
            FormExperience::section($this, '新订单推送', '先填写对应渠道参数，再开启推送。每个渠道可独立启用，密钥留空会保留原值。');
            $this->switch('is_open_server_jiang', admin_trans('system-setting.fields.is_open_server_jiang'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->password('server_jiang_token', admin_trans('system-setting.fields.server_jiang_token'))->attribute('autocomplete', 'new-password')->placeholder('留空保留原 SendKey')->help('填写 Server 酱 SendKey，格式示例：SCT123456example；仅填密钥。');
            $this->switch('is_open_telegram_push', admin_trans('system-setting.fields.is_open_telegram_push'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->password('telegram_bot_token', admin_trans('system-setting.fields.telegram_bot_token'))->attribute('autocomplete', 'new-password')->placeholder('留空保留原 Bot Token')->help('填写 BotFather 提供的机器人令牌，格式示例：123456789:AAExampleBotToken123456789。');
            $this->text('telegram_userid', admin_trans('system-setting.fields.telegram_userid'))->placeholder('例如：123456789 或 -1001234567890')->help('填写接收消息的用户或群组 chat_id。');
            $this->switch('is_open_bark_push', admin_trans('system-setting.fields.is_open_bark_push'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->switch('is_open_bark_push_url', admin_trans('system-setting.fields.is_open_bark_push_url'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->text('bark_server', admin_trans('system-setting.fields.bark_server'))->placeholder('https://api.day.app')->help('填写 Bark 服务根 URL，例如 https://api.day.app；末尾不带斜杠、设备密钥或推送路径。');
            $this->password('bark_token', admin_trans('system-setting.fields.bark_token'))->attribute('autocomplete', 'new-password')->placeholder('留空保留原设备密钥')->help('仅填写 Bark 设备密钥，格式示例：AbCd1234Example。');
            $this->switch('is_open_qywxbot_push', admin_trans('system-setting.fields.is_open_qywxbot_push'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->password('qywxbot_key', admin_trans('system-setting.fields.qywxbot_key'))->attribute('autocomplete', 'new-password')->placeholder('留空保留原机器人 key')->help('只填 webhook 地址中 key= 后的值，例如 00000000-0000-0000-0000-000000000000；请勿填写整条 URL。');
        });
        $this->tab(admin_trans('system-setting.labels.mail_setting'), function () {
            FormExperience::section($this, '邮件连接', 'SMTP 常见组合：587 + TLS，或 465 + SSL；以邮箱服务商提供的配置为准。日志和内存驱动仅用于调试或测试。');
            $settings = (array) Cache::get('system-setting', []);
            $drivers = ['smtp' => 'SMTP（邮箱服务器）', 'sendmail' => 'Sendmail（本机服务）', 'log' => '日志（调试）', 'array' => '内存（测试）'];
            foreach (array_keys((array) config('mail.mailers', [])) as $driver) {
                if (!isset($drivers[$driver])) $drivers[$driver] = $driver;
            }
            if (!empty($settings['driver']) && !isset($drivers[$settings['driver']])) {
                $drivers[$settings['driver']] = $settings['driver'] . '（已有驱动）';
            }
            $encryptions = ['' => '不加密', 'tls' => 'TLS（常配 587）', 'ssl' => 'SSL（常配 465）'];
            if (!empty($settings['encryption']) && !isset($encryptions[$settings['encryption']])) {
                $encryptions[$settings['encryption']] = $settings['encryption'] . '（已有配置）';
            }
            $this->select('driver', admin_trans('system-setting.fields.driver'))->options($drivers)->default('smtp')->help('SMTP 用于连接邮箱服务器；保留当前已有的其他驱动。')->required();
            $this->text('host', admin_trans('system-setting.fields.host'))->placeholder('例如：smtp.example.com')->help('填写服务器域名，不带 https:// 或端口。');
            $this->number('port', admin_trans('system-setting.fields.port'))->default(587)->min(1)->max(65535)->rules('nullable|integer|between:1,65535')->help('常见端口：587（TLS）、465（SSL）。');
            $this->text('username', admin_trans('system-setting.fields.username'))->placeholder('例如：mailer@example.com');
            $this->password('password', admin_trans('system-setting.fields.password'))->attribute('autocomplete', 'new-password')->placeholder('留空保留原密码或授权码')->help('填写邮箱服务商提供的 SMTP 密码或授权码。');
            $this->select('encryption', admin_trans('system-setting.fields.encryption'))->options($encryptions)->default(array_key_exists('encryption', $settings) ? ($settings['encryption'] ?? '') : 'tls')->help('587 通常选 TLS，465 通常选 SSL；按服务商说明配置。');
            $this->email('from_address', admin_trans('system-setting.fields.from_address'))->placeholder('例如：mailer@example.com')->rules('nullable|email')->help('使用邮箱服务商允许发送邮件的发件地址。');
            $this->text('from_name', admin_trans('system-setting.fields.from_name'))->placeholder('例如：独角数卡商店');
        });
        $this->tab(admin_trans('system-setting.labels.geetest'), function () {
            $this->text('geetest_id', admin_trans('system-setting.fields.geetest_id'))->placeholder('填写极验后台的验证 ID');
            $this->password('geetest_key', admin_trans('system-setting.fields.geetest_key'))->attribute('autocomplete', 'new-password')->placeholder('留空保留原极验密钥')->help('填写与验证 ID 配套的密钥；关闭极验时可留空。');
            $this->switch('is_open_geetest', admin_trans('system-setting.fields.is_open_geetest'))->default(BaseModel::STATUS_CLOSE);
        });
        $this->confirm(
            admin_trans('dujiaoka.warning_title'),
            admin_trans('system-setting.rule_messages.change_reboot_php_worker')
        );
    }

    public function default()
    {
        $settings = (array) Cache::get('system-setting', []);
        foreach (self::SENSITIVE_FIELDS as $field) {
            $settings[$field] = '';
        }
        return $settings;
    }

}
