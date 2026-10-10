<?php

namespace App\Admin\Controllers;

use App\Admin\Service\OperationsService;
use App\Http\Controllers\Controller;
use Dcat\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Validation\ValidationException;

class OperationsController extends Controller
{
    public function page(Content $content, OperationsService $service, string $section)
    {
        return $content->header(\App\Admin\Support\OperationsAccess::sections()[$section])
            ->body(view('admin.workbench', ['config' => $service->pageConfig($section)]));
    }

    public function data(Request $request, OperationsService $service, string $section)
    {
        return $this->respond(function () use ($request, $service, $section) {
            return $service->data($section, $request->query());
        });
    }

    public function action(Request $request, OperationsService $service, string $section)
    {
        return $this->respond(function () use ($request, $service, $section) {
            return $service->action($section, $request->all());
        });
    }

    private function respond(callable $operation)
    {
        try {
            return response()->json($operation());
        } catch (ValidationException $exception) {
            return response()->json(['message' => '请求参数格式错误。'], 422);
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();
            $messages = [401 => '请先登录管理后台。', 403 => '当前账号没有此模块的操作权限。',
                404 => '记录或模块不存在。', 409 => '模块未启用、正在处理或记录状态已改变。', 422 => '请求参数格式错误。'];
            return response()->json(['message' => $messages[$status] ?? '操作暂未完成，请稍后重试。'], $status);
        } catch (\Throwable $exception) {
            // Never record transport bodies, SQL bindings, queue payloads or exception messages.
            Log::warning('admin.operations.failure', ['category' => 'operation_failed']);
            return response()->json(['message' => '操作暂未完成，请稍后重试。'], 503);
        }
    }
}
