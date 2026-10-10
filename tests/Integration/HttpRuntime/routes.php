<?php
use Illuminate\Support\Facades\Route;

// These routes exist only in the owned release copy, behind the original web middleware.
Route::get('/__runtime42/identity', function (Illuminate\Http\Request $request) {
    return response()->json(['scheme' => $request->getScheme(), 'host' => $request->getHost(),
        'port' => $request->getPort(), 'ip' => $request->getClientIp(), 'csrf' => csrf_token(),
        'session' => $request->session()->getId(), 'counter' => $request->session()->get('counter', 0)]);
});
Route::post('/__runtime42/session', function (Illuminate\Http\Request $request) {
    $counter = (int) $request->session()->get('counter', 0) + 1;
    $request->session()->put('counter', $counter);
    return response()->json(['counter' => $counter, 'session' => $request->session()->getId()]);
});
Route::get('/__runtime42/failure', function () { throw new RuntimeException('Private synthetic detail'); });
