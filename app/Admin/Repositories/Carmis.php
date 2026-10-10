<?php

namespace App\Admin\Repositories;

use App\Models\Carmis as Model;

class Carmis extends TransactionalRepository
{

    /**
     * Model.
     *
     * @var string
     */
    protected $eloquentClass = Model::class;

}
