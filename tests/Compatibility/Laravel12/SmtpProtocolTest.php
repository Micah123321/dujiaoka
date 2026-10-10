<?php

namespace Tests\Compatibility\Laravel12;

use App\Jobs\MailSend;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;
use Tests\TestCase;

class SmtpProtocolTest extends TestCase
{
    /** @dataProvider rejectedProtocols */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedProtocols')]
    public function testFailedNegotiationOrSmtpRejectionNeverSucceeds(string $mode): void
    {
        $stream = $this->prepare($mode);
        $sent = 0;
        app('events')->listen(MessageSent::class, function () use (&$sent) { ++$sent; });
        try {
            (new MailSend('buyer@example.test', 'Fixture', 'Fixture body', true))->handle();
            $this->fail('SMTP fixture was unexpectedly accepted.');
        } catch (TransportExceptionInterface $exception) {
            $this->assertSame(0, $sent);
            if (in_array($mode, ['helo-fallback', 'missing-starttls', 'crypto-failure'], true)) {
                $this->assertStringNotContainsString('MAIL FROM:', implode('', $stream->commands));
                $this->assertStringNotContainsString('Fixture body', implode('', $stream->commands));
            }
        }
    }

    public static function rejectedProtocols(): array
    {
        return [['helo-fallback'], ['missing-starttls'], ['crypto-failure'], ['rcpt-rejected'], ['data-rejected']];
    }

    public function testStartTlsAndFinalDataAcceptanceEmitOneSentEvent(): void
    {
        $stream = $this->prepare('accepted');
        $sent = 0;
        app('events')->listen(MessageSent::class, function () use (&$sent) { ++$sent; });
        (new MailSend('buyer@example.test', 'Fixture', 'Fixture body', true))->handle();
        $this->assertSame(1, $sent);
        $this->assertSame(1, $stream->tlsAttempts);
        $commands = implode('', $stream->commands);
        $this->assertStringContainsString("STARTTLS\r\n", $commands);
        $this->assertStringContainsString('RCPT TO:<buyer@example.test>', $commands);
        $this->assertStringContainsString('Fixture body', $commands);
    }

    private function prepare(string $mode): ScriptedSmtpStream
    {
        $stream = new ScriptedSmtpStream($mode);
        Cache::put('system-setting', ['driver' => 'smtp', 'host' => 'fixture.invalid', 'port' => 2587,
            'encryption' => 'tls', 'from_address' => 'sender@example.test'], 60);
        app('mail.manager')->extend('smtp', function ($config) use ($stream) {
            return (new EsmtpTransport($config['host'], (int) $config['port'], false, null, null, $stream))
                ->setAutoTls(false)->setRequireTls($config['require_tls']);
        });
        return $stream;
    }
}

// Exercise real SMTP transport logic without sockets or certificate/credential I/O.
class ScriptedSmtpStream extends AbstractStream
{
    private $mode;
    private $responses = [];
    private $encrypted = false;
    private $host = '';
    private $port = 0;
    private $tls = false;
    public $commands = [];
    public $tlsAttempts = 0;

    public function __construct(string $mode) { $this->mode = $mode; }
    public function setHost(string $host) { $this->host = $host; return $this; }
    public function getHost(): string { return $this->host; }
    public function setPort(int $port) { $this->port = $port; return $this; }
    public function getPort(): int { return $this->port; }
    public function disableTls() { $this->tls = false; return $this; }
    public function isTLS(): bool { return $this->tls; }
    protected function getReadConnectionDescription(): string { return 'SMTP fixture'; }
    public function initialize(): void { $this->responses = ["220 Fixture ready\r\n"]; }
    public function flush(): void {}
    public function terminate(): void {}
    public function startTLS(): bool { ++$this->tlsAttempts; return $this->encrypted = $this->mode !== 'crypto-failure'; }
    public function hasPendingData(): bool { return false; }
    public function readLine(): string
    {
        if (!$this->responses) throw new \LogicException('Unexpected fixture response read.');
        return array_shift($this->responses);
    }

    public function write(string $bytes, bool $debug = true): void
    {
        $this->commands[] = $bytes;
        if (!$debug) return;
        if (strpos($bytes, 'EHLO ') === 0) {
            if ($this->mode === 'helo-fallback') $this->responses[] = "500 EHLO unsupported\r\n";
            elseif ($this->encrypted || $this->mode === 'missing-starttls') $this->responses[] = "250 Fixture ready\r\n";
            else array_push($this->responses, "250-Fixture ready\r\n", "250 STARTTLS\r\n");
        } elseif (strpos($bytes, 'HELO ') === 0) $this->responses[] = "250 Fixture ready\r\n";
        elseif ($bytes === "STARTTLS\r\n") $this->responses[] = "220 Start TLS\r\n";
        elseif (strpos($bytes, 'MAIL FROM:') === 0) $this->responses[] = "250 Sender accepted\r\n";
        elseif (strpos($bytes, 'RCPT TO:') === 0) $this->responses[] = $this->mode === 'rcpt-rejected' ? "550 Recipient rejected\r\n" : "250 Recipient accepted\r\n";
        elseif ($bytes === "DATA\r\n") $this->responses[] = "354 Send data\r\n";
        elseif ($bytes === "\r\n.\r\n") $this->responses[] = $this->mode === 'data-rejected' ? "451 Data rejected\r\n" : "250 Message accepted\r\n";
        elseif ($bytes === "RSET\r\n") $this->responses[] = "250 Reset\r\n";
        elseif ($bytes === "QUIT\r\n") $this->responses[] = "221 Bye\r\n";
        else throw new \LogicException('Unexpected fixture SMTP command.');
    }
}
