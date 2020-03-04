<?php

namespace App\Model;
use Illuminate\Database\Eloquent\Model;

class Group extends Model{
    protected $table = 'groups';
    protected $fillable = [
        'line_user_id',
        'group_id',
        'is_joining',
        'active'
    ];

}