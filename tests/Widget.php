<?php

namespace Langsys\RequestQueryCache\Tests;

use Illuminate\Database\Eloquent\Model;

class Widget extends Model
{
    protected $table = 'widgets';

    public $timestamps = false;

    protected $guarded = [];
}
