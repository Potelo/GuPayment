<?php

namespace Potelo\GuPayment\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;
use Potelo\GuPayment\GuPaymentTrait;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use GuPaymentTrait, SoftDeletes;
    protected $table = 'users';
}
