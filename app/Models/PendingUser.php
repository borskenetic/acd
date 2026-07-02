<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingUser extends Model
{
    protected $fillable = [
        'fname',
        'lname',
        'email',
        'course',
        'department',
        'campus',
        'password',
        'role',
    ];
}
