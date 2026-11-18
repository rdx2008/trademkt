<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitaSku extends Model
{
    protected $table = 'visita_skus';

    protected $guarded = ['id'];

    public $timestamps = false;

    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }

    public function visita()
    {
        return $this->belongsTo(Visita::class);
    }
}
