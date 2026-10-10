<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */

namespace App\Service;


use App\Exceptions\RuleValidationException;
use App\Models\Carmis;
use App\Models\Goods;
use App\Models\GoodsGroup;

/**
 * 商品服务层
 *
 * Class GoodsService
 * @package App\Service
 * @author: Assimon
 * @email: Ashang@utf8.hk
 * @blog: https://utf8.hk
 * Date: 2021/5/30
 */
class GoodsService
{

    /**
     * 获取所有分类并加载该分类下的商品
     *
     * @return array|null
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function withGroup(): ?array
    {
        $seconds = max(0, min(60, (int) config('dujiaoka.catalog_cache_seconds', 10)));
        if ($seconds === 0 || \Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            return $this->loadCatalog();
        }
        $key = 'storefront:catalog:v1:' . app()->getLocale();
        try {
            $cached = \Illuminate\Support\Facades\Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $exception) {
            // A cache outage must not hide an otherwise available catalog.
        }
        $catalog = $this->loadCatalog();
        try {
            \Illuminate\Support\Facades\Cache::put($key, $catalog, $seconds);
        } catch (\Throwable $exception) {
            // Database results remain usable if the cache backend is unavailable.
        }
        return $catalog;
    }

    private function loadCatalog(): array
    {
        $goods = GoodsGroup::query()
            ->with(['goods' => function($query) {
                $query->withCount(['carmis' => function($query) {
                    $query->where('status', Carmis::STATUS_UNSOLD);
                }])->where('is_open', Goods::STATUS_OPEN)->orderBy('ord', 'DESC');
            }])
            ->where('is_open', GoodsGroup::STATUS_OPEN)
            ->orderBy('ord', 'DESC')
            ->get();
        return $goods->toArray();
    }

    /**
     * 商品详情
     *
     * @param int $id 商品id
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|object|null
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function detail(int $id)
    {
        $goods = Goods::query()
            ->with(['coupon'])
            ->withCount(['carmis' => function($query) {
                $query->where('status', Carmis::STATUS_UNSOLD);
            }])->where('id', $id)->first();
        return $goods;
    }

    /**
     * 格式化商品信息
     *
     * @param Goods $goods 商品模型
     * @return Goods
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function format(Goods $goods)
    {
        // 格式化批发配置以及输入框配置
        $goods->wholesale_price_cnf = $goods->wholesale_price_cnf ?
            format_wholesale_price($goods->wholesale_price_cnf) :
            null;
        // 如果存在其他配置输入框且为代充
        $goods->other_ipu = $goods->other_ipu_cnf ?
            format_charge_input($goods->other_ipu_cnf) :
            null;
        return $goods;
    }

    /**
     * 验证商品状态
     *
     * @param Goods $goods
     * @return Goods
     * @throws RuleValidationException
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function validatorGoodsStatus(Goods $goods): Goods
    {
        if (empty($goods)) {
            throw new RuleValidationException(__('dujiaoka.prompt.goods_does_not_exist'));
        }
        // 上架判断.
        if ($goods->is_open != Goods::STATUS_OPEN) {
            throw new RuleValidationException(__('dujiaoka.prompt.the_goods_is_not_on_the_shelves'));
        }
        return $goods;
    }

    /**
     * 库存减去
     *
     * @param int $id 商品id
     * @param int $number 出库数量
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function inStockDecr(int $id, int $number = 1): bool
    {
        return $number > 0 && Goods::query()->where('id', $id)
            ->where('in_stock', '>=', $number)->decrement('in_stock', $number);
    }

    /**
     * 商品销量加
     *
     * @param int $id 商品id
     * @param int $number 数量
     * @return bool
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function salesVolumeIncr(int $id, int $number = 1): bool
    {
        return Goods::query()->where('id', $id)->increment('sales_volume', $number);
    }

}
