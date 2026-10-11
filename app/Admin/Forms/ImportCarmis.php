<?php

namespace App\Admin\Forms;

use App\Admin\Support\FormExperience;
use App\Models\Carmis;
use App\Models\Goods;
use Dcat\Admin\Widgets\Form;
use Illuminate\Support\Facades\Storage;

class ImportCarmis extends Form
{

    /**
     * Handle the form request.
     *
     * @param array $input
     *
     * @return mixed
     */
    public function handle(array $input)
    {
        $pasted = (string) ($input['carmis_list'] ?? '');
        $uploaded = (string) ($input['carmis_txt'] ?? '');
        if ($pasted === '' && $uploaded === '') {
            return $this->response()->error(admin_trans('carmis.rule_messages.carmis_list_and_carmis_txt_can_not_be_empty'));
        }
        // Pasted content takes priority, including a card containing only "0".
        $carmisContent = $pasted !== '' ? $pasted : Storage::disk('public')->get($uploaded);
        $carmisData = [];
        $tempList = preg_split('/\r\n|\n|\r/', $carmisContent);
        foreach ($tempList as $val) {
            if (trim($val) != "") {
                $carmisData[] = [
                    'goods_id' => $input['goods_id'] ?? null,
                    'carmi' => trim($val),
                    'status' => Carmis::STATUS_UNSOLD,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
        }
        if (!$carmisData) {
            return $this->response()->error(admin_trans('carmis.rule_messages.carmis_list_and_carmis_txt_can_not_be_empty'));
        }
        $goodsId = $input['goods_id'] ?? null;
        if (!is_scalar($goodsId) || !ctype_digit((string) $goodsId) || !Goods::query()
            ->where('id', $goodsId)->where('type', Goods::AUTOMATIC_DELIVERY)->exists()) {
            return $this->response()->error('请选择有效的自动发货商品。');
        }
        if (($input['remove_duplication'] ?? 0) == 1) {
            $carmisData = assoc_unique($carmisData, 'carmi');
        }
        Carmis::query()->insert($carmisData);
        // 删除文件
        if (!empty($input['carmis_txt'])) {
            Storage::disk('public')->delete($input['carmis_txt']);
        }
        return $this
				->response()
				->success(admin_trans('carmis.rule_messages.import_carmis_success'))
				->location(admin_url('carmis'));
    }

    /**
     * Build a form here.
     */
    public function form()
    {
        FormExperience::intro($this, '批量导入卡密', '先选择自动发货商品，再粘贴卡密或上传 TXT 文件。每行一条，空行忽略；同时填写时仅导入粘贴内容。');
        $this->confirm(admin_trans('carmis.fields.are_you_import_sure'), '请核对商品与卡密内容；本次导入会增加该商品的可售卡密。');
        $this->select('goods_id', '所属商品')->options(
            Goods::query()->where('type', Goods::AUTOMATIC_DELIVERY)->pluck('gd_name', 'id')
        )->placeholder('请选择自动发货商品')->help('仅支持当前存在的自动发货商品。')->required();
        FormExperience::section($this, '导入内容', '粘贴与文件任选一种；首尾空格会移除，内容为 0 的卡密也会正常导入。');
        $this->textarea('carmis_list', '粘贴卡密')
            ->rows(12)->placeholder("CARD-EXAMPLE-001\nCARD-EXAMPLE-002")
            ->help('每行一条；同时上传文件时，粘贴内容优先并覆盖文件内容。');
        $this->file('carmis_txt', '上传 TXT 文件')
            ->disk('public')
            ->uniqueName()
            ->accept('txt')
            ->maxSize(5120)
            ->help('TXT 文件，每行一条，最大 5 MB；成功导入后删除本次上传文件。');
        $this->switch('remove_duplication', '本批去重')->default(false)->help('仅移除本次内容内相同的卡密，不与数据库已有卡密去重。');
    }

}
