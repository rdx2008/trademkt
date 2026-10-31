<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Super admin do fornecedor. Fica no banco central.
 */
class Admin extends Authenticatable
{
    use CentralConnection;

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
