<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class World extends Model
{
    protected $table = 'world';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $keyType = 'int';

    protected $fillable = ['id', 'randomnumber'];

    protected $casts = [
        'id' => 'integer',
        'randomnumber' => 'integer',
    ];
}
