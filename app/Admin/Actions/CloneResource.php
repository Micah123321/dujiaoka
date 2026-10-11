<?php

namespace App\Admin\Actions;

use App\Admin\Middleware\ResourcePermission;
use App\Admin\Support\BulkActionInput;
use App\Models\Goods;
use App\Models\Pay;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\RowAction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CloneResource extends RowAction
{
    protected $title = '克隆并编辑';
    protected $model;

    public function __construct(?string $model = null)
    {
        $this->model = $model;
        $this->addHtmlClass('admin-clone-resource');
    }

    public function handle(Request $request)
    {
        $model = BulkActionInput::model($request->get('model'), true);
        $key = BulkActionInput::keys([$this->getKey()])[0];
        if (!ResourcePermission::canClone(Admin::user(), $model, $key)) abort(403, __('admin.deny'));
        $copy = (new $model())->getConnection()->transaction(function () use ($model, $key) {
            $source = $model::query()->lockForUpdate()->findOrFail($key);
            // Only raw configuration is replicated; associated inventory/orders/pivots stay on the source.
            $copy = $source->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
            $copy->setRelations([]);
            $copy->is_open = 0;
            if ($model === Goods::class) {
                $copy->gd_name = mb_substr($source->gd_name, 0, 190) . '（副本）';
                $copy->in_stock = 0;
                $copy->sales_volume = 0;
            } else {
                $copy->pay_name = mb_substr($source->pay_name, 0, 190) . '（副本）';
                do {
                    $copy->pay_check = 'draft-' . Str::uuid();
                } while (Pay::withTrashed()->where('pay_check', $copy->pay_check)->exists());
            }
            if ($copy->save() === false) throw new \RuntimeException('Clone creation was cancelled.');
            return $copy;
        });
        return $this->response()->success('已创建停用副本，请核对后保存。')
            ->redirect(admin_url(BulkActionInput::RESOURCES[$model] . '/' . $copy->id . '/edit'));
    }

    protected function authorize($user): bool
    {
        try {
            $model = BulkActionInput::model($this->model ?? request()->get('model'), true);
            $key = BulkActionInput::keys([$this->getKey()])[0];
            return ResourcePermission::canClone($user, $model, $key);
        } catch (HttpException $exception) {
            return false;
        }
    }

    public function allowed()
    {
        return parent::allowed() && $this->passesAuthorization();
    }

    public function failedAuthorization()
    {
        abort(403, __('admin.deny'));
    }

    public function parameters()
    {
        return ['model' => $this->model];
    }
}
