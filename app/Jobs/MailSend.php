<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\MailServiceProvider;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class MailSend implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数。
     *
     * @var int
     */
    public $tries = 2;

    /**
     * 任务运行的超时时间。
     *
     * @var int
     */
    public $timeout = 30;

    private $to;

    private $content;

    private $title;

    private $requireDelivery = false;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(string $to, string $title, string $content, bool $requireDelivery = false)
    {
        $this->to = $to;
        $this->title = $title;
        $this->content = $content;
        $this->requireDelivery = $requireDelivery;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $body = $this->content;
        $title = $this->title;
        $sysConfig = cache('system-setting');
        $mailConfig = [
            'driver' => $sysConfig['driver'] ?? 'smtp',
            'host' => $sysConfig['host'] ?? '',
            'port' => $sysConfig['port'] ?? '465',
            'username' => $sysConfig['username'] ?? '',
            'from'      =>  [
                'address'   =>   $sysConfig['from_address'] ?? '',
                'name'      =>  $sysConfig['from_name'] ?? '独角发卡'
            ],
            'password' => $sysConfig['password'] ?? '',
            'encryption' => $sysConfig['encryption'] ?? ''
        ];
        $modern = method_exists(\Illuminate\Mail\Mailer::class, 'getSymfonyTransport');
        if ($modern) {
            $mailConfig['scheme'] = $mailConfig['encryption'] === 'ssl' ? 'smtps' : 'smtp';
            // Prevent port 465 from turning explicit STARTTLS/plaintext into implicit TLS.
            $mailConfig['auto_tls'] = false;
            $mailConfig['require_tls'] = $mailConfig['encryption'] === 'tls';
            $mailConfig['timeout'] = 15;
        }
        $to = $this->to;
        //  覆盖 mail 配置
        config([
            'mail'  =>  array_merge(config('mail'), $mailConfig)
        ]);
        if ($modern) {
            // Refresh named mailers while preserving registered transport creators.
            app('mail.manager')->forgetMailers();
            Mail::clearResolvedInstance('mail.manager');
        } else {
            (new MailServiceProvider(app()))->register();
        }
        Mail::clearResolvedInstance('mailer');
        $mailer = app('mailer');
        $swift = null;
        $tracker = null;
        if ($modern) {
            $transport = $mailer->getSymfonyTransport();
            if ($transport instanceof \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport) {
                if ($mailConfig['encryption'] === 'tls') {
                    $stream = $transport->getStream();
                    $guarded = new \App\Mail\StartTlsTransport($stream->getHost(), $stream->getPort(), false, null, null, $stream);
                    $guarded->setUsername($transport->getUsername())->setPassword($transport->getPassword())
                        ->setLocalDomain($transport->getLocalDomain());
                    $mailer->setSymfonyTransport($transport = $guarded);
                }
                $transport->setAutoTls($mailConfig['encryption'] === 'tls');
                $transport->setRequireTls($mailConfig['encryption'] === 'tls');
            }
        } else {
            $swift = $mailer->getSwiftMailer();
            $transport = $swift->getTransport();
            if ($transport instanceof \Swift_SmtpTransport) {
                $transport->setTimeout(15);
            }
            $tracker = $this->requireDelivery ? new \App\Mail\TrackedTransport($transport) : null;
            if ($tracker) {
                $mailer->setSwiftMailer(new \Swift_Mailer($tracker));
            }
        }
        try {
            $sent = $mailer->send(['html' => 'email.mail'], ['body' => $body], function ($message) use ($to, $title) {
                $message->to($to)->subject($title);
            });
            $accepted = $modern
                ? $sent instanceof \Illuminate\Mail\SentMessage && count($sent->getSymfonySentMessage()->getEnvelope()->getRecipients()) > 0
                : !$tracker || ($tracker->acceptedRecipients > 0 && !$mailer->failures());
            if ($this->requireDelivery && !$accepted) {
                throw new \RuntimeException('Payment mail was not accepted by the transport.');
            }
        } finally {
            if ($tracker) {
                $mailer->setSwiftMailer($swift);
            }
        }
    }
}
