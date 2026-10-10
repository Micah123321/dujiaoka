<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminData
{
    public static function create($extended = false)
    {
        // Derive columns/defaults from the shipped schema, never its sample accounts or records.
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/sql/install.sql');
        $quote = chr(96);
        preg_match_all('/CREATE TABLE ' . $quote . '([^' . $quote . ']+)' . $quote . ' \((.*?)\n\) ENGINE=/s', $sql, $tables, PREG_SET_ORDER);
        foreach ($tables as $table) {
            if ($table[1] === 'failed_jobs') continue;
            Schema::create($table[1], function ($blueprint) use ($table, $quote) {
                foreach (explode("\n", $table[2]) as $line) {
                    if (!preg_match('/^\s*' . $quote . '([^' . $quote . ']+)' . $quote . '\s+([a-z]+)(?:\(([^)]+)\))?/i', $line, $match)) continue;
                    $name = $match[1];
                    $type = strtolower($match[2]);
                    if (strpos($line, 'AUTO_INCREMENT') !== false) {
                        $blueprint->increments($name);
                        continue;
                    }
                    if (in_array($type, ['int', 'bigint', 'tinyint', 'smallint'], true)) $column = $blueprint->integer($name);
                    elseif ($type === 'decimal') $column = $blueprint->decimal($name, 10, 2);
                    elseif ($type === 'timestamp') $column = $blueprint->dateTime($name);
                    else $column = $blueprint->text($name);
                    if (strpos($line, 'NOT NULL') === false) $column->nullable();
                    if (preg_match("/DEFAULT '([^']*)'/", $line, $default)) $column->default($default[1]);
                }
            });
        }
        Schema::create('fixture_marker', function ($table) { $table->string('name')->primary(); });
        DB::table('fixture_marker')->insert(['name' => 'dujiaoka-admin-browser-v1']);
        $now = date('Y-m-d H:i:s');
        $stamp = ['created_at' => $now, 'updated_at' => $now];
        DB::table('admin_users')->insert(array_merge($stamp, ['id' => 1, 'username' => 'fixture-admin', 'name' => 'Fixture Admin', 'password' => password_hash('fixture-password', PASSWORD_BCRYPT)]));
        DB::table('admin_roles')->insert(array_merge($stamp, ['id' => 1, 'name' => 'Administrator', 'slug' => 'administrator']));
        DB::table('admin_role_users')->insert(array_merge($stamp, ['role_id' => 1, 'user_id' => 1]));
        if ($extended) {
            foreach ([2 => 'reader', 3 => 'goods-editor', 4 => 'importer'] as $id => $role) {
                DB::table('admin_users')->insert(array_merge($stamp, ['id' => $id, 'username' => 'fixture-' . $role, 'name' => 'Fixture ' . $role, 'password' => password_hash('fixture-password', PASSWORD_BCRYPT)]));
                DB::table('admin_roles')->insert(array_merge($stamp, ['id' => $id, 'name' => 'Fixture ' . $role, 'slug' => 'fixture-' . $role]));
                DB::table('admin_role_users')->insert(array_merge($stamp, ['role_id' => $id, 'user_id' => $id]));
            }
            foreach ([2 => ['GET', '/goods,/goods/1,/goods/2,/import-carmis'], 3 => ['GET,PUT,PATCH', '/goods,/goods/*'], 4 => ['GET,POST', '/import-carmis']] as $id => $permission) {
                DB::table('admin_permissions')->insert(array_merge($stamp, ['id' => $id, 'parent_id' => 0, 'order' => $id, 'name' => 'Fixture permission ' . $id, 'slug' => 'fixture-permission-' . $id, 'http_method' => $permission[0], 'http_path' => $permission[1]]));
                DB::table('admin_role_permissions')->insert(array_merge($stamp, ['role_id' => $id, 'permission_id' => $id]));
            }
        }
        $uris = ['', 'goods', 'goods-group', 'carmis', 'coupon', 'emailtpl', 'pay', 'order', 'import-carmis', 'system-setting', 'email-test'];
        foreach ($uris as $i => $uri) {
            DB::table('admin_menu')->insert(array_merge($stamp, ['id' => $i + 1, 'parent_id' => 0, 'order' => $i, 'title' => $uri ?: 'Dashboard', 'icon' => 'feather icon-circle', 'uri' => $uri, 'show' => 1, 'extension' => '']));
            DB::table('admin_role_menu')->insert(array_merge($stamp, ['role_id' => 1, 'menu_id' => $i + 1]));
        }
        if ($extended) {
            foreach ([[2,2],[2,9],[3,2],[4,9]] as $pair) DB::table('admin_role_menu')->insert(array_merge($stamp, ['role_id' => $pair[0], 'menu_id' => $pair[1]]));
        }
        DB::table('goods_group')->insert(array_merge($stamp, ['id' => 1, 'gp_name' => 'Fixture Group', 'is_open' => 1, 'ord' => 1]));
        foreach ([1, 2] as $id) DB::table('goods')->insert(array_merge($stamp, [
            'id' => $id, 'group_id' => 1, 'gd_name' => $id === 1 ? 'Fixture Auto Goods' : 'Fixture Manual Goods',
            'gd_description' => 'Fixture description', 'gd_keywords' => 'fixture',
            'actual_price' => '9.00', 'retail_price' => '10.00', 'type' => $id, 'in_stock' => 10,
            'description' => 'Fixture description', 'api_hook' => '', 'other_ipu_cnf' => '', 'wholesale_price_cnf' => '',
        ]));
        if ($extended) {
            foreach ([3,4] as $id) {
                $goods = (array) DB::table('goods')->where('id', 2)->first();
                $goods['id'] = $id; $goods['gd_name'] = 'Fixture Trashed Goods ' . $id; $goods['deleted_at'] = $now;
                DB::table('goods')->insert($goods);
            }
        }
        DB::table('carmis')->insert(array_merge($stamp, ['id' => 1, 'goods_id' => 1, 'carmi' => 'FIXTURE-ADMIN-CARD', 'status' => 1]));
        if ($extended) {
            foreach ([2,3] as $id) DB::table('carmis')->insert(array_merge($stamp, ['id' => $id, 'goods_id' => 1, 'carmi' => 'FIXTURE-TRASHED-CARD-' . $id, 'status' => 1, 'deleted_at' => $now]));
        }
        DB::table('coupons')->insert(array_merge($stamp, ['id' => 1, 'discount' => '1.00', 'coupon' => 'FIXTURE-COUPON', 'ret' => 2]));
        DB::table('coupons_goods')->insert(['goods_id' => 1, 'coupons_id' => 1]);
        DB::table('emailtpls')->insert(array_merge($stamp, ['id' => 1, 'tpl_name' => 'Fixture Mail', 'tpl_token' => 'fixture-mail', 'tpl_content' => '<p>Fixture mail</p>']));
        DB::table('pays')->insert(array_merge($stamp, ['id' => 1, 'pay_name' => 'Fixture Pay', 'pay_check' => 'fixture', 'pay_method' => 1, 'pay_client' => 3, 'merchant_id' => '', 'merchant_key' => '', 'merchant_pem' => '', 'pay_handleroute' => '/pay/fixture']));
        foreach ([1, 4] as $i => $status) DB::table('orders')->insert(array_merge($stamp, [
            'id' => $i + 1, 'order_sn' => 'FIXTURE-ADMIN-ORDER-' . ($i + 1), 'goods_id' => 1, 'title' => 'Fixture Auto Goods',
            'type' => 1, 'goods_price' => '9.00', 'buy_amount' => 1, 'total_price' => '9.00', 'actual_price' => '9.00',
            'email' => 'fixture@example.test', 'info' => $status === 4 ? 'FIXTURE-SOLD-CARD' : '', 'pay_id' => 1, 'buy_ip' => '127.0.0.1', 'status' => $status,
        ]));
    }
}
