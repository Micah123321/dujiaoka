<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Post\BatchRestore;
use App\Admin\Actions\BatchSetStatus;
use App\Admin\Actions\CloneResource;
use App\Admin\Actions\Post\Restore;
use App\Admin\Repositories\Goods;
use App\Models\Carmis;
use App\Models\Coupon;
use App\Models\GoodsGroup as GoodsGroupModel;
use Dcat\Admin\Admin;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use App\Models\Goods as GoodsModel;
use App\Admin\Support\FormExperience;
use App\Admin\Support\GoodsFormRules;

class GoodsController extends AdminController
{


    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new Goods(['group', 'coupon']), function (Grid $grid) {
            $grid->model()->orderBy('id', 'DESC');
            $grid->column('id')->sortable();
            $grid->column('picture')->image('', 100, 100);
            $grid->column('gd_name');
            $grid->column('gd_description');
            $grid->column('gd_keywords');
            $grid->column('group.gp_name', admin_trans('goods.fields.group_id'));
            $grid->column('type')
                ->using(GoodsModel::getGoodsTypeMap())
                ->label([
                    GoodsModel::AUTOMATIC_DELIVERY => Admin::color()->success(),
                    GoodsModel::MANUAL_PROCESSING => Admin::color()->info(),
                ]);
            $grid->column('retail_price');
            $grid->column('actual_price')->sortable();
            $grid->column('in_stock')->display(function () {
                // 如果为自动发货，则加载库存卡密
                if ($this->type == GoodsModel::AUTOMATIC_DELIVERY) {
                    return Carmis::query()->where('goods_id', $this->id)
                        ->where('status', Carmis::STATUS_UNSOLD)
                        ->count();
                } else {
                    return $this->in_stock;
                }
            });
            $grid->column('sales_volume');
            $grid->column('ord')->editable()->sortable();
            $grid->column('is_open')->switch();
            $grid->column('created_at')->sortable();
            $grid->column('updated_at');
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id');
                $filter->like('gd_name');
                $filter->equal('type')->select(GoodsModel::getGoodsTypeMap());
                $filter->equal('group_id')->select(GoodsGroupModel::query()->pluck('gp_name', 'id'));
                $filter->scope(admin_trans('dujiaoka.trashed'))->onlyTrashed();
                $filter->equal('coupon.coupons_id', admin_trans('goods.fields.coupon_id'))->select(
                    Coupon::query()->pluck('coupon', 'id')
                );
            });
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $actions->append(new Restore(GoodsModel::class));
                } else {
                    $actions->append(new CloneResource(GoodsModel::class));
                }
            });
            $grid->batchActions(function (Grid\Tools\BatchActions $batch) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $batch->add(new BatchRestore(GoodsModel::class));
                } else {
                    $batch->add(new BatchSetStatus(GoodsModel::class, 1, '批量上架'));
                    $batch->add(new BatchSetStatus(GoodsModel::class, 0, '批量下架'));
                }
            });
        });
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     *
     * @return Show
     */
    protected function detail($id)
    {
        return Show::make($id, new Goods(), function (Show $show) {
            $show->id('id');
            $show->field('gd_name');
            $show->field('gd_description');
            $show->field('gd_keywords');
            $show->field('picture')->image();
            $show->field('retail_price');
            $show->field('actual_price');
            $show->field('in_stock');
            $show->field('ord');
            $show->field('sales_volume');
            $show->field('type')->as(function ($type) {
                if ($type == GoodsModel::AUTOMATIC_DELIVERY) {
                    return admin_trans('goods.fields.automatic_delivery');
                } else {
                    return admin_trans('goods.fields.manual_processing');
                }
            });
            $show->field('is_open')->as(function ($isOpen) {
                if ($isOpen == GoodsGroupModel::STATUS_OPEN) {
                    return admin_trans('dujiaoka.status_open');
                } else {
                    return admin_trans('dujiaoka.status_close');
                }
            });
            $show->wholesale_price_cnf()->unescape()->as(function ($wholesalePriceCnf) {
                return  "<textarea class=\"form-control field_wholesale_price_cnf _normal_\"  rows=\"10\" cols=\"30\">" . e($wholesalePriceCnf) . "</textarea>";
            });
            $show->other_ipu_cnf()->unescape()->as(function ($otherIpuCnf) {
                return  "<textarea class=\"form-control field_wholesale_price_cnf _normal_\"  rows=\"10\" cols=\"30\">" . e($otherIpuCnf) . "</textarea>";
            });
            $show->api_hook()->unescape()->as(function ($apiHook) {
                return  "<textarea class=\"form-control field_wholesale_price_cnf _normal_\"  rows=\"10\" cols=\"30\">" . e($apiHook) . "</textarea>";
            });;
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Form::make(new Goods(), function (Form $form) {
            FormExperience::intro($form, '把商品信息填写清楚', '先填写名称、分类和售价，再选择自动发货或人工处理。批发优惠与买家资料都是选填。');
            $form->tab('1 · 基本信息', function (Form $form) {
                $form->text('gd_name', '商品名称')->required()->rules('max:200')->placeholder('例如：设计工具年度会员');
                $form->select('group_id', '商品分类')->options(GoodsGroupModel::query()->pluck('gp_name', 'id'))->required()->rules('exists:goods_group,id')->help('先建立分类再添加商品。<a target="_blank" rel="noopener" href="' . e(admin_url('goods-group/create')) . '">新建分类</a>');
                $form->currency('actual_price', '实际售价（元）')->default(0)->required()->rules('numeric|min:0')->help('买家实际付款的单价。填 0 表示免费商品，会直接进入发货流程。');
                $form->currency('retail_price', '划线原价（元）')->default(0)->rules('nullable|numeric|min:0')->help('仅用于展示对比价格；填 0 表示不设置。');
                $form->image('picture', '商品封面')->autoUpload()->uniqueName()->help('选填，未上传时使用默认图片。');
                $form->editor('description', '商品详情')->help('介绍商品内容、有效期、使用方法和售后说明。');
                $form->editor('buy_prompt', '购买前提示')->help('显示在购买位置附近，例如到账时间、适用范围或所需资料。');
            });
            $form->tab('2 · 发货与库存', function (Form $form) {
                FormExperience::section($form, '选择交付方式', '自动发货：付款后发送已导入的卡密。人工处理：付款后由你处理订单。');
                $form->radio('type', '发货方式')->options(GoodsModel::getGoodsTypeMap())->default(GoodsModel::AUTOMATIC_DELIVERY)->required()->rules('in:1,2');
                $form->number('in_stock', '人工可售库存')->default(0)->rules('nullable|integer|min:0')->help('仅人工处理使用。自动发货库存来自未售卡密，保存商品后到“导入卡密”补充。');
                $form->number('buy_limit_num', '每单最多购买')->default(0)->rules('nullable|integer|min:0')->help('填 0 表示不限，填 1 表示每张订单最多购买 1 件。');
                $form->number('sales_volume', '累计销量')->default(0)->rules('nullable|integer|min:0')->help('新商品一般保留 0，后续成交会自动增加。');
                $form->switch('is_open', '商品上架')->default(GoodsModel::STATUS_OPEN)->help('上架后买家可见。自动发货商品还需有未售卡密才有库存。');
            });
            $form->tab('3 · 优惠与买家资料', function (Form $form) {
                FormExperience::guide($form, 'goods');
                $form->textarea('wholesale_price_cnf', '批发优惠原始配置')->rows(4)->help('选填。一行一个：5=3 表示购买 5 件起每件 3 元。优先使用上方逐行编辑器。');
                $form->textarea('other_ipu_cnf', '买家填写项原始配置')->rows(4)->help('仅人工处理生效。选填，例如 account=充值账号=true；优先使用上方逐行编辑器。');
            });
            $form->tab('4 · 更多设置', function (Form $form) {
                FormExperience::section($form, '一般保持默认即可', '搜索展示信息和外部通知按需设置，不影响基础上架。');
                $form->text('gd_description', '搜索展示简介')->rules('nullable|max:200')->help('选填，留空时使用商品名称。用于列表及搜索展示，与上方商品详情不同。');
                $form->text('gd_keywords', '搜索关键词')->rules('nullable|max:200')->placeholder('例如：会员,设计,年度')->help('选填，留空时使用商品名称。');
                $form->text('api_hook', '订单通知地址')->rules('nullable|url')->placeholder('https://example.com/order-notify')->help('选填，付款后向该地址 POST 订单 JSON；接收方按订单号处理重复通知。无需外部对接请留空。');
                $form->number('ord', '展示排序')->default(1)->rules('integer|min:0')->help('数字越大越靠前，一般保留 1。');
            });
            $form->saving(function (Form $form) {
                $model = $form->model();
                $original = $model && $model->id ? $model->toArray() : [];
                if ($error = GoodsFormRules::error($form->input(), $original)) return $form->response()->error($error);
                foreach (['gd_description', 'gd_keywords'] as $field) {
                    if (array_key_exists('gd_name', $form->input()) && trim((string) $form->input($field)) === '') $form->$field = $form->input('gd_name');
                }
                foreach (['wholesale_price_cnf', 'other_ipu_cnf'] as $field) {
                    if (array_key_exists($field, $form->input())) {
                        $value = $form->input($field);
                        $form->$field = GoodsFormRules::same($value, $original[$field] ?? '') ? (string) ($original[$field] ?? '') : GoodsFormRules::normalize($value);
                    }
                }
            });
        });
    }
}
