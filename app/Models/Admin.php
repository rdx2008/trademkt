<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Super admin do fornecedor. Fica no banco central.
 */
class Admin extends Authenticatable
{
    protected $table = 'admins';

    protected $fillable = ['nome', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
