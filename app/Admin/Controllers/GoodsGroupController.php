<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Post\BatchRestore;
use App\Admin\Actions\BatchSetStatus;
use App\Admin\Actions\Post\Restore;
use App\Admin\Repositories\GoodsGroup;
use App\Admin\Support\FormExperience;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use App\Models\GoodsGroup as GoodsGroupModel;

class GoodsGroupController extends AdminController
{

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new GoodsGroup(), function (Grid $grid) {
            $grid->model()->orderBy('id', 'DESC');
            $grid->column('id')->sortable();
            $grid->column('gp_name')->editable();
            $grid->column('is_open')->switch();
            $grid->column('ord')->editable();
            $grid->column('created_at');
            $grid->column('updated_at')->sortable();
            $grid->disableViewButton();
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id');
                $filter->scope(admin_trans('dujiaoka.trashed'))->onlyTrashed();
            });
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $actions->append(new Restore(GoodsGroupModel::class));
                }
            });
            $grid->batchActions(function (Grid\Tools\BatchActions $batch) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $batch->add(new BatchRestore(GoodsGroupModel::class));
                } else {
                    $batch->add(new BatchSetStatus(GoodsGroupModel::class, 1, '批量启用'));
                    $batch->add(new BatchSetStatus(GoodsGroupModel::class, 0, '批量停用'));
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
        return Show::make($id, new GoodsGroup(), function (Show $show) {
            $show->field('id');
            $show->field('gp_name');
            $show->field('is_open')->as(function ($isOpen) {
                if ($isOpen == GoodsGroupModel::STATUS_OPEN) {
                    return admin_trans('dujiaoka.status_open');
                } else {
                    return admin_trans('dujiaoka.status_close');
                }
            });
            $show->field('ord');
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
        return Form::make(new GoodsGroup(), function (Form $form) {
            FormExperience::intro($form, '商品分类', '先填写分类名称，再设置前台展示和排序；保存后可在商品中选择该分类。');
            $form->display('id');
            $form->text('gp_name', '分类名称')->placeholder('例如：软件授权、游戏充值')->help('用于商品分组和前台分类展示。');
            $form->switch('is_open', '前台展示')->default(GoodsGroupModel::STATUS_OPEN)->help('开启后该分类可在前台展示。');
            $form->number('ord', '排序')->default(1)->help(admin_trans('dujiaoka.ord'));
            $form->display('created_at');
            $form->display('updated_at');
            $form->disableViewButton();
            $form->footer(function ($footer) {
                // 去掉`查看`checkbox
                $footer->disableViewCheck();
            });
        });
    }
}
