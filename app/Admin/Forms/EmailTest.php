<?php
/**
 * The file was created by Assimon.
 *
 * @author    ZhangYiQiu<me@zhangyiqiu.net>
 * @copyright ZhangYiQiu<me@zhangyiqiu.net>
 * @link      http://zhangyiqiu.net/
 */

namespace App\Admin\Forms;

use App\Admin\Support\FormExperience;
use Dcat\Admin\Widgets\Form;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Mail\MailServiceProvider;
use Illuminate\Support\Facades\Mail;

class EmailTest extends Form
{
    /**
     * Handle the form request.
     *
     * @param array $input
     *
     * @return mixed
     */
    public function handle(array $input)
    {
        $to = trim((string) ($input['to'] ?? ''));
        if (Validator::make(['to' => $to], ['to' => 'required|email'])->fails()) {
            return $this->response()->error('请填写有效的收件邮箱地址。');
        }
        $title = $input['title'];
        $body = $input['body'];
        $sysConfig = cache('system-setting');
        $mailConfig = [
            'driver' => $sysConfig['driver'] ?? 'smtp',
            'host' => $sysConfig['host'] ?? '',
            'port' => $sysConfig['port'] ?? '465',
            'username' => $sysConfig['username'] ?? '',
            'from' => [
                'address' => $sysConfig['from_address'] ?? '',
                'name' => $sysConfig['from_name'] ?? '独角发卡',
            ],
            'password' => $sysConfig['password'] ?? '',
            'encryption' => $sysConfig['encryption'] ?? 'ssl',
        ];
        try {
            config(['mail' => array_merge(config('mail'), $mailConfig)]);
            (new MailServiceProvider(app()))->register();
            Mail::send(['html' => 'email.mail'], ['body' => $body], function ($message) use ($to, $title) {
                $message->to($to)->subject($title);
            });
        } catch (\Throwable $e) {
            Log::warning('测试邮件发送失败', ['exception_type' => get_class($e)]);
            return $this->response()->error('测试邮件发送失败，请检查邮件设置或联系管理员。');
        }
        return $this->response()->success(admin_trans('email-test.labels.success'));
    }

    /**
     * Build a form here.
     */
    public function form()
    {
        FormExperience::intro($this, '测试邮件', '读取已保存的邮件配置向指定邮箱发送一封测试邮件；先保存系统邮件设置，再填写收件地址。');
        $this->tab(admin_trans('menu.titles.email_test'), function () {
            FormExperience::section($this, '收件人与内容', '请选择自己可查收的邮箱，提交后检查收件箱与垃圾邮件目录。');
            $this->email('to', admin_trans('email-test.labels.to'))->placeholder('例如：you@example.com')->rules('email')->help('填写一个用于查收测试邮件的邮箱地址。')->required();
            $this->text('title', admin_trans('email-test.labels.title'))->placeholder('例如：邮件配置测试')->default('这是一条测试邮件')->required();
            $this->editor('body', admin_trans('email-test.labels.body'))->default("这是一封邮件配置测试邮件。<br/><br/>收到此邮件表示当前发送配置可用，请同时检查发件人名称和邮箱地址。")->required();
        });
    }

    public function default()
    {
    }

}
