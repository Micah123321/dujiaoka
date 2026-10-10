<?php

namespace App\Admin\Repositories;

use App\Models\GoodsGroup as Model;

class GoodsGroup extends TransactionalRepository
{

    /**
     * Model.
     *
     * @var string
     */
    protected $eloquentClass = Model::class;

}
