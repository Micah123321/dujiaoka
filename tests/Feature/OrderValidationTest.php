<?php

namespace Tests\Feature;

use App\Exceptions\RuleValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class OrderValidationTest extends TestCase
{
    private function validateInput(array $extra = []): void
    {
        app('Service\OrderService')->validatorCreateOrder(new Request(array_merge([
            'gid' => 1, 'email' => 'fixture@example.test', 'payway' => 1, 'by_amount' => 1,
        ], $extra)));
    }

    public function testDisabledOptionalChecksPermitMinimalOrder(): void
    {
        Cache::put('system-setting', [], 60);
        $this->validateInput();
        $this->addToAssertionCount(1);
    }

    public function testEnabledChecksRejectOmittedFields(): void
    {
        foreach (['is_open_geetest', 'is_open_img_code', 'is_open_search_pwd'] as $setting) {
            Cache::put('system-setting', [$setting => 1], 60);
            try {
                $this->validateInput();
                $this->fail('Missing enabled check accepted: ' . $setting);
            } catch (RuleValidationException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function testGeetestFailureIsEvaluatedRatherThanValidatorObjectTruthiness(): void
    {
        Cache::put('system-setting', ['is_open_geetest' => 1], 60);
        Validator::extend('geetest', function () { return false; });
        $this->expectException(RuleValidationException::class);
        $this->validateInput(['geetest_challenge' => 'invalid-fixture']);
    }

    public function testSuccessfulGeetestCanContinue(): void
    {
        Cache::put('system-setting', ['is_open_geetest' => 1], 60);
        Validator::extend('geetest', function () { return true; });
        $this->validateInput(['geetest_challenge' => 'valid-fixture']);
        $this->addToAssertionCount(1);
    }
}
