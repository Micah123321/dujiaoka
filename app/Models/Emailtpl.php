<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Emailtpl extends BaseModel
{

    use SoftDeletes;

    protected $table = 'emailtpls';

}
