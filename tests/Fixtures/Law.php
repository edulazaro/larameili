<?php

namespace EduLazaro\Larameili\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Law extends Model
{
    protected $table = 'laws';

    public $timestamps = false;

    protected $fillable = ['external_id', 'name'];
}
