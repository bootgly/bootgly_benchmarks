<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fortune extends Model
{
    protected $table = 'fortune';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $keyType = 'int';

    protected $casts = [
        'id' => 'integer',
    ];
}
