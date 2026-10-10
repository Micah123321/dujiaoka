<?php

namespace Tests\Unit\Payments;

use App\Payments\JsonNotification;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class JsonNotificationTest extends TestCase
{
    public function testJsonScalarTypesAndOriginalStringValuesRemainIntact(): void
    {
        $data = JsonNotification::parse(" \r\n{\"amount\":7.01,\"status\":2,\"text\":\"  a + \\r\\n  \",\"empty\":\"\",\"optional\":null,\"flag\":true,\"zero\":0}\t", ['amount']);
        $this->assertSame(7.01, $data['amount']);
        $this->assertSame(2, $data['status']);
        $this->assertSame("  a + \r\n  ", $data['text']);
        $this->assertSame('', $data['empty']); $this->assertNull($data['optional']);
        $this->assertTrue($data['flag']); $this->assertSame(0, $data['zero']);
    }

    /** @dataProvider malformedObjects */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedObjects')]
    public function testAmbiguousNestedOrOversizedObjectsFail(string $body): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Payment provider unavailable.');
        JsonNotification::parse($body, ['amount']);
    }

    public static function malformedObjects(): array
    {
        return [[''], ['{}'], ['[]'], ['[1]'], ['{"amount":7.01,"amount":7.01}'],
            ['{"amount":7.01,"amoun\\u0074":7.01}'], ['{"amount":7.01,}'],
            ['{"amount":[7.01]}'], ['{"amount":{"nested":7.01}}'], ['{"_method":"POST"}'],
            ['{"1":1}'], ['{"amount":NaN}'], ['{"amount":7.01}trailing'], ['{"text":"\\uD800"}'],
            ['{"text":"' . str_repeat('x', 65536) . '"}'], ['{"amount":7.0100000001}'],
            ['{"amount":7.01e0}'], ['{"amount":-7.01}'], ['{"amount":true}'], ['{"amount":null}']];
    }

    public function testEscapedKeysResolveOnceAndUnknownNumbersAreNotFinancialValues(): void
    {
        $data = JsonNotification::parse('{"amoun\\u0074":"7.01","extra":1e-3,"large":999999999999999999999999}', ['amount']);
        $this->assertSame('7.01', $data['amount']);
        $this->assertSame(0.001, $data['extra']);
        $this->assertSame('999999999999999999999999', $data['large']);
    }

    /** @dataProvider longStrings */
    #[\PHPUnit\Framework\Attributes\DataProvider('longStrings')]
    public function testValidLongStringsWithinBodyLimitDoNotDependOnRegexJit(string $text): void
    {
        $body = json_encode(['amount' => 7.01, 'text' => $text]);
        $this->assertLessThanOrEqual(65536, strlen($body));
        $this->assertSame($text, JsonNotification::parse($body, ['amount'])['text']);
    }

    public static function longStrings(): array
    {
        $overhead = strlen(json_encode(['amount' => 7.01, 'text' => '']));
        return [[str_repeat('x', 8192)], [str_repeat('x', 65536 - $overhead)], [str_repeat('"', 20000)],
            [str_repeat('\\', 20000)], [str_repeat("x\\\"\t\n", 5000)]];
    }

    /** @dataProvider centNumbers */
    #[\PHPUnit\Framework\Attributes\DataProvider('centNumbers')]
    public function testFiatJsonNumberEncodingDoesNotDependOnSerializePrecision(string $amount): void
    {
        $previous = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', '17');
            $body = JsonNotification::encode(['ActualAmount' => $amount, 'name' => '"ActualAmount":"7.01"', 'secretless' => true], ['ActualAmount']);
            $this->assertStringContainsString('"ActualAmount":' . $amount . ',', $body);
            $this->assertStringContainsString('"name":"\\"ActualAmount\\":\\"7.01\\""', $body);
            $this->assertSame($amount, (string) json_decode($body, true)['ActualAmount']);
        } finally { ini_set('serialize_precision', $previous); }
    }

    public static function centNumbers(): array { return [['7.01'], ['1'], ['10.1'], ['99999999.99']]; }

    public function testMoneyEncodingRejectsInvalidTokensAndMissingField(): void
    {
        foreach (['1e1', '7.019', '-1', '', null, [], true] as $amount) {
            try { JsonNotification::encode(['amount' => $amount], ['amount']); $this->fail('Expected invalid wire amount.'); }
            catch (RuntimeException $e) { $this->assertSame('Payment provider unavailable.', $e->getMessage()); $this->assertNull($e->getPrevious()); }
        }
        $this->expectException(RuntimeException::class);
        JsonNotification::encode(['name' => 'fixture'], ['amount']);
    }
}
