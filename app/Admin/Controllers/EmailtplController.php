<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Post\BatchRestore;
use App\Admin\Actions\Post\Restore;
use App\Admin\Repositories\Emailtpl;
use App\Admin\Support\FormExperience;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use App\Models\Emailtpl as EmailTplModel;

class EmailtplController extends AdminController
{
    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new Emailtpl(), function (Grid $grid) {
            $grid->column('id')->sortable();
            $grid->column('tpl_name');
            $grid->column('tpl_token');
            $grid->column('created_at');
            $grid->column('updated_at')->sortable();
            $grid->disableViewButton();
            $grid->disableDeleteButton();
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id');
                $filter->like('tpl_name');
                $filter->like('tpl_token');
                $filter->scope(admin_trans('dujiaoka.trashed'))->onlyTrashed();
            });
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $actions->append(new Restore(EmailTplModel::class));
                }
            });
            $grid->batchActions(function (Grid\Tools\BatchActions $batch) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $batch->add(new BatchRestore(EmailTplModel::class));
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
        return Show::make($id, new Emailtpl(), function (Show $show) {
            $show->field('id');
            $show->field('tpl_name');
            $show->field('tpl_content');
            $show->field('tpl_token');
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
        return Form::make(new Emailtpl(), function (Form $form) {
            FormExperience::intro($form, '邮件模板', '模板名称用作邮件标题；正文支持单花括号变量。模板标识决定使用场景，编辑时保持锁定。');
            $form->display('id');
            $form->text('tpl_name', '邮件标题')->placeholder('例如：您的订单已完成')->help('客户收到邮件时显示的标题。')->required();
            FormExperience::section($form, '正文与变量', '常用变量：{webname} 商店名称、{order_id} 订单号、{ord_title} 订单标题、{ord_price} 实付金额、{ord_info} 订单内容、{created_at} 时间、{weburl} 网站地址。');
            $form->editor('tpl_content', '邮件正文')->help('使用单花括号，例如 {order_id}；发货与管理员新订单模板另支持 {product_name}、{buy_amount}。')->required();
            if ($form->isCreating()) {
                $tokens = [
                    'card_send_user_email' => '自动发货：发送给客户',
                    'manual_send_manage_mail' => '人工订单：提醒管理员',
                    'pending_order' => '人工订单：待处理通知',
                    'completed_order' => '人工订单：完成通知',
                    'failed_order' => '人工订单：失败通知',
                ];
                foreach (EmailTplModel::query()->pluck('tpl_token')->all() as $token) {
                    if (!isset($tokens[$token])) $tokens[$token] = $token . '（已有自定义标识）';
                }
                $form->select('tpl_token', '使用场景 / 模板标识')->options($tokens)->placeholder('请选择与实际用途对应的标识')->config('tags', true)->help('内置流程按固定标识查找模板；同一标识请编辑原模板。自定义流程可输入新的标识。')->rules('unique:emailtpls,tpl_token|max:200')->required();
            } else {
                $form->text('tpl_token', '模板标识')->help('标识已锁定，保留原有内置或自定义标识。')->disable();
            }
            $form->display('created_at');
            $form->display('updated_at');
            $form->disableViewButton();
            $form->disableDeleteButton();
            $form->footer(function ($footer) {
                // 去掉`查看`checkbox
                $footer->disableViewCheck();
            });
        });
    }
}
