<?php
require __DIR__ . '/bootstrap.php';
$cfg = hr_manifest(getenv('HTTP_RUNTIME_RUN'));
if (PHP_SAPI !== 'fpm-fcgi' || PHP_VERSION !== '8.5.11' || posix_geteuid() !== 1000
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:' . $cfg['port']) {
    http_response_code(403); exit('HTTP fixture identity mismatch');
}
hr_environment($cfg);
header('X-HTTP-Fixture: ' . $cfg['marker']);
header('X-HTTP-SAPI: ' . PHP_SAPI);
header('X-HTTP-PHP: ' . PHP_VERSION);
register_shutdown_function(function () use ($cfg) {
    $app = Illuminate\Container\Container::getInstance();
    $request = $app->bound('request') ? $app->make('request') : null;
    $event = ['event' => 'HttpRequest', 'pid' => getmypid(), 'time' => microtime(true),
        'uid' => posix_geteuid(), 'gid' => posix_getegid(), 'sapi' => PHP_SAPI, 'ini' => php_ini_loaded_file(),
        'ini_sha256' => hash_file('sha256', php_ini_loaded_file()), 'scanned_ini' => php_ini_scanned_files(),
        'framework' => $app->version(), 'base' => $app->basePath(), 'storage' => $app->storagePath(),
        'environment' => $app->environment(), 'request_id' => $_SERVER['HTTP_X_HTTP_FIXTURE_REQUEST'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '', 'route' => $_SERVER['REQUEST_URI'] ?? '', 'status' => http_response_code(),
        'scheme' => $request ? $request->getScheme() : null, 'client_ip' => $request ? $request->getClientIp() : null,
        'db' => $app->make('config')->get('database.connections.sqlite.database'),
        'source_index_sha256' => hash_file('sha256', $_SERVER['SCRIPT_FILENAME']),
        'error_type' => error_get_last()['type'] ?? null, 'last_php_error' => error_get_last()];
    file_put_contents($cfg['run'] . '/http-events.jsonl', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
});
