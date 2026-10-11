<?php

namespace App\Admin\Actions;

use App\Admin\Middleware\ResourcePermission;
use App\Admin\Support\BulkActionInput;
use App\Admin\Support\PaymentFormCatalog;
use App\Models\Pay;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BatchSetStatus extends BatchAction
{
    protected $model;
    protected $state;

    public function __construct(?string $model = null, $state = null, ?string $title = null)
    {
        parent::__construct($title);
        $this->model = $model;
        $this->state = $state;
        if ($model !== null) $this->addHtmlClass('admin-batch-status-' . BulkActionInput::RESOURCES[$model] . '-' . $state);
    }

    public function handle(Request $request)
    {
        $model = BulkActionInput::model($request->get('model'));
        $keys = BulkActionInput::keys($this->getKey());
        $state = BulkActionInput::status($request->get('field'), $request->get('state'));
        ResourcePermission::authorizeUpdate($model, $keys);
        try {
            (new $model())->getConnection()->transaction(function () use ($model, $keys, $state) {
                // Lock in key order, then validate the entire selection before any mutation.
                $rows = $model::query()->whereIn('id', $keys)->orderBy('id')->lockForUpdate()->get();
                if ($rows->count() !== count($keys)) abort(404, 'Selected rows are missing or deleted.');
                foreach ($rows as $row) {
                    if ($model === Pay::class && $state === Pay::STATUS_OPEN
                        && ($error = PaymentFormCatalog::error(['is_open' => $state], $row->getAttributes()))) {
                        abort(422, '渠道 #' . $row->id . '：' . $error);
                    }
                }
                foreach ($rows as $row) {
                    $row->is_open = $state;
                    if ($row->save() === false) throw new \RuntimeException('Status update was cancelled.');
                }
            });
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 422) throw $exception;
            return $this->response()->error($exception->getMessage());
        }
        return $this->response()->success('已更新所选记录。')->refresh();
    }

    protected function authorize($user): bool
    {
        try {
            $model = BulkActionInput::model($this->model ?? request()->get('model'));
            BulkActionInput::status('is_open', $this->state ?? request()->get('state'));
            $keys = $this->getKey() === null && $this->model !== null ? [] : BulkActionInput::keys($this->getKey());
            return ResourcePermission::canUpdate($user, $model, $keys, $this->model !== null);
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

    public function confirm()
    {
        $operation = $this->title();
        return ['确认' . $operation . '？', '只处理当前选中的记录；任一记录验证失败时整批保持原样。'];
    }

    public function parameters()
    {
        return ['model' => $this->model, 'field' => 'is_open', 'state' => $this->state];
    }
}
