<?php

namespace Pawan\UserDiscounts\Tests;

use Illuminate\Foundation\Auth\User;

class TestUser extends User
{
    protected $table = 'users';
    protected $guarded = [];
    protected $hidden = ['password'];
}
