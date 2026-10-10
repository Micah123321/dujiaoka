<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaypalOrder extends Model
{
    protected $table = 'paypal_orders';
    protected $guarded = ['id'];
    protected $hidden = ['account_fingerprint', 'create_key', 'capture_key', 'capture_lease_token'];
    protected $casts = [
        'order_id' => 'string', 'pay_id' => 'string',
        'cny_amount' => 'decimal:2', 'usd_amount' => 'decimal:2',
        'capture_attempted_at' => 'datetime', 'capture_leased_until' => 'datetime',
    ];
}
