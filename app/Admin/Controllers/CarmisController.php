<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Post\BatchRestore;
use App\Admin\Actions\Post\Restore;
use App\Admin\Forms\ImportCarmis;
use App\Admin\Repositories\Carmis;
use App\Models\Goods;
use App\Admin\Support\FormExperience;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use App\Models\Carmis as CarmisModel;
use Dcat\Admin\Widgets\Card;

class CarmisController extends AdminController
{


    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new Carmis(['goods']), function (Grid $grid) {
            $grid->model()->orderBy('id', 'DESC');
            $grid->column('id')->sortable();
            $grid->column('goods.gd_name', admin_trans('carmis.fields.goods_id'));
            $grid->column('status')->select(CarmisModel::getStatusMap());
            $grid->column('is_loop')->display(function($v){return $v==1?admin_trans('carmis.fields.yes'):"";});
            $grid->column('carmi')->limit(20);
            $grid->column('created_at');
            $grid->column('updated_at')->sortable();
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id');
                $filter->equal('goods_id')->select(
                    Goods::query()->where('type', Goods::AUTOMATIC_DELIVERY)->pluck('gd_name', 'id')
                );
                $filter->equal('status')->select(CarmisModel::getStatusMap());
                $filter->scope(admin_trans('dujiaoka.trashed'))->onlyTrashed();
            });
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $actions->append(new Restore(CarmisModel::class));
                }
            });
            $grid->batchActions(function (Grid\Tools\BatchActions $batch) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $batch->add(new BatchRestore(CarmisModel::class));
                }
            });
            $grid->export()->titles(['goods.gd_name' => admin_trans('carmis.fields.goods_id'), 'carmi' => admin_trans('carmis.fields.carmi'), 'created_at' => admin_trans('admin.created_at')]);
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
        return Show::make($id, new Carmis(['goods']), function (Show $show) {
            $show->field('id');
            $show->field('goods.gd_name', admin_trans('carmis.fields.goods_id'));
            $show->field('status')->as(function ($type) {
                if ($type == CarmisModel::STATUS_UNSOLD) {
                    return admin_trans('carmis.fields.status_unsold');
                } else {
                    return admin_trans('carmis.fields.status_sold');
                }
            });
			$show->field('is_loop')->as(function ($v) {return $v==1?admin_trans('carmis.fields.yes'):"";});
            $show->field('carmi');
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
        return Form::make(new Carmis(), function (Form $form) {
            FormExperience::intro($form, '单条卡密', '选择自动发货商品并填写一条完整卡密；大量卡密请使用批量导入。');
            $form->display('id');
            $form->select('goods_id', '所属商品')->options(
                Goods::query()->where('type', Goods::AUTOMATIC_DELIVERY)->pluck('gd_name', 'id')
            )->placeholder('请选择自动发货商品')->help('卡密将用于该商品的自动发货。')->required();
            FormExperience::section($form, '销售设置与内容', '普通卡密售出后退出可售库存，循环卡密可重复出售同一内容。');
            $form->radio('status', '销售状态')
                ->options(CarmisModel::getStatusMap())
                ->default(CarmisModel::STATUS_UNSOLD)->help('新增通常选择未售出；修改已售卡密前请核对订单。');
            $form->switch('is_loop', '循环出售')->default(false)->help('开启后同一条卡密可重复出售；请仅用于允许重复交付的内容。');
            $form->textarea('carmi', '卡密内容')->rows(6)->placeholder('例如：兑换码，或账号与密码的完整交付内容')->help('此处整体保存为一条卡密，多行内容也属于同一条。')->required();
            $form->display('created_at');
            $form->display('updated_at');
        });
    }

    /**
     * 导入卡密
     *
     * @param Content $content
     * @return Content
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function importCarmis(Content $content)
    {
        return $content
            ->title(admin_trans('carmis.fields.import_carmis'))
            ->body(new Card(new ImportCarmis()));
    }
}
