<?php

namespace Potelo\GuPayment;

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class SubscriptionBuilder
{
    /**
     * The user model that is subscribing.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $user;

    /**
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;

    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $plan;

    /**
     * The number of trial days to apply to the subscription.
     *
     * @var int|null
     */
    protected $trialDays;

    /**
     * Indicates that the trial should end immediately.
     *
     * @var bool
     */
    protected $skipTrial = false;

    /**
     * The coupon code being applied to the customer.
     *
     * @var string|null
     */
    protected $coupon;

    private $charge_on_success = false;

    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $user
     * @param  string  $name
     * @param  string  $plan
     */
    public function __construct($user, $name, $plan, $additionalData, array $subItems = [])
    {
        $this->user = $user;
        $this->name = $name;
        $this->plan = $plan;
        $this->additionalData = $additionalData;
        $this->subItems = $subItems;
    }

    /**
     * Specify the ending date of the trial.
     *
     * @param  int  $trialDays
     * @return $this
     */
    public function trialDays($trialDays)
    {
        $this->trialDays = $trialDays;

        return $this;
    }

    /**
     * Force the trial to end immediately.
     *
     * @return $this
     */
    public function skipTrial()
    {
        $this->skipTrial = true;

        return $this;
    }

    /**
     * Add a new Iugu subscription to the user.
     *
     * @param  array  $options
     * @return \Potelo\GuPayment\Subscription
     */
    public function add(array $options = [])
    {
        return $this->create(null, $options);
    }

    /**
     * Create a new Iugu subscription.
     *
     * @param  string|null $token
     * @param  array $options
     * @return \Potelo\GuPayment\Subscription|boolean
     */
    public function create($token = null, array $options = [])
    {
        $customer = $this->getIuguCustomer($token, $options);

        $subscriptionIugu = $this->user->createIuguSubscription($this->buildPayload($customer->id));

        if (isset($subscriptionIugu->errors)) {
            return false;
        }

        if ($this->skipTrial) {
            $trialEndsAt = null;
        } else {
            $trialEndsAt = $this->trialDays ? Carbon::now()->addDays($this->trialDays) : null;
        }

        $subscription = new Subscription();
        $subscription->name = $this->name;
        $subscription->iugu_id =  $subscriptionIugu->id;
        $subscription->iugu_plan =  $this->plan;
        $subscription->trial_ends_at = $trialEndsAt;
        $subscription->ends_at = null;

        foreach ($this->additionalData as $k => $v) {
            // If column exists at database
            if (Schema::hasColumn($subscription->getTable(), $k)) {
                $subscription->{$k} = $v;
            }
        }

        return $this->user->subscriptions()->save($subscription);
    }

    /**
     * Get the Iugu customer instance for the current user and token.
     *
     * @param  string|null  $token
     * @param  array  $options
     * @return \Iugu_Customer
     */
    protected function getIuguCustomer($token = null, array $options = [])
    {
        if (! $this->user->iugu_id) {
            $customer = $this->user->createAsIuguCustomer(
                $token,
                array_merge($options, array_filter(['coupon' => $this->coupon]))
            );
        } else {
            $customer = $this->user->asIuguCustomer();

            if ($token) {
                $this->user->updateCard($token);
            }
        }

        return $customer;
    }

    /**
     * Build the payload for subscription creation.
     *
     * @param $customerId
     * @return array
     */
    protected function buildPayload($customerId)
    {
        $customVariables = [];
        foreach ($this->additionalData as $k => $v) {
            $additionalData = [];
            $additionalData['name'] = $k;
            $additionalData['value'] = $v;

            $customVariables[] = $additionalData;
        }

        return array_filter([
            'plan_identifier' => $this->plan,
            'expires_at' => $this->getTrialEndForPayload(),
            'customer_id' => $customerId,
            'only_on_charge_success' => $this->charge_on_success,
            'subitems' => $this->subItems['subItems'] ?? [],
            'custom_variables' => $customVariables
        ]);
    }

    /**
     * Get the trial ending date for the Iugu payload.
     *
     * @return int|null
     */
    protected function getTrialEndForPayload()
    {
        if ($this->skipTrial) {
            return Carbon::now();
        }

        if ($this->trialDays) {
            return Carbon::now()->addDays($this->trialDays);
        }
    }

    public function chargeOnSuccess()
    {
        $this->charge_on_success = true;

        return $this;
    }
}
