<?php

namespace Potelo\GuPayment;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use GuSubscriptionTrait;

    /**
     * The attributes that are not mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
