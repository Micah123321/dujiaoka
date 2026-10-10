<?php

namespace Tests\Feature;

use App\Jobs\MailSend;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailWorkerTest extends TestCase
{
    use \Tests\Concerns\InteractsWithMailRuntime;

    public function testSmtpTransportRefreshesWithoutOpeningNetworkConnection(): void
    {
        // Cancel after preparation, before any SMTP I/O.
        app('events')->listen(\Illuminate\Mail\Events\MessageSending::class, function () { return false; });
        $previous = null;
        foreach (['first.invalid', 'second.invalid'] as $host) {
            Cache::put('system-setting', ['driver' => 'smtp', 'host' => $host, 'port' => 2525,
                'username' => $host, 'from_address' => 'sender@example.test', 'from_name' => 'Fixture'], 60);
            (new MailSend('buyer@example.test', 'Fixture', 'Fixture'))->handle();
            $transport = $this->mailTransport();
            $stream = $this->usesSymfonyMail() ? $transport->getStream() : $transport;
            $this->assertSame($host, $stream->getHost());
            $this->assertSame(2525, $stream->getPort());
            $this->assertSame(15, (int) $stream->getTimeout());
            $this->assertSame($host, $transport->getUsername());
            $this->assertNotSame($previous, $transport);
            if (!$this->usesSymfonyMail()) $this->assertFalse($transport->isStarted());
            $previous = $transport;
        }
    }

    public function testConsecutiveJobsUseUpdatedSenderConfiguration(): void
    {
        $messages = [];
        Mail::getFacadeRoot(); app('mailer');
        app('events')->listen(\Illuminate\Mail\Events\MessageSent::class, function ($event) use (&$messages) {
            $messages[] = $event->message;
        });
        foreach (['first@example.test', 'second@example.test'] as $sender) {
            Cache::put('system-setting', ['driver' => 'array', 'from_address' => $sender, 'from_name' => 'Fixture'], 60);
            (new MailSend('buyer@example.test', 'Fixture subject', '<p>Fixture body</p>', true))->handle();
        }
        $this->assertCount(2, $messages);
        $this->assertSame(['first@example.test'], $this->mailAddresses($messages[0], 'From'));
        $this->assertSame(['second@example.test'], $this->mailAddresses($messages[1], 'From'));
        $this->assertSame('Fixture subject', $messages[1]->getSubject());
        $this->assertSame(['buyer@example.test'], $this->mailAddresses($messages[1], 'To'));
        $this->assertStringContainsString('Fixture body', $this->mailBody($messages[1]));
        $this->assertSame(app($this->usesSymfonyMail() ? 'mail.manager' : 'mailer'), Mail::getFacadeRoot());
    }

    /** @dataProvider smtpEncryptionProfiles */
    #[\PHPUnit\Framework\Attributes\DataProvider('smtpEncryptionProfiles')]
    public function testSmtpEncryptionRetainsExplicitProfileAtEveryPort(string $encryption, int $port): void
    {
        app('events')->listen(\Illuminate\Mail\Events\MessageSending::class, function () { return false; });
        Cache::put('system-setting', ['driver' => 'smtp', 'host' => 'fixture.invalid', 'port' => $port,
            'encryption' => $encryption, 'from_address' => 'sender@example.test'], 60);
        (new MailSend('buyer@example.test', 'Fixture', 'Fixture'))->handle();
        $transport = $this->mailTransport();
        if ($this->usesSymfonyMail()) {
            $this->assertSame($encryption === 'ssl', $transport->getStream()->isTLS());
            $this->assertSame($encryption === 'tls', $transport->isAutoTls());
            $this->assertSame($encryption === 'tls', $transport->isTlsRequired());
            $this->assertSame($port, $transport->getStream()->getPort());
        } else {
            $this->assertSame($encryption, $transport->getEncryption());
            $this->assertSame($port, $transport->getPort());
        }
    }

    public static function smtpEncryptionProfiles(): array
    {
        return [['ssl', 2465], ['ssl', 465], ['tls', 2587], ['tls', 465], ['', 2525], ['', 465]];
    }
}
