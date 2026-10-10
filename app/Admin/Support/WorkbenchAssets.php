<?php

namespace App\Admin\Support;

use Dcat\Admin\Admin;

class WorkbenchAssets
{
    public static function register(): void
    {
        $files = self::files();
        if ($files['css']) Admin::css($files['css']);
        if ($files['js']) Admin::js($files['js']);
    }

    public static function files(): array
    {
        $empty = ['css' => [], 'js' => []];
        $directory = realpath(public_path('assets/admin'));
        $manifest = public_path('assets/admin/manifest.json');
        if (!$directory || !is_file($manifest) || filesize($manifest) > 65536) return $empty;
        try {
            $data = json_decode(file_get_contents($manifest), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return $empty;
        }
        if (!is_array($data)) return $empty;
        $entry = $data['src/admin/main.ts'] ?? null;
        if (!is_array($entry) || empty($entry['isEntry'])) return $empty;
        $javascript = self::localFile($directory, $entry['file'] ?? null, 'js');
        if (!$javascript) return $empty;
        $styles = (array) ($entry['css'] ?? []);
        if (isset($data['style.css'])) {
            if (!is_array($data['style.css'])) return $empty;
            $styles[] = $data['style.css']['file'] ?? null;
        }
        if (!$styles) return $empty;
        $css = [];
        foreach ($styles as $file) {
            $url = self::localFile($directory, $file, 'css');
            if (!$url) return $empty;
            $css[] = $url;
        }
        return ['css' => array_values(array_unique($css)), 'js' => [$javascript]];
    }

    private static function localFile(string $directory, $file, string $extension): ?string
    {
        if (!is_string($file) || !preg_match('/\Aassets\/[A-Za-z0-9_-]+\.' . $extension . '\z/', $file)) return null;
        $resolved = realpath($directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));
        $prefix = str_replace('\\', '/', $directory) . '/';
        if (!$resolved || !is_file($resolved) || !str_starts_with(str_replace('\\', '/', $resolved), $prefix)) return null;
        return '/assets/admin/' . $file;
    }
}
