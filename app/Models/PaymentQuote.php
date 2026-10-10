<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentQuote extends Model
{
    protected $table = 'payment_quotes';
    protected $guarded = [];
    protected $casts = [
        'cny_amount' => 'decimal:2',
        'usd_amount' => 'decimal:2',
    ];
}
