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

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'trial_ends_at', 'ends_at',
        'created_at', 'updated_at',
    ];
}
