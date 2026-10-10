<?php

namespace App\Admin\Repositories;

use App\Models\Coupon as Model;

class Coupon extends TransactionalRepository
{
    /**
     * Model.
     *
     * @var string
     */
    protected $eloquentClass = Model::class;
}
