<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Post\BatchRestore;
use App\Admin\Actions\Post\Restore;
use App\Admin\Repositories\Order;
use App\Models\Coupon;
use App\Models\Goods;
use App\Models\Pay;
use Dcat\Admin\Admin;
use App\Admin\Support\FormExperience;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use App\Models\Order as OrderModel;

class OrderController extends AdminController
{


    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new Order(['goods', 'coupon', 'pay']), function (Grid $grid) {
            $grid->model()->orderBy('id', 'DESC');
            $grid->column('id')->sortable();
            $grid->column('order_sn')->copyable();
            $grid->column('title');
            $grid->column('type')->using(OrderModel::getTypeMap())
                ->label([
                    OrderModel::AUTOMATIC_DELIVERY => Admin::color()->success(),
                    OrderModel::MANUAL_PROCESSING => Admin::color()->info(),
                ]);
            $grid->column('email')->copyable();
            $grid->column('goods.gd_name', admin_trans('order.fields.goods_id'));
            $grid->column('goods_price');
            $grid->column('buy_amount');
            $grid->column('total_price');
            $grid->column('coupon.coupon', admin_trans('order.fields.coupon_id'));
            $grid->column('coupon_discount_price');
            $grid->column('wholesale_discount_price');
            $grid->column('actual_price');
            $grid->column('pay.pay_name', admin_trans('order.fields.pay_id'));
            $grid->column('buy_ip');
            $grid->column('search_pwd')->copyable();
            $grid->column('trade_no')->copyable();
            $grid->column('status')
                ->select(OrderModel::getStatusMap());
            $grid->column('created_at');
            $grid->column('updated_at')->sortable();
            $grid->disableCreateButton();
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('order_sn');
                $filter->like('title');
                $filter->equal('status')->select(OrderModel::getStatusMap());
                $filter->equal('email');
                $filter->equal('trade_no');
                $filter->equal('type')->select(OrderModel::getTypeMap());
                $filter->equal('goods_id')->select(Goods::query()->pluck('gd_name', 'id'));
                $filter->equal('coupon_id')->select(Coupon::query()->pluck('coupon', 'id'));
                $filter->equal('pay_id')->select(Pay::query()->pluck('pay_name', 'id'));
                $filter->whereBetween('created_at', function ($q) {
                    $start = $this->input['start'] ?? null;
                    $end = $this->input['end'] ?? null;
                    $q->where('created_at', '>=', $start)
                        ->where('created_at', '<=', $end);
                })->datetime();
                $filter->scope(admin_trans('dujiaoka.trashed'))->onlyTrashed();
            });
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $actions->append(new Restore(OrderModel::class));
                }
            });
            $grid->batchActions(function (Grid\Tools\BatchActions $batch) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $batch->add(new BatchRestore(OrderModel::class));
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
        return Show::make($id, new Order(['goods', 'coupon', 'pay']), function (Show $show) {
            $show->field('id');
            $show->field('order_sn');
            $show->field('title');
            $show->field('email');
            $show->field('goods.gd_name', admin_trans('order.fields.goods_id'));
            $show->field('goods_price');
            $show->field('buy_amount');
            $show->field('coupon.coupon', admin_trans('order.fields.coupon_id'));
            $show->field('coupon_discount_price');
            $show->field('wholesale_discount_price');
            $show->field('total_price');
            $show->field('actual_price');
            $show->field('buy_ip');
            $show->field('info')->unescape()->as(function ($info) {
                return  "<textarea class=\"form-control field_wholesale_price_cnf _normal_\"  rows=\"10\" cols=\"30\">" . $info . "</textarea>";
            });
            $show->field('pay.pay_name', admin_trans('order.fields.pay_id'));
            $show->field('status')->using(OrderModel::getStatusMap());
            $show->field('search_pwd');
            $show->field('trade_no');
            $show->field('type')->using(OrderModel::getTypeMap());
            $show->field('created_at');
            $show->field('updated_at');
            $show->disableEditButton();
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Form::make(new Order(['goods', 'coupon', 'pay']), function (Form $form) {
            FormExperience::intro($form, '维护订单', '先核对订单金额、客户邮箱和交付内容，再修改状态。人工订单的部分状态变更会向客户发送邮件。');
            $form->confirm('确认保存订单', '人工订单状态变为待处理、已完成或失败时，将按现有流程向客户发送邮件。请确认状态与交付内容后提交。');
            $form->display('id');
            $form->display('order_sn');
            $form->text('title', '订单标题')->placeholder('填写便于识别的订单标题');
            $form->display('goods.gd_name', admin_trans('order.fields.goods_id'));
            $form->display('goods_price');
            $form->display('buy_amount');
            $form->display('coupon.coupon', admin_trans('order.fields.coupon_id'));
            $form->display('coupon_discount_price');
            $form->display('wholesale_discount_price');
            $form->display('total_price');
            $form->display('actual_price');
            $form->display('email');
            FormExperience::section($form, '交付内容与状态', '状态邮件会使用订单信息，请先核对交付内容是否适合发送给客户。');
            $form->textarea('info', '交付内容 / 订单信息')->rows(8)->placeholder('填写给客户的交付内容或处理说明')->help('人工订单状态通知可能包含此内容。');
            $form->display('buy_ip');
            $form->display('pay.pay_name', admin_trans('order.fields.pay_id'));
            $form->radio('status', '订单状态')->options(OrderModel::getStatusMap())->help('人工订单变为待处理、已完成或失败时会触发客户邮件，请谨慎选择。');
            $form->text('search_pwd', '订单查询密码')->placeholder('客户用于查询订单的密码')->help('修改后请告知客户新的查询密码。');
            $form->display('trade_no');
            $form->radio('type', '处理方式')->options(OrderModel::getTypeMap())->help('请保持与实际订单处理方式一致；修改此项不会自动补发卡密。');
            $form->display('created_at');
            $form->display('updated_at');
        });
    }
}
