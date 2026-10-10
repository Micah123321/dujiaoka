<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class BaseModel extends Model
{

    const STATUS_OPEN = 1; // 状态开启
    const STATUS_CLOSE = 0; // 状态关闭

    const AUTOMATIC_DELIVERY = 1; // 自动发货
    const MANUAL_PROCESSING = 2; // 人工处理

    // Preserve the local clock shown by legacy grids and serialized storefront data.
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * map
     *
     * @return array
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public static function getIsOpenMap()
    {
        return [
            self::STATUS_OPEN => admin_trans('dujiaoka.status_open'),
            self::STATUS_CLOSE => admin_trans('dujiaoka.status_close')
        ];
    }

}
