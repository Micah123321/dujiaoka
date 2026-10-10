<?php

namespace App\Admin\Repositories;

use App\Models\Order as Model;

class Order extends TransactionalRepository
{
    /**
     * Model.
     *
     * @var string
     */
    protected $eloquentClass = Model::class;
}
