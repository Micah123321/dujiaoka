<?php

namespace Tests\Fixtures;

use App\Models\Goods;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminDeletionData
{
    public const RESOURCES = ['goods' => 'goods', 'goods-group' => 'goods_group', 'carmis' => 'carmis',
        'coupon' => 'coupons', 'emailtpl' => 'emailtpls', 'pay' => 'pays', 'order' => 'orders'];

    public static function configure(string $runtime, bool $initialize): void
    {
        config(['dujiaoka.api_hook_outbox' => false, 'dujiaoka.payment_mail_outbox' => false,
            'dujiaoka.manual_status_mail_outbox' => false]);
        if ($initialize) self::seed($runtime);
        foreach (['Goods', 'GoodsGroup', 'Carmis', 'Coupon', 'Emailtpl', 'Pay', 'Order'] as $model) {
            $class = 'App\\Models\\' . $model;
            app('events')->listen('eloquent.deleting: ' . $class, function ($row) use ($runtime) {
                file_put_contents($runtime . '/delete-events.jsonl', json_encode([
                    'model' => get_class($row), 'table' => $row->getTable(), 'id' => (int) $row->getKey(),
                    'transactionLevel' => $row->getConnection()->transactionLevel(), 'php' => PHP_VERSION,
                    'framework' => app()->version(), 'force' => $row->isForceDeleting(),
                ]) . PHP_EOL, FILE_APPEND | LOCK_EX);
                $control = $runtime . '/fail-next-delete.json';
                if (!is_file($control)) return;
                $fail = json_decode(file_get_contents($control), true);
                if (($fail['table'] ?? null) === $row->getTable() && (int) ($fail['id'] ?? 0) === (int) $row->getKey()) {
                    unlink($control);
                    throw new \RuntimeException('Fixture second-member deletion failure');
                }
            });
        }
    }

    private static function seed(string $runtime): void
    {
        foreach (['delete-events.jsonl', 'fail-next-delete.json'] as $file) if (is_file($runtime . '/' . $file)) unlink($runtime . '/' . $file);
        $stamp = ['created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
        $paths = [];
        foreach (array_keys(self::RESOURCES) as $resource) { $paths[] = '/' . $resource; $paths[] = '/' . $resource . '/*'; }
        foreach ([2 => 'reader', 3 => 'deleter', 4 => 'limited'] as $id => $role) {
            DB::table('admin_users')->insert(array_merge($stamp, ['id' => $id, 'username' => 'fixture-delete-' . $role, 'name' => 'Fixture delete-' . $role, 'password' => password_hash('fixture-password', PASSWORD_BCRYPT)]));
            DB::table('admin_roles')->insert(array_merge($stamp, ['id' => $id, 'name' => 'Fixture delete-' . $role, 'slug' => 'fixture-delete-' . $role]));
            DB::table('admin_role_users')->insert(array_merge($stamp, ['role_id' => $id, 'user_id' => $id]));
            $rules = $role === 'limited' ? [['GET', '/goods,/goods/*'], ['DELETE', '/goods/3*']]
                : [[$role === 'reader' ? 'GET' : 'GET,DELETE', implode(',', $paths)]];
            foreach ($rules as $index => $rule) {
                $key = $id * 10 + $index;
                DB::table('admin_permissions')->insert(array_merge($stamp, ['id' => $key, 'parent_id' => 0, 'order' => $key, 'name' => 'Deletion rule ' . $key, 'slug' => 'deletion-rule-' . $key, 'http_method' => $rule[0], 'http_path' => $rule[1]]));
                DB::table('admin_role_permissions')->insert(array_merge($stamp, ['role_id' => $id, 'permission_id' => $key]));
            }
            foreach (DB::table('admin_menu')->whereIn('uri', $role === 'limited' ? ['goods'] : array_keys(self::RESOURCES))->pluck('id') as $menuId) DB::table('admin_role_menu')->insert(array_merge($stamp, ['role_id' => $id, 'menu_id' => $menuId]));
        }
        foreach (self::RESOURCES as $resource => $table) {
            $base = (array) DB::table($table)->orderBy('id')->first();
            foreach ([10, 11, 12, 20, 21] as $id) {
                $row = $base;
                $row['id'] = $id;
                $row['deleted_at'] = $id >= 20 ? $stamp['created_at'] : null;
                if ($resource === 'goods') { $row['gd_name'] = 'Fixture Delete Goods ' . $id; $row['picture'] = 'images/deletion-' . $id . '.png'; }
                elseif ($resource === 'goods-group') $row['gp_name'] = 'Fixture Delete Group ' . $id;
                elseif ($resource === 'carmis') $row['carmi'] = 'FIXTURE-DELETE-CARD-' . $id;
                elseif ($resource === 'coupon') $row['coupon'] = 'FIXTURE-DELETE-COUPON-' . $id;
                elseif ($resource === 'emailtpl') $row['tpl_token'] = 'fixture-delete-mail-' . $id;
                elseif ($resource === 'pay') $row['pay_check'] = 'fixture-delete-pay-' . $id;
                elseif ($resource === 'order') { $row['order_sn'] = 'FIXTURE-DELETE-ORDER-' . $id; $row['type'] = 1; $row['status'] = 1; }
                DB::table($table)->insert($row);
            }
        }
        foreach ([3, 4] as $id) {
            $row = (array) DB::table('goods')->where('id', 20)->first();
            $row['id'] = $id; $row['gd_name'] = 'Fixture Limited Goods ' . $id; $row['picture'] = 'images/deletion-' . $id . '.png';
            DB::table('goods')->insert($row);
        }
        foreach ([40 => 10, 41 => 11, 42 => 3] as $id => $goodsId) DB::table('carmis')->insert(array_merge($stamp, ['id' => $id, 'goods_id' => $goodsId, 'carmi' => 'FIXTURE-CASCADE-' . $id, 'status' => 1]));
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP1sAAAAASUVORK5CYII=');
        foreach ([3, 4, 10, 11, 12, 20, 21] as $id) Storage::disk('admin')->put('images/deletion-' . $id . '.png', $bytes);
    }

    public static function state(string $runtime): array
    {
        $tables = [];
        foreach (self::RESOURCES as $resource => $table) $tables[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        $tables['coupons_goods'] = DB::table('coupons_goods')->orderBy('goods_id')->get()->toArray();
        $files = [];
        foreach (['admin', 'public'] as $disk) foreach (Storage::disk($disk)->allFiles() as $file) $files[$disk][$file] = hash('sha256', Storage::disk($disk)->get($file));
        return ['php' => PHP_VERSION, 'binary' => PHP_BINARY, 'ini' => php_ini_loaded_file(), 'framework' => app()->version(),
            'database' => config('database.connections.sqlite.database'), 'storage' => app()->storagePath(), 'tables' => $tables, 'files' => $files];
    }
}
