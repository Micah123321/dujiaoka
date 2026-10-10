<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminDateSerializationTest extends TestCase
{
    /** @dataProvider businessModels */
    #[\PHPUnit\Framework\Attributes\DataProvider('businessModels')]
    public function testBusinessTimestampsKeepTheLegacyLocalClockFormat($class)
    {
        $model = new $class;
        $model->setRawAttributes(['id' => 1, 'created_at' => '2026-10-09 12:34:56', 'updated_at' => '2026-10-09 13:45:00', 'deleted_at' => null]);
        $data = $model->toArray();
        $this->assertSame('2026-10-09 12:34:56', $data['created_at']);
        $this->assertSame('2026-10-09 13:45:00', $data['updated_at']);
        $this->assertNull($data['deleted_at']);
        $this->assertSame($data, json_decode($model->toJson(), true));
    }

    public static function businessModels()
    {
        return [
            [\App\Models\Goods::class], [\App\Models\GoodsGroup::class], [\App\Models\Carmis::class],
            [\App\Models\Coupon::class], [\App\Models\Emailtpl::class], [\App\Models\Pay::class], [\App\Models\Order::class],
        ];
    }
}
