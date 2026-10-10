<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/LinuxLifecycle/process.php';
require dirname(__DIR__) . '/ScheduleWorkerLifecycle/process.php';

function pr_copy($source, $destination, $relative = '', $sourceFilter = true)
{
    foreach (new DirectoryIterator($source) as $file) {
        if ($file->isDot()) continue;
        $path = $relative . $file->getFilename();
        if ($sourceFilter && (preg_match('#(^|/)(\.env[^/]*|[^/]*\.(pem|key|p12|pfx))$#i', $path) || in_array($path, ['bootstrap/cache', 'public/uploads', 'public/storage'], true))) continue;
        if ($file->isLink()) throw new RuntimeException('Source link requires explicit release handling: ' . $path);
        $target = $destination . '/' . $file->getFilename();
        if ($file->isDir()) { mkdir($target, 0700); pr_copy($file->getPathname(), $target, $path . '/', $sourceFilter); }
        elseif (!copy($file->getPathname(), $target)) throw new RuntimeException('Release copy failed: ' . $path);
    }
}

function pr_stage_packages($release, array $lock)
{
    $installed = json_decode(file_get_contents($release . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $packages = [];
    foreach ($installed['packages'] as $package) {
        if (isset($packages[$package['name']])) throw new RuntimeException('Duplicate installed package.');
        $packages[$package['name']] = $package;
    }
    if (count($lock['packages']) !== 100 || count($lock['packages-dev']) !== 29 || count($packages) !== 100 || $installed['dev'] !== false) throw new RuntimeException('Exact 100 runtime / 29 absent dev packages required.');
    foreach ($lock['packages'] as $package) {
        $actual = $packages[$package['name']] ?? null;
        if (!$actual || $actual['version'] !== $package['version']
            || ($actual['source']['reference'] ?? null) !== ($package['source']['reference'] ?? null)
            || ($actual['dist']['reference'] ?? null) !== ($package['dist']['reference'] ?? null)) throw new RuntimeException('Installed package provenance differs: ' . $package['name']);
    }
    foreach ($lock['packages-dev'] as $package) if (isset($packages[$package['name']])) throw new RuntimeException('Development package installed: ' . $package['name']);
    ksort($packages); return $packages;
}

$mode = null; $inputRun = null; $rootManifest = false;
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--root-manifest' && !$rootManifest) { $rootManifest = true; continue; }
    if (in_array($argv[$i], ['--from-installed', '--resume-install'], true) && $mode === null
        && isset($argv[$i + 1]) && $argv[$i + 1] !== '' && strpos($argv[$i + 1], '--') !== 0) {
        $mode = $argv[$i]; $inputRun = $argv[++$i]; continue;
    }
    throw new RuntimeException('Expected [--from-installed RUN | --resume-install RUN] [--root-manifest].');
}
if (PHP_OS_FAMILY !== 'Linux' || PHP_VERSION !== '8.5.11' || posix_geteuid() !== 1000) throw new RuntimeException('Existing Linux PHP8.5.11 UID1000 required.');
$root = ql_normalize(realpath(dirname(__DIR__, 3)));
$profile = ['name' => $rootManifest ? 'root' : 'candidate',
    'manifest_source' => $rootManifest ? 'composer.json' : 'tests/Compatibility/Laravel12/composer.json',
    'lock_source' => $rootManifest ? 'composer.lock' : 'tests/Compatibility/Laravel12/composer.lock'];
$profile['manifest_sha256'] = hash_file('sha256', $root . '/' . $profile['manifest_source']);
$profile['lock_sha256'] = hash_file('sha256', $root . '/' . $profile['lock_source']);
$sourceLock = json_decode(file_get_contents($root . '/' . $profile['lock_source']), true, 512, JSON_THROW_ON_ERROR);
$reuse = $mode === '--from-installed';
$reuseCfg = $reuse ? pr_manifest($inputRun) : null;
$reuseInputs = [];
if ($reuseCfg) {
    $reuseStage = json_decode(file_get_contents($reuseCfg['run'] . '/stage-summary.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($reuseStage['status'] !== 'PASS' || $reuseStage['failure'] || $reuseStage['cleanup_errors']
        || array_filter($reuseStage['processes'], function ($p) { return !$p['closed'] || $p['exit'] !== 0; })
        || array_filter($reuseStage['owned'], 'sw_child_alive')) throw new RuntimeException('Reusable original stage must be PASS and closed.');
    foreach ($reuseStage['processes'] as $process) {
        $live = sw_stat($process['pid']);
        if ($live && $live['start_ticks'] === $process['identity']['start_ticks']) throw new RuntimeException('Reusable stage process still exists.');
    }
    pr_owned_path($reuseCfg, $reuseCfg['release'] . '/vendor');
    $reuseLock = json_decode(file_get_contents($reuseCfg['release'] . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    if ($rootManifest) {
        if ($reuseLock['packages'] !== $sourceLock['packages'] || $reuseLock['packages-dev'] !== $sourceLock['packages-dev']) throw new RuntimeException('Reusable complete lock package metadata differs from root.');
    } elseif (hash_file('sha256', $reuseCfg['release'] . '/composer.lock') !== $profile['lock_sha256']) throw new RuntimeException('Reusable owned stage must match current candidate lock.');
    $reuseProof = json_decode(file_get_contents($reuseCfg['run'] . '/installed-proof.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($reuseProof['status'] !== 'PASS' || $reuseProof['lock_sha256'] !== hash_file('sha256', $reuseCfg['release'] . '/composer.lock')) throw new RuntimeException('Reusable original installed proof changed.');
    foreach (['marker', 'run.json', 'stage-summary.json', 'installed-proof.json', 'source-copy.json', 'release/composer.json', 'release/composer.lock'] as $file) $reuseInputs[$file] = hash_file('sha256', $reuseCfg['run'] . '/' . $file);
}
$resume = $mode === '--resume-install';
$resumeCfg = $resume ? pr_manifest($inputRun) : null;
if ($resumeCfg && (($resumeCfg['profile'] ?? null) !== $profile)
    && ($rootManifest || isset($resumeCfg['profile']))) throw new RuntimeException('Resume profile must match explicit mode and current manifest sources.');
$resumeAttempt = bin2hex(random_bytes(6));
if ($resumeCfg) {
    if (is_file($resumeCfg['run'] . '/runtime-summary.json')) throw new RuntimeException('Started runtime is not an incomplete install stage.');
    if (is_file($resumeCfg['run'] . '/stage-summary.json')) {
        $eligibility = json_decode(file_get_contents($resumeCfg['run'] . '/stage-summary.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($eligibility['status'] !== 'FAIL' || $eligibility['cleanup_errors'] || array_filter($eligibility['processes'], function ($p) { return !$p['closed']; })) throw new RuntimeException('Existing completed stage is preserved.');
    }
}
$marker = $resume ? $resumeCfg['marker'] : bin2hex(random_bytes(12));
$run = $resume ? $resumeCfg['run'] : $root . '/tests/.runtime/production-release/run-' . gmdate('Ymd-His') . '-' . $marker;
if (!$resume) mkdir($run, 0700, true);
$GLOBALS['ql_run'] = $run; $GLOBALS['ql_role'] = 'release-stage';
$GLOBALS['ql_config'] = ['root' => $root, 'marker' => $marker];
$GLOBALS['ll_processes'] = []; $GLOBALS['sw_owned_children'] = []; $GLOBALS['sw_groups'] = [];
if (!$resume) file_put_contents($run . '/events.jsonl', '');
$started = microtime(true); $failure = null; $cleanup = []; $cfg = null;
try {
    if (!$resume) {
    mkdir($run . '/release', 0700);
    foreach (['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'public', 'deploy'] as $dir) { mkdir($run . '/release/' . $dir, 0700); pr_copy($root . '/' . $dir, $run . '/release/' . $dir, $dir . '/'); }
    copy($root . '/artisan', $run . '/release/artisan');
    foreach (['bootstrap/cache', 'public/uploads', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs', 'storage/app', 'composer-home', 'composer-cache', 'tmp'] as $dir) {
        $target = in_array($dir, ['composer-home', 'composer-cache', 'tmp'], true) ? $run . '/' . $dir : $run . '/release/' . $dir;
        mkdir($target, 0700, true);
    }
    file_put_contents($run . '/release/.env', '');
    file_put_contents($run . '/release/install.lock', 'Synthetic production release fixture');
    touch($run . '/release/storage/fixture.sqlite');
    $manifest = json_decode(file_get_contents($root . '/' . $profile['manifest_source']), true, 512, JSON_THROW_ON_ERROR);
    if ($rootManifest) {
        if (!copy($root . '/composer.json', $run . '/release/composer.json')) throw new RuntimeException('Root manifest copy failed.');
    } else {
        $manifest['autoload'] = ['psr-4' => ['App\\' => 'app/'], 'files' => ['app/Helpers/functions.php']];
        unset($manifest['autoload-dev']);
        ql_json($run . '/release/composer.json', $manifest);
    }
    if (!copy($root . '/' . $profile['lock_source'], $run . '/release/composer.lock')) throw new RuntimeException('Profile lock copy failed.');
    file_put_contents($run . '/marker', $marker);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Release port reservation failed.');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
    $cfg = ['root' => $root, 'run' => $run, 'release' => $run . '/release', 'marker' => $marker, 'key' => 'base64:' . base64_encode(str_repeat('R', 32)),
        'url' => 'http://127.0.0.1:' . $port, 'port' => $port, 'ini' => ql_normalize(php_ini_loaded_file()), 'php' => ql_normalize(realpath(PHP_BINARY)), 'profile' => $profile];
    ql_json($run . '/run.json', $cfg);
    ql_json($run . '/source-copy.json', pr_hashes($cfg['release']));
    ql_check(!is_dir($cfg['release'] . '/tests') && !is_dir($cfg['release'] . '/vendor'), 'new_release_no_tests_or_vendor');
    } else {
        $cfg = $resumeCfg;
        if (file_exists($run . '/runtime-summary.json') || !is_file($run . '/installed-proof.json')) throw new RuntimeException('Only incomplete installed stage can resume.');
        if (is_file($run . '/stage-summary.json')) {
            $oldStage = json_decode(file_get_contents($run . '/stage-summary.json'), true, 512, JSON_THROW_ON_ERROR);
            if ($oldStage['status'] !== 'FAIL' || $oldStage['cleanup_errors'] || array_filter($oldStage['processes'], function ($p) { return !$p['closed']; })) throw new RuntimeException('Previous stage is not closed failed run.');
            if (!rename($run . '/stage-summary.json', $run . '/stage-failed-' . bin2hex(random_bytes(6)) . '.json')) throw new RuntimeException('Failed stage archive failed.');
        }
        $manifest = json_decode(file_get_contents($cfg['release'] . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $copied = json_decode(file_get_contents($run . '/source-copy.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($copied as $path => $hash) ql_check(hash_file('sha256', $cfg['release'] . '/' . $path) === $hash, 'resume_original_input_unchanged');
        ql_check(!is_dir($cfg['release'] . '/tests') && json_decode(file_get_contents($run . '/installed-proof.json'), true)['status'] === 'PASS', 'resume_explicit_installed_run');
    }
    if ($reuseCfg) {
        $reusePackages = pr_stage_packages($reuseCfg['release'], $sourceLock);
        mkdir($cfg['release'] . '/vendor', 0700);
        pr_copy($reuseCfg['release'] . '/vendor', $cfg['release'] . '/vendor', 'vendor/', false);
        $vendorHashes = pr_hashes($reuseCfg['release'] . '/vendor');
        ql_check($vendorHashes === pr_hashes($cfg['release'] . '/vendor'), 'reused_owned_vendor_all_bytes_equal');
        ql_check($reusePackages === pr_stage_packages($cfg['release'], $sourceLock), 'reused_owned_vendor_100_package_metadata_equal');
        foreach ($reuseInputs as $file => $hash) ql_check(hash_file('sha256', $reuseCfg['run'] . '/' . $file) === $hash, 'reused_original_stage_input_unchanged');
        ql_json($run . '/vendor-copy-proof.json', ['status' => 'PASS', 'source' => $reuseCfg['run'], 'files' => count($vendorHashes), 'hashes' => $vendorHashes,
            'packages' => $reusePackages, 'original_inputs' => $reuseInputs, 'tree_sha256' => hash('sha256', json_encode($vendorHashes, JSON_THROW_ON_ERROR))]);
    }
    $composer = '/mnt/e/php/composer.phar';
    ql_check(is_file($composer), 'existing_explicit_composer');
    putenv('COMPOSER_HOME=' . $run . '/composer-home'); putenv('COMPOSER_CACHE_DIR=' . $run . '/composer-cache');
    putenv('COMPOSER_NO_INTERACTION=1'); putenv('COMPOSER_PROCESS_TIMEOUT=180'); putenv('PHP_INI_SCAN_DIR=');
    $args = [PHP_BINARY, '-c', $cfg['ini'], $composer];
    foreach ([['validate', '--no-check-publish', '--no-plugins', '--no-interaction'],
        ['install', '--no-dev', '--prefer-dist', '--no-scripts', '--no-plugins', '--no-interaction', '--optimize-autoloader'],
        ['check-platform-reqs', '--no-dev', '--no-plugins', '--no-interaction']] as $index => $operation) {
        $label = ($resume ? 'resume-' . $resumeAttempt . '-composer-' : 'composer-') . $index;
        sw_spawn(array_merge($args, $operation, ['--working-dir=' . $cfg['release']]), $label);
        ql_check(sw_wait($label, $index === 1 ? 300 : 30) === 0, 'original_' . $label . '_exit0');
    }
    $installed = json_decode(file_get_contents($cfg['release'] . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $lock = json_decode(file_get_contents($cfg['release'] . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $expected = []; $actual = [];
    foreach ($lock['packages'] as $p) $expected[$p['name']] = $p['version'];
    foreach ($installed['packages'] as $p) $actual[$p['name']] = $p['version'];
    ksort($expected); ksort($actual);
    ql_check($actual === $expected && count($actual) === 100 && $installed['dev'] === false, 'exact_locked_100_runtime_no_dev');
    foreach ($lock['packages-dev'] as $p) ql_check(!isset($actual[$p['name']]), 'dev_package_absent_' . $p['name']);
    pr_stage_packages($cfg['release'], $lock);
    foreach (['manifest', 'lock'] as $input) ql_check(hash_file('sha256', $root . '/' . $profile[$input . '_source']) === $profile[$input . '_sha256'], 'profile_' . $input . '_source_unchanged');
    ql_check(hash_file('sha256', $cfg['release'] . '/composer.lock') === $profile['lock_sha256'], $rootManifest ? 'root_lock_bytes_unchanged' : 'candidate_lock_bytes_unchanged');
    if ($rootManifest) ql_check(hash_file('sha256', $cfg['release'] . '/composer.json') === $profile['manifest_sha256'], 'root_manifest_bytes_unchanged');
    if ($reuseCfg) foreach ($reuseInputs as $file => $hash) ql_check(hash_file('sha256', $reuseCfg['run'] . '/' . $file) === $hash, 'reused_original_stage_preserved_after_composer');
    ql_json($run . '/installed-proof.json', ['status' => 'PASS', 'installed' => $actual, 'dev' => $installed['dev'], 'manifest' => $manifest, 'profile' => $cfg['profile'] ?? $profile, 'lock_sha256' => hash_file('sha256', $cfg['release'] . '/composer.lock')]);
} catch (Throwable $e) { $failure = ['class' => get_class($e), 'message' => $e->getMessage()]; fwrite(STDERR, $e->getMessage() . PHP_EOL); }
finally {
    try { sw_track_children(); sw_cleanup_children(); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    foreach (array_keys($GLOBALS['ll_processes']) as $label) try { ll_cleanup_owned($label); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    $status = !$failure && !$cleanup ? 'PASS' : 'FAIL';
    $processes = array_map(function ($p) { unset($p['proc']); return $p; }, $GLOBALS['ll_processes']);
    ql_json($run . '/stage-summary.json', ['status' => $status, 'checks' => count(array_filter(array_map(function ($line) { return json_decode($line, true, 512, JSON_THROW_ON_ERROR); }, file($run . '/events.jsonl', FILE_SKIP_EMPTY_LINES)), function ($e) { return $e['event'] === 'CheckPassed'; })), 'elapsed' => microtime(true) - $started,
        'failure' => $failure, 'cleanup_errors' => $cleanup, 'processes' => $processes, 'owned' => array_values($GLOBALS['sw_owned_children']), 'run' => $run]);
    echo json_encode(['status' => $status, 'run' => $run], JSON_THROW_ON_ERROR), PHP_EOL;
}
exit($status === 'PASS' ? 0 : 1);
