<?php

namespace App\Model;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model{
    protected $table = 'settings';
    protected $fillable = [
        'id_owner',
        'type',
        'active'
    ];

    public $primaryKey = "id_owner";

    public $incrementing = false;
}