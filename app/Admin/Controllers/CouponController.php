<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Post\BatchRestore;
use App\Admin\Actions\BatchSetStatus;
use App\Admin\Actions\Post\Restore;
use App\Admin\Repositories\Coupon;
use App\Models\Goods;
use Dcat\Admin\Admin;
use App\Admin\Support\FormExperience;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use App\Models\Coupon as CouponModel;

class CouponController extends AdminController
{

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new Coupon(['goods']), function (Grid $grid) {
            $grid->model()->orderBy('id', 'DESC');
            $grid->column('id')->sortable();
            $grid->column('discount');
            $grid->column('is_use')->select(CouponModel::getStatusUseMap());
            $grid->column('is_open')->switch();
            $grid->column('coupon')->copyable();
            $grid->column('ret');
            $grid->column('created_at');
            $grid->column('updated_at')->sortable();
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $actions->append(new Restore(CouponModel::class));
                }
            });
            $grid->batchActions(function (Grid\Tools\BatchActions $batch) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $batch->add(new BatchRestore(CouponModel::class));
                } else {
                    $batch->add(new BatchSetStatus(CouponModel::class, 1, '批量启用'));
                    $batch->add(new BatchSetStatus(CouponModel::class, 0, '批量停用'));
                }
            });
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id');
                $filter->equal('goods.goods_id', admin_trans('coupon.fields.goods_id'))->select(
                    Goods::query()->pluck('gd_name', 'id')
                );
                $filter->scope(admin_trans('dujiaoka.trashed'))->onlyTrashed();
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
        return Show::make($id, new Coupon(), function (Show $show) {
            $show->field('id');
            $show->field('discount');
            $show->field('is_use')->as(function ($isUse) {
                if ($isUse == CouponModel::STATUS_UNUSED) {
                    return admin_trans('coupon.fields.status_unused');
                } else {
                    return admin_trans('coupon.fields.status_use');
                }
            });
            $show->field('is_open')->as(function ($isOPen) {
                if ($isOPen == CouponModel::STATUS_OPEN) {
                    return admin_trans('dujiaoka.status_open');
                } else {
                    return admin_trans('dujiaoka.status_close');
                }
            });
            $show->field('coupon');
            $show->field('ret');
            $show->field('created_at');
            $show->field('updated_at');
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Form::make(Coupon::with('goods'), function (Form $form) {
            FormExperience::intro($form, '优惠券', '优惠券按固定金额减免；关联商品、保持启用且有可用次数时，客户才可使用。');
            $form->display('id');
            $form->multipleSelect('goods', '适用商品')->placeholder('选择可使用此优惠券的商品')->help('可选择多个商品；清空后保留优惠券，但没有可使用的商品。')
                ->options(Goods::all()->pluck('gd_name', 'id'))
                ->customFormat(function ($v) {
                    if (! $v) {
                        return [];
                    }
                    // 从数据库中查出的二维数组中转化成ID
                    return array_column($v, 'id');
                });
            FormExperience::section($form, '优惠金额与可用次数', '例如金额填 5 表示减免 5 元，可用次数填 10 表示最多再使用 10 次。');
            $form->currency('discount', '减免金额（元）')->default(0)->help('填写固定减免金额，例如 5.00 表示减 5 元。')->required();
            $form->text('coupon', '优惠码')->placeholder('例如：WELCOME5')->help('客户下单时输入的优惠码。')->required();
            $form->number('ret', '剩余可用次数')->default(1)->help('每次成功使用会扣减次数，0 表示没有可用次数。');
            $form->radio('is_use', '使用状态')->options(CouponModel::getStatusUseMap())->default(CouponModel::STATUS_UNUSED)->help('新优惠券通常选择未使用。');
            $form->switch('is_open', '启用优惠券')->default(CouponModel::STATUS_OPEN)->help('关闭后客户暂时停用此优惠码。');
            $form->display('created_at');
            $form->display('updated_at');
        });
    }
}
