<?php
function pr_command(array $args, $label, $seconds = 45)
{
    $phpCommand = $args[0] === PHP_BINARY;
    if ($phpCommand) {
        putenv('PRODUCTION_RELEASE_RUN=' . $GLOBALS['ql_run']); putenv('PRODUCTION_RELEASE_COMMAND=' . $label);
        array_splice($args, 1, 0, ['-d', 'auto_prepend_file=' . __DIR__ . '/cli-prepend.php']);
    }
    sw_spawn($args, $label);
    ql_check(sw_wait($label, $seconds) === 0, 'native_' . $label . '_exit0');
    $output = file_get_contents($GLOBALS['ql_run'] . '/' . $label . '.stdout') . file_get_contents($GLOBALS['ql_run'] . '/' . $label . '.stderr');
    ql_check(!preg_match('/(?:^|[\r\n])(?:PHP )?(?:Fatal error|Warning|Notice|Deprecated|Parse error):/', $output), 'native_' . $label . '_no_reported_php_diagnostic');
    if ($phpCommand) {
        $proof = json_decode(file_get_contents($GLOBALS['ql_run'] . '/' . $label . '.php-proof.json'), true, 512, JSON_THROW_ON_ERROR);
        ql_check($proof['last_php_error'] === null && $proof['php'] === PHP_VERSION && $proof['sapi'] === 'cli', 'native_' . $label . '_raw_php_shutdown_proof');
        ql_check(!$proof['php_observer']['issues'] && (!$proof['identity'] || $proof['php_observer']['installed']), 'native_' . $label . '_framework_php_diagnostics');
        if (in_array($label, ['config-cache', 'route-cache'], true)) {
            $apps = $proof['php_observer']['applications'] ?? [];
            ql_check(count($apps) === 2 && $apps[0]['id'] !== $apps[1]['id']
                && $apps[0]['base'] === $proof['identity']['base'] && $apps[1]['base'] === $proof['identity']['base'], 'native_' . $label . '_two_actual_app_observers');
        }
    }
}

