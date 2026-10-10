<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */

namespace App\Admin\Actions\Post;


use App\Admin\Middleware\ResourcePermission;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchRestore extends BatchAction
{

    protected $title;

    protected $model;

    // 注意构造方法的参数必须要有默认值
    public function __construct(?string $model = null)
    {
        $this->title = admin_trans('dujiaoka.restore');
        $this->model = $model;
    }

    public function handle(Request $request)
    {
        $keys = (array) $this->getKey();
        $model = ResourcePermission::authorizeRestore($request->get('model'), $keys);

        (new $model())->getConnection()->transaction(function () use ($model, $keys) {
            $records = [];
            foreach ($keys as $key) {
                $records[] = $model::onlyTrashed()->lockForUpdate()->findOrFail($key);
            }
            foreach ($records as $record) {
                $record->restore();
            }
        });

        return $this->response()->success(admin_trans('dujiaoka.restored'))->refresh();
    }

    protected function authorize($user): bool
    {
        $model = $this->model !== null ? $this->model : request()->get('model');
        return ResourcePermission::canRestore($user, $model, (array) $this->getKey(), $this->model !== null);
    }

    public function allowed()
    {
        return parent::allowed() && $this->passesAuthorization();
    }

    public function confirm()
    {
        return [admin_trans('dujiaoka.are_you_restore_sure')];
    }

    public function parameters()
    {
        return [
            'model' => $this->model,
        ];
    }

}
