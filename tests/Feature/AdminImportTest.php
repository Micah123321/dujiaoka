<?php

namespace Tests\Feature;

use App\Admin\Forms\ImportCarmis;
use App\Models\Carmis;
use Dcat\Admin\Http\JsonResponse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AdminImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'filesystems.default' => 'import-other',
            'admin.route.prefix' => 'admin',
            'admin.https' => false,
            'admin.secure' => false,
        ]);
        DB::purge('sqlite');
        Schema::create('carmis', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('goods_id');
            $table->text('carmi');
            $table->unsignedTinyInteger('status');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('goods', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedTinyInteger('type');
            $table->softDeletes();
        });
        DB::table('goods')->insert(['id' => 17, 'type' => 1]);
        Storage::fake('public');
        Storage::fake('import-other');
        URL::forceRootUrl('http://localhost');
        URL::forceScheme('http');
    }

    public function test_text_only_import_accepts_normalized_null_upload_and_keeps_duplicates(): void
    {
        $request = Request::create('/admin/dcat-api/form', 'POST', [
            'goods_id' => 17,
            'carmis_list' => implode(PHP_EOL, [' alpha ', '', ' 0 ', '   ', 'alpha', ' beta ']),
            'carmis_txt' => '',
            'remove_duplication' => 0,
        ]);
        $response = (new ConvertEmptyStringsToNull())->handle($request, function ($request) {
            $this->assertNull($request->input('carmis_txt'));

            return $this->import($request->all());
        });

        $this->assertSuccess($response);
        $this->assertCards(['alpha', '0', 'alpha', 'beta']);
    }

    public function test_text_only_import_deduplicates_when_upload_key_is_missing(): void
    {
        $response = $this->import([
            'goods_id' => 17,
            'carmis_list' => implode(PHP_EOL, [' alpha ', '0', '', 'alpha', ' 0 ', ' beta ']),
            'remove_duplication' => 1,
        ]);

        $this->assertSuccess($response);
        $this->assertCards(['alpha', '0', 'beta']);
    }

    public function test_text_only_import_accepts_empty_upload_path(): void
    {
        $response = $this->import([
            'goods_id' => 17,
            'carmis_list' => 'alpha',
            'carmis_txt' => '',
            'remove_duplication' => 0,
        ]);

        $this->assertSuccess($response);
        $this->assertCards(['alpha']);
    }

    public function test_import_splits_crlf_lines(): void
    {
        $this->assertLineEndingImport("\r\n");
    }

    public function test_import_splits_lf_lines(): void
    {
        $this->assertLineEndingImport("\n");
    }

    public function test_import_splits_cr_lines(): void
    {
        $this->assertLineEndingImport("\r");
    }

    public function test_uploaded_file_is_read_and_deleted_on_public_disk_only(): void
    {
        $path = 'imports/cards.txt';
        Storage::disk('public')->put($path, 'public-card');
        Storage::disk('import-other')->put($path, 'other-card');

        $response = $this->import([
            'goods_id' => 17,
            'carmis_list' => null,
            'carmis_txt' => $path,
            'remove_duplication' => 0,
        ]);

        $this->assertSuccess($response);
        $this->assertCards(['public-card']);
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertSame('other-card', Storage::disk('import-other')->get($path));
    }

    public function test_success_location_uses_admin_prefix(): void
    {
        $this->assertLocationForPrefix('admin');
    }

    public function test_success_location_uses_custom_admin_prefix(): void
    {
        $this->assertLocationForPrefix('staff/backend');
    }

    public function test_normalized_empty_content_returns_existing_error_without_importing(): void
    {
        $request = Request::create('/admin/dcat-api/form', 'POST', [
            'goods_id' => 17,
            'carmis_list' => '',
            'carmis_txt' => '',
            'remove_duplication' => 1,
        ]);
        $response = (new ConvertEmptyStringsToNull())->handle($request, function ($request) {
            return $this->import($request->all());
        });

        $this->assertEmptyContentError($response);
    }

    public function test_missing_content_returns_existing_error_without_importing(): void
    {
        $this->assertEmptyContentError($this->import([
            'goods_id' => 17,
            'remove_duplication' => 0,
        ]));
    }

    public function test_single_zero_is_a_valid_card_and_invalid_manual_or_deleted_goods_never_receive_cards(): void
    {
        $this->assertSuccess($this->import(['goods_id' => 17, 'carmis_list' => '0']));
        $this->assertCards(['0']);
        foreach ([18, 19, 9999, ['17']] as $goods) {
            DB::table('goods')->insertOrIgnore(['id' => 18, 'type' => 2]);
            DB::table('goods')->insertOrIgnore(['id' => 19, 'type' => 1, 'deleted_at' => date('Y-m-d H:i:s')]);
            $result = $this->import(['goods_id' => $goods, 'carmis_list' => 'SHOULD-NOT-IMPORT']);
            $this->assertFalse($result['status']);
            $this->assertCards(['0']);
        }
    }

    private function import(array $input): array
    {
        $response = (new ImportCarmis())->handle($input);
        $this->assertInstanceOf(JsonResponse::class, $response);

        // Inspect each installed Dcat version's actual serialized widget response.
        $httpResponse = $response->send();
        $this->assertSame(200, $httpResponse->getStatusCode());

        return $httpResponse->getData(true);
    }

    private function assertSuccess(array $response): void
    {
        $this->assertTrue($response['status']);
        $this->assertSame('success', $response['data']['type']);
        $this->assertSame(admin_trans('carmis.rule_messages.import_carmis_success'), $response['data']['message']);
    }

    private function assertCards(array $expected): void
    {
        $cards = Carmis::query()->orderBy('id')->get();
        $actual = $cards->pluck('carmi')->all();
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        foreach ($cards as $card) {
            $this->assertSame(17, (int) $card->goods_id);
            $this->assertSame(Carmis::STATUS_UNSOLD, (int) $card->status);
            $this->assertNotNull($card->created_at);
            $this->assertNotNull($card->updated_at);
        }
    }

    private function assertLineEndingImport(string $lineEnding): void
    {
        // A real upload isolates line splitting from the empty upload-path regression.
        $path = 'imports/line-endings.txt';
        Storage::disk('public')->put($path, implode($lineEnding, [' alpha ', '', ' 0 ', '   ', 'alpha', ' beta ', '']));
        $response = $this->import([
            'goods_id' => 17,
            'carmis_list' => null,
            'carmis_txt' => $path,
            'remove_duplication' => 1,
        ]);

        $this->assertSuccess($response);
        $this->assertCards(['alpha', '0', 'beta']);
        $this->assertFalse(Storage::disk('public')->exists($path));
    }

    private function assertLocationForPrefix(string $prefix): void
    {
        config(['admin.route.prefix' => $prefix]);
        $path = 'imports/location.txt';
        Storage::disk('public')->put($path, 'alpha');
        $response = $this->import([
            'goods_id' => 17,
            'carmis_txt' => $path,
            'remove_duplication' => 0,
        ]);

        $this->assertSuccess($response);
        $this->assertCards(['alpha']);
        $this->assertSame([
            'action' => 'location',
            'value' => 'http://localhost/' . $prefix . '/carmis',
        ], $response['data']['then']);
    }

    private function assertEmptyContentError(array $response): void
    {
        $this->assertFalse($response['status']);
        $this->assertSame('error', $response['data']['type']);
        $this->assertSame(admin_trans('carmis.rule_messages.carmis_list_and_carmis_txt_can_not_be_empty'), $response['data']['message']);
        $this->assertArrayNotHasKey('then', $response['data']);
        $this->assertSame(0, Carmis::query()->count());
    }
}
