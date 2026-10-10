<?php

namespace App\Admin\Repositories;

use App\Models\Pay as Model;

class Pay extends TransactionalRepository
{
    /**
     * Model.
     *
     * @var string
     */
    protected $eloquentClass = Model::class;
}
