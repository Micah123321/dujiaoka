<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripePaymentIntent extends Model
{
    protected $table = 'stripe_payment_intents';

    protected $guarded = ['id'];

    protected $hidden = ['account_fingerprint'];

    protected $casts = [
        'order_id' => 'string',
        'pay_id' => 'string',
        'amount_minor' => 'string',
        'cny_amount' => 'decimal:2',
    ];
}
