<?php

namespace App\Admin\Repositories;

use App\Models\Goods as Model;

class Goods extends TransactionalRepository
{
    /**
     * Model.
     *
     * @var string
     */
    protected $eloquentClass = Model::class;
}
