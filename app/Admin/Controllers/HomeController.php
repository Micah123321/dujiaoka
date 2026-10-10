<?php

namespace App\Admin\Controllers;

use App\Admin\Service\OperationsService;
use App\Admin\Support\OperationsAccess;
use App\Http\Controllers\Controller;
use Dcat\Admin\Layout\Content;

class HomeController extends Controller
{
    public function index(Content $content)
    {
        if (!OperationsAccess::allows('overview', 'GET')) {
            foreach (array_keys(OperationsAccess::sections()) as $section) {
                if (OperationsAccess::allows($section, 'GET')) {
                    return redirect(OperationsAccess::url('operations/' . $section));
                }
            }
            $management = OperationsAccess::management();
            if ($management) return redirect($management[0]['href']);
            abort(403);
        }
        return $content
            ->header('经营概览')
            ->body(view('admin.workbench', ['config' => app(OperationsService::class)->pageConfig('overview')]));
    }

    public static function title()
    {
        return view('admin.dashboard.title');
    }
}
