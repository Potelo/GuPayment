<?php

namespace Potelo\GuPayment;

use Iugu;
use Iugu_Customer;
use Iugu_Subscription;
use Iugu_Invoice;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait GuPaymentTrait
{
    /**
     * The IUGU API key.
     *
     * @var string
     */
    protected static $apiKey;


    public function newSubscription($subscription, $plan, $additionalData = [], $subItems = [])
    {
        return new SubscriptionBuilder($this, $subscription, $plan, $additionalData, $subItems);
    }

    /**
     * Create a Iugu customer for the given user.
     *
     * @param  string $token
     * @param  array $options
     * @return \Iugu_Customer
     */
    public function createAsIuguCustomer($token, array $options = [])
    {
        $options = array_merge($options, ['email' => $this->email]);


        Iugu::setApiKey($this->getApiKey());

        // Here we will create the customer instance on Iugu and store the ID of the
        // user from Iugu. This ID will correspond with the Iugu user instances
        // and allow us to retrieve users from Iugu later when we need to work.
        $customer = Iugu_Customer::create(
            $options
        );

        $this->iugu_id = $customer->id;

        $this->save();

        // Next we will add the credit card to the user's account on Iugu using this
        // token that was provided to this method. This will allow us to bill users
        // when they subscribe to plans or we need to do one-off charges on them.
        if (! is_null($token)) {
            $this->updateCard($token);
        }

        return $customer;
    }

    /**
     * Create a Iugu subscription
     *
     * @param $subscription
     * @return \Iugu_Subscription
     */
    public function createIuguSubscription($subscription)
    {
        Iugu::setApiKey($this->getApiKey());

        return Iugu_Subscription::create($subscription);

    }

    /**
     * get a Iugu subscription
     *
     * @param $subscriptionId
     * @return \Iugu_Subscription
     */
    public function getIuguSubscription($subscriptionId)
    {
        Iugu::setApiKey($this->getApiKey());

        return Iugu_Subscription::fetch($subscriptionId);
    }


    /**
     * Determine if the entity is on the given plan.
     *
     * @param  string  $plan
     * @return bool
     */
    public function onPlan($plan)
    {
        $onPlan = $this->subscriptions->where('iugu_plan', $plan)->first();

        return ! is_null($onPlan);
    }

    /**
     * Determine if the user has a given subscription.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function subscribed($subscription = 'default', $plan = null)
    {
        $subscription = $this->subscription($subscription);

        if (is_null($subscription)) {
            return false;
        }

        if (is_null($plan)) {
            return $subscription->valid();
        }

        return $subscription->valid() &&
        $subscription->iugu_plan === $plan;
    }

    /**
     * Get a subscription instance by name.
     *
     * @param  string  $subscription
     * @return \Potelo\GuPayment\Subscription|null
     */
    public function subscription($subscription = 'default')
    {
        return $this->subscriptions->sortByDesc(function ($value) {
            return $value->created_at->getTimestamp();
        })->where('name', $subscription)->first();
    }


    /**
     * Get all of the subscriptions for the user.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function subscriptions()
    {
        $column = getenv('IUGU_MODEL_FOREIGN_KEY') ?: config('services.iugu.model_foreign_key', 'user_id');

        return $this->hasMany(Subscription::class, $column)->orderBy('created_at', 'desc');
    }

    /**
     * Get a collection of the entity's invoices.
     *
     * @param  bool  $includePending
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function invoices($includePending = false, $parameters = [])
    {
        $invoices = [];

        $customer = $this->asIuguCustomer();

        $parameters = array_merge(['limit' => 24, 'customer_id' => $customer->id], $parameters);

        Iugu::setApiKey($this->getApiKey());

        $iuguInvoices = Iugu_Invoice::search($parameters)->results();

        // Here we will loop through the Iugu invoices and create our own custom Invoice
        // instances that have more helper methods and are generally more convenient to
        // work with than the plain Iugu objects are. Then, we'll return the array.
        if (! is_null($iuguInvoices)) {

            foreach ($iuguInvoices as $invoice) {
                if ($invoice->status == 'paid' || $includePending) {
                    $invoices[] = new Invoice($this, $invoice);
                }
            }
        }

        return new Collection($invoices);
    }
    /**
     * Find an invoice by ID.
     *
     * @param  string  $id
     * @return \Potelo\GuPayment\Invoice|null
     */
    public function findInvoice($id)
    {
        Iugu::setApiKey($this->getApiKey());

        try {
            return new Invoice($this, Iugu_Invoice::fetch($id));
        } catch (\Exception $e) {
            //
        }

        return Null;
    }


    /**
     * Find an invoice or throw a 404 error.
     *
     * @param  string  $id
     * @return \Potelo\GuPayment\Invoice
     */
    public function findInvoiceOrFail($id)
    {
        $invoice = $this->findInvoice($id);

        if (is_null($invoice)) {
            throw new NotFoundHttpException;
        }

        return $invoice;
    }

    /**
     * Create an invoice download Response.
     *
     * @param  string  $id
     * @param  array   $data
     * @param  string  $storagePath
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function downloadInvoice($id, array $data, $storagePath = null)
    {
        return $this->findInvoiceOrFail($id)->download($data, $storagePath);
    }


    /**
     * Get the Iugu customer for the user.
     *
     * @return \Iugu_Customer
     */
    public function asIuguCustomer()
    {
        Iugu::setApiKey($this->getApiKey());

        return Iugu_Customer::fetch($this->iugu_id);
    }

    /**
     * Get the Iugu API key.
     *
     * @return string
     */
    public static function getApiKey()
    {
        if (static::$apiKey) {
            return static::$apiKey;
        }

        if ($key = getenv('IUGU_APIKEY')) {
            return $key;
        }

        return config('services.iugu.key');
    }

    /**
     * Update customers credit card.
     *
     * @param string $token
     */
    public function updateCard($token)
    {
        $customer = $this->asIuguCustomer();

        $customer->payment_methods()->create(Array(
            "description" => "Credit card",
            "token" => $token,
            "set_as_default" => true
        ));
    }


}