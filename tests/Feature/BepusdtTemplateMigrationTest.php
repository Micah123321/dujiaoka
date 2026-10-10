<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BepusdtTemplateMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        Schema::create('pays', function (Blueprint $table) {
            $table->increments('id');
            $table->string('pay_name');
            $table->string('pay_check')->unique();
            $table->integer('pay_method');
            $table->integer('pay_client');
            $table->string('merchant_id')->nullable();
            $table->text('merchant_key')->nullable();
            $table->text('merchant_pem');
            $table->string('pay_handleroute');
            $table->integer('is_open');
            $table->timestamps();
            $table->softDeletes();
        });
        require_once base_path('database/migrations/2026_01_01_000009_add_bepusdt_payment_template.php');
    }

    public function testMissingTemplateIsAddedDisabledWithoutDuplicatingOtherChannels(): void
    {
        $this->channel('epusdt', ['pay_handleroute' => 'pay/epusdt']);
        $migration = new \AddBepusdtPaymentTemplate();
        $migration->up();
        $migration->up();
        $row = DB::table('pays')->where('pay_check', 'usdt.trc20')->first();

        $this->assertSame(2, DB::table('pays')->count());
        $this->assertSame('BEpusdt[USDT-TRC20]', $row->pay_name);
        $this->assertSame('pay/bepusdt', $row->pay_handleroute);
        $this->assertSame(1, (int) $row->pay_method);
        $this->assertSame(3, (int) $row->pay_client);
        $this->assertSame(0, (int) $row->is_open);
    }

    public function testExistingMerchantConfigurationSurvivesMigrationAndRollback(): void
    {
        $this->channel('usdt.trc20');
        $before = DB::table('pays')->first();
        $migration = new \AddBepusdtPaymentTemplate();
        $migration->up();
        $migration->down();

        $this->assertSame(1, DB::table('pays')->count());
        $this->assertEquals($before, DB::table('pays')->first());
    }

    public function testDeletedTemplateIsNeitherRestoredNorDuplicated(): void
    {
        $this->channel('usdt.trc20', ['deleted_at' => '2026-01-02 00:00:00']);
        $before = DB::table('pays')->first();
        (new \AddBepusdtPaymentTemplate())->up();

        $this->assertSame(1, DB::table('pays')->count());
        $this->assertEquals($before, DB::table('pays')->first());
    }

    private function channel(string $identifier, array $changes = []): void
    {
        DB::table('pays')->insert(array_merge([
            'pay_name' => 'Existing channel', 'pay_check' => $identifier,
            'pay_method' => 1, 'pay_client' => 3, 'merchant_id' => 'fixture-token',
            'merchant_key' => '', 'merchant_pem' => 'https://fixture.example',
            'pay_handleroute' => 'pay/bepusdt', 'is_open' => 1,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ], $changes));
    }
}
