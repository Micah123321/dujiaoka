<?php

declare(strict_types=1);

namespace Tests\Unit\Captcha;

use App\Captcha\GeetestClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use PHPUnit\Framework\TestCase;

final class GeetestClientTest extends TestCase
{
    public function testRegisterAndOnlineValidationUseLegacyV3Protocol(): void
    {
        $raw = str_repeat('a', 32);
        $history = array();
        $stack = HandlerStack::create(new MockHandler(array(
            new Response(200, array(), $raw),
            new Response(200, array(), json_encode(array('seccode' => md5('answer')))),
        )));
        $stack->push(Middleware::history($history));
        $client = new GeetestClient(new Client(array('handler' => $stack)), 'id', 'key');

        $registered = $client->register(array('user_id' => 'u', 'gt' => 'evil'));
        $this->assertSame(1, $registered['success']);
        $this->assertSame(md5($raw . 'key'), $registered['challenge']);
        $this->assertTrue($client->validateOnline($registered['challenge'], md5('keygeetest' . $registered['challenge']), 'answer', array('ip_address' => '127.0.0.1', 'seccode' => 'evil')));

        $this->assertSame('https', $history[0]['request']->getUri()->getScheme());
        $this->assertSame('/register.php', $history[0]['request']->getUri()->getPath());
        parse_str((string) $history[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame('id', $query['gt']);
        $this->assertSame('u', $query['user_id']);
        $this->assertArrayNotHasKey('seccode', $query);
        $this->assertSame('POST', $history[1]['request']->getMethod());
        parse_str((string) $history[1]['request']->getBody(), $form);
        $this->assertSame('id', $form['captchaid']);
        $this->assertSame('php_3.0.0', $form['sdk']);
        $this->assertArrayNotHasKey('seccode_override', $form);
        $this->assertSame(3.0, $history[0]['options']['connect_timeout']);
        $this->assertSame(5.0, $history[0]['options']['timeout']);
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $this->assertTrue($history[0]['options']['verify']);
    }

    public function testBadSignatureDoesNotSendRequest(): void
    {
        $history = array();
        $stack = HandlerStack::create(new MockHandler());
        $stack->push(Middleware::history($history));
        $client = new GeetestClient(new Client(array('handler' => $stack)), 'id', 'key');
        $this->assertFalse($client->validateOnline('challenge', 'bad', 'answer', array()));
        $this->assertCount(0, $history);
    }

    /** @dataProvider registerFailureProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('registerFailureProvider')]
    public function testRegisterFailuresFallback($handler): void
    {
        $client = new GeetestClient(new Client(array('handler' => HandlerStack::create(new MockHandler(array($handler))))), 'id', 'key');
        $result = $client->register(array('gt' => 'evil', 'challenge' => 'evil'));
        $this->assertSame(0, $result['success']);
        $this->assertSame(34, strlen($result['challenge']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{34}$/', $result['challenge']);
        $this->assertSame('id', $result['gt']);
    }

    public static function registerFailureProvider(): array
    {
        return array(
            array(new Response(500, array(), 'bad')),
            array(new Response(200, array(), '{')),
            array(new Response(200, array(), 'short')),
            array(new ConnectException('timeout', new Request('GET', 'https://api.geetest.com/register.php'))),
        );
    }

    public function testOfflineValidationUsesLegacyMd5(): void
    {
        $client = new GeetestClient(new Client(array('handler' => HandlerStack::create(new MockHandler()))), 'id', 'key');
        $this->assertTrue($client->validateOffline('challenge', md5('challenge'), 'ignored'));
        $this->assertFalse($client->validateOffline('challenge', md5('other'), 'ignored'));
    }
}
