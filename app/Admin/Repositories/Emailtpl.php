<?php

namespace App\Admin\Repositories;

use App\Models\Emailtpl as Model;

class Emailtpl extends TransactionalRepository
{
    /**
     * Model.
     *
     * @var string
     */
    protected $eloquentClass = Model::class;
}
