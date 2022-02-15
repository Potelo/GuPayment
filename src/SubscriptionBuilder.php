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
     * @var int|null
     */
    protected $daysToExpire;

    /**
     * The coupon code being applied to the customer.
     *
     * @var string|null
     */
    protected $coupon;

    /**
     * @var bool
     */
    private $chargeOnSuccess = false;

    /**
     * @var null
     */
    protected $lastError = null;

    /**
     * @var null
     */
    protected $lr = null;

    /**
     * @var string
     */
    private $payableWith = 'all';

    /**
     * @var bool
     */
    private $validateCard = false;

    /**
     * @var array|null
     */
    private $additionalData;

    /**
     * Additional items to subscription
     *
     * @var array|null
     */
    private $subItems = [];

    /**
     * Options that will be sent to the API
     *
     * @var array|null
     */
    private $options = null;

    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $user
     * @param  string  $name
     * @param  string  $plan
     * @param  array  $additionalData
     * @param  array  $options
     */
    public function __construct($user, $name, $plan, $additionalData, $options)
    {
        $this->user = $user;
        $this->name = $name;
        $this->plan = $plan;
        $this->additionalData = $additionalData;
        $this->options = $options;
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
     * Expires at in N days
     *
     * @param $daysToExpire
     * @return $this
     */
    public function daysToExpire($daysToExpire)
    {
        $this->daysToExpire = $daysToExpire;

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
        $iuguSubscriptionModelIdColumn = getenv('IUGU_SUBSCRIPTION_MODEL_ID_COLUMN') ?: config('services.iugu.subscription_model_id_column', 'iugu_id');
        $iuguSubscriptionModelPlanColumn = getenv('IUGU_SUBSCRIPTION_MODEL_PLAN_COLUMN') ?: config('services.iugu.subscription_model_plan_column', 'iugu_plan');
        $searchOnBadGateway = getenv('IUGU_SUBSCRIPTION_SEARCH_ON_BAD_GATEWAY') ?: config('services.iugu.subscription_search_on_bad_gateway', false);

        $customer = $this->getIuguCustomer($token, $options);

        if (isset($customer->errors)) {
            $this->lastError = $customer->errors;
            return false;
        }
        $subscriptionIugu = null;
        $payload = $this->buildPayload($customer->id);

        try {
            $createdAtFrom = Carbon::now('America/Sao_Paulo');
            $subscriptionIugu = $this->user->createIuguSubscription($payload);
        } catch (\Exception $e) {
            if ($e->getCode() === 502 && $searchOnBadGateway) {
                sleep(5);
                $subscriptions = $this->user->getIuguSubscriptions([
                    'created_at_from' => $createdAtFrom,
                    'plan_identifier' => $payload['plan_identifier']
                ])->results();
                if (!empty($subscriptions)) {
                    $subscriptionIugu = $subscriptions[0];
                } else {
                    throw $e;
                }
            } else {
                throw $e;
            }
        }
        if (isset($subscriptionIugu->errors)) {
            if (isset($subscriptionIugu->LR)) {
                $this->lr = $subscriptionIugu->LR;
            }

            $this->lastError = $subscriptionIugu->errors;
            return false;
        }

        if ($this->skipTrial) {
            $trialEndsAt = null;
        } else {
            $trialEndsAt = $this->trialDays ? Carbon::now()->addDays($this->trialDays) : null;
        }

        $subscription = new Subscription();
        $subscription->name = $this->name;
        $subscription->{$iuguSubscriptionModelIdColumn} =  $subscriptionIugu->id;
        $subscription->{$iuguSubscriptionModelPlanColumn} =  $this->plan;
        $subscription->trial_ends_at = $trialEndsAt;
        $subscription->ends_at = null;

        foreach ($this->additionalData as $k => $v) {
            // If column exists at database
            if (Schema::hasColumn($subscription->getTable(), $k)) {
                $subscription->{$k} = $v;
            }
        }

        $subscription->setCacheIuguSubscription($subscriptionIugu);

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
        if (!$this->user->getIuguUserId()) {
            $customer = $this->user->createAsIuguCustomer(
                $token,
                array_merge($options, array_filter(['coupon' => $this->coupon]))
            );
        } else {
            $customer = $this->user->asIuguCustomer();

            if (!empty($options)) {
                foreach ($options as $key => $value) {
                    $customer->{$key} = $value;
                }
                $customer->save();
            }

            if ($token) {
                $this->user->updateCard($token);
            }
        }

        // If has token and validate card
        if ($token && $this->validateCard) {
            $iuguCharge = $this->user->charge(100, [
                'items' => [
                    ['description' => 'Verificação do cartão de crédito.', 'quantity' => 1, 'price_cents' => 100],
                ]
            ]);

            // If ok, refund
            if ($iuguCharge->success) {
                $this->user->refund($iuguCharge->invoice_id);
            } else {
                if (isset($iuguCharge->errors)) {
                    $customer->errors = $iuguCharge->errors;
                } else {
                    $customer->errors = $iuguCharge->message;

                    if (isset($iuguCharge->LR)) {
                        $this->lr = $iuguCharge->LR;
                    }
                }

                return $customer;
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

        $endDate = $this->getEndDateForPayload();

        $payload = [
            'plan_identifier' => $this->plan,
            'expires_at' => $endDate,
            'customer_id' => $customerId,
            'only_on_charge_success' => $this->chargeOnSuccess,
            'custom_variables' => $customVariables,
            'payable_with' => $this->payableWith
        ];

        if (!empty($this->subItems)) {
            $payload['subitems'] = $this->subItems;
        }

        $options = [];
        if (!is_null($this->options)) {
            $options = $this->options;
        }

        return array_filter(array_merge($payload, $options));
    }

    /**
     * Get the trial ending date for the Iugu payload.
     *
     * @return Carbon|null
     */
    protected function getEndDateForPayload()
    {
        $totalDays = $this->daysToExpire ? $this->daysToExpire : null;

        if ($this->skipTrial) {
            return $totalDays ? Carbon::now()->addDays($totalDays) : Carbon::now();
        }

        if ($totalDays) {
            $totalDays = $this->trialDays ? ($totalDays + $this->trialDays) : $totalDays;

            return Carbon::now()->addDays($totalDays);
        } elseif ($this->trialDays) {
            return Carbon::now()->addDays($this->trialDays);
        }

        return null;
    }

    /**
     * Get the expires_at date for the Iugu payload.
     *
     * @return Carbon|null
     */
    protected function getExpiresDateForPayload()
    {
        if ($this->daysToExpire) {
            return Carbon::now()->addDays($this->trialDays);
        }

        return null;
    }

    public function chargeOnSuccess()
    {
        $this->chargeOnSuccess = true;

        return $this;
    }

    /**
     * Choose the payable method
     *
     * @param string $method
     * @return $this
     */
    public function payWith($method = 'all')
    {
        $this->payableWith = $method;

        return $this;
    }

    /**
     * Charge R$ 1,00 and refund to check if the creditcard is valid
     */
    public function validateCard()
    {
        $this->validateCard = true;

        return $this;
    }

    /**
     * Get last error
     *
     * @return null
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * If the error contains LR, keep in a variable
     */
    public function getLR()
    {
        return $this->lr;
    }

    /**
     * Add sub items to subscription
     *
     * @param array $subItems
     * @return $this
     */
    public function subItems($subItems)
    {
        $this->subItems = array_merge($this->subItems, $subItems);

        return $this;
    }

    /**
     * Add a sub item to subscription
     *
     * @param array $subItem
     * @return $this
     */
    public function addSubItem($subItem)
    {
        $this->subItems[] = $subItem;

        return $this;
    }
}
