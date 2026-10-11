<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Post\BatchRestore;
use App\Admin\Actions\BatchSetStatus;
use App\Admin\Actions\CloneResource;
use App\Admin\Actions\Post\Restore;
use App\Admin\Repositories\Pay;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use App\Models\Pay as PayModel;
use App\Admin\Support\FormExperience;
use App\Admin\Support\PaymentFormCatalog;

class PayController extends AdminController
{


    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new Pay(), function (Grid $grid) {
            $grid->column('id')->sortable();
            $grid->column('pay_name');
            $grid->column('pay_check', '付款标识')->display(function ($value) {
                return PaymentFormCatalog::isDraft($value) ? '<span class="badge admin-status-pending">克隆草稿 · 待选方式</span>' : e($value);
            });
            $grid->column('pay_method')->select(PayModel::getMethodMap());
            $grid->column('account_status', '账户配置')->display(function () {
                $profile = PaymentFormCatalog::all()[trim((string) $this->pay_handleroute, '/')] ?? null;
                $required = $profile ? array_keys(array_filter($profile['fields'], function ($field) { return $field['required']; })) : ['merchant_id', 'merchant_pem'];
                foreach ($required as $field) {
                    if (trim((string) $this->$field) === '') return '<span class="badge admin-status-pending">待补齐</span>';
                }
                return '<span class="badge admin-status-ready">已填写</span>';
            });
            $grid->column('pay_client')->select(PayModel::getClientMap());
            $grid->column('pay_handleroute', '支付服务')->display(function ($value) { return PaymentFormCatalog::routeOptions($value)[$value] ?? $value; });
            $grid->column('is_open')->switch();
            $grid->column('created_at');
            $grid->column('updated_at')->sortable();
            $grid->disableDeleteButton();
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id');
                $filter->equal('pay_check');
                $filter->like('pay_name');
                $filter->scope(admin_trans('dujiaoka.trashed'))->onlyTrashed();
            });
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $actions->append(new Restore(PayModel::class));
                } else {
                    $actions->append(new CloneResource(PayModel::class));
                }
            });
            $grid->batchActions(function (Grid\Tools\BatchActions $batch) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $batch->add(new BatchRestore(PayModel::class));
                } else {
                    $batch->add(new BatchSetStatus(PayModel::class, 1, '批量启用'));
                    $batch->add(new BatchSetStatus(PayModel::class, 0, '批量停用'));
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
        return Show::make($id, new Pay(), function (Show $show) {
            $show->field('id');
            $show->field('pay_name');
            $show->field('merchant_id')->as(function ($value) { return $value !== null && $value !== '' ? '已填写 · 进入编辑页查看或更换' : '未填写'; });
            $show->field('merchant_key')->as(function ($value) { return $value ? '已配置 · 进入编辑页查看或更换' : '未配置'; });
            $show->field('merchant_pem')->as(function ($value) { return $value ? '已配置 · 进入编辑页查看或更换' : '未配置'; });
            $show->field('pay_check');
            $show->field('pay_client')->as(function ($payClient) {
                return PayModel::getClientMap()[$payClient] ?? '未设置';
            });
            $show->field('pay_handleroute');
            $show->field('pay_method')->as(function ($payMethod) {
                if ($payMethod == PayModel::METHOD_JUMP) {
                    return admin_trans('pay.fields.method_jump');
                } else {
                    return admin_trans('pay.fields.method_scan');
                }
            });
            $show->field('is_open')->as(function ($isOpen) {
                if ($isOpen == PayModel::STATUS_OPEN) {
                    return admin_trans('dujiaoka.status_open');
                } else {
                    return admin_trans('dujiaoka.status_close');
                }
            });
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
        return Form::make(new Pay(), function (Form $form) {
            FormExperience::intro($form, '配置收款渠道', '先选择支付服务，再填写对应账户。可先保持停用保存，核对完成后启用。');
            $form->tab('1 · 选择服务', function (Form $form) {
                $form->select('pay_handleroute', '支付服务')->options(function ($value) {
                    return PaymentFormCatalog::routeOptions($value);
                })->required()->help('按服务名称选择，系统会对应到正确的支付处理路由。');
                FormExperience::guide($form, 'payment');
                $form->select('pay_check', '付款方式')->options(function ($value) {
                    return PaymentFormCatalog::checkOptions($value);
                })->config('tags', true)->required()->rules('max:50')->help('克隆草稿待选实际付款方式：先替换 draft-* 临时标识再启用。固定付款方式若已被占用，请编辑原渠道或选择该服务支持的另一方式。自建服务的扩展标识可直接输入。');
                $form->text('pay_name', '买家看到的名称')->required()->rules('max:200')->placeholder('例如：支付宝、微信扫码')->help('显示在买家选择付款方式的位置。');
                $form->radio('pay_client', '展示给哪些设备')->options(PayModel::getClientMap())->default(PayModel::PAY_CLIENT_ALL)->required()->rules('in:1,2,3')->help('一般选择通用；电脑网站或手机网站支付按服务要求选择。');
                $form->radio('pay_method', '付款页面形式')->options(PayModel::getMethodMap())->default(PayModel::METHOD_JUMP)->required()->rules('in:1,2')->help('选择付款方式时会给出对应建议。扫码表示在页面展示二维码。');
            });
            $form->tab('2 · 填写账户', function (Form $form) {
                FormExperience::section($form, '按所选服务填写', '字段名称和说明会随支付服务变化。编辑时原内容保持不变，切换服务后请逐项核对。');
                $form->text('merchant_id', '账户标识')->rules('nullable|max:200')->attribute('autocomplete', 'off');
                $form->textarea('merchant_key', '服务密钥或地址')->rows(4)->attribute('autocomplete', 'off');
                $form->textarea('merchant_pem', '私钥或服务地址')->rows(6)->attribute('autocomplete', 'off');
            });
            $form->tab('3 · 保存与启用', function (Form $form) {
                FormExperience::section($form, '完成后再开放收款', '停用时买家不会看到此渠道。启用前会检查账户必填项；保存仅存储配置，不会发起支付。');
                $form->switch('is_open', '向买家开放此渠道')->default(PayModel::STATUS_CLOSE);
                $form->display('id', '渠道编号');
                $form->display('updated_at', '最近修改');
            });
            $form->saving(function (Form $form) {
                $model = $form->model();
                $original = $model && $model->id ? $model->toArray() : [];
                if ($error = PaymentFormCatalog::error($form->input(), $original)) return $form->response()->error($error);
                foreach (['merchant_id', 'merchant_key', 'merchant_pem'] as $field) {
                    if (array_key_exists($field, $form->input())) $form->$field = (string) $form->input($field);
                }
            });
            $form->disableDeleteButton();
        });
    }
}
