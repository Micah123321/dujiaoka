<?php

namespace App\Admin\Support;

use Dcat\Admin\Admin;
use Dcat\Admin\Color;

class AdminTheme
{
    public static function register(): void
    {
        $light = config('admin-theme.light');
        $dark = config('admin-theme.dark');
        // Keep the shipped asset name while setting colors used by native widgets and JavaScript.
        Color::extend(Color::DEFAULT_COLOR, [
            'primary' => $light['primary'], 'primary-darker' => $light['primary-hover'],
            'link' => $light['link'], 'success' => $light['success'],
            'danger' => $light['danger'], 'warning' => $light['warning'],
            'info' => $light['primary'],
        ]);
        $color = new Color();
        $color->setName(Color::DEFAULT_COLOR);
        app()->instance('admin.color', $color);
        Admin::style(self::variables('body.dujiaoka-admin', $light) . self::variables('body.dujiaoka-admin.dark-mode', $dark));
    }

    private static function variables(string $selector, array $palette): string
    {
        $styles = [];
        foreach ($palette as $name => $value) $styles[] = '--admin-' . $name . ':' . $value;
        return $selector . '{' . implode(';', $styles) . '}';
    }
}
