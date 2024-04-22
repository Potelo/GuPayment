<?php

namespace Potelo\GuPayment;

use Iugu;
use Exception;
use IuguObjectNotFound;
use InvalidArgumentException;
use Iugu_Charge as IuguCharge;
use Iugu_Invoice as IuguInvoice;
use Iugu_Customer as IuguCustomer;
use Illuminate\Support\Collection;
use Iugu_Subscription as IuguSubscription;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;


trait GuPaymentTrait
{
    /**
     * The IUGU API key.
     *
     * @var string
     */
    protected static $apiKey;

    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param  integer|null  $amount
     * @param  array  $options
     * @return \Iugu_Charge
     *
     * @throws \InvalidArgumentException
     */
    public function charge($amount, array $options = [])
    {
        if (! array_key_exists('items', $options) &&
            ! array_key_exists('invoice_id', $options)
        ) {
            $options['items'] = [];

            array_push($options['items'], [
                'description' => 'Nova cobrança',
                'quantity' => 1,
                'price_cents' => $amount,
            ]);
        }

        if (! array_key_exists('customer_id', $options) && $this->hasIuguId()) {
            $options['customer_id'] = $this->getIuguUserId();
        }

        if (! array_key_exists('token', $options) &&
            ! array_key_exists('method', $options) &&
            ! array_key_exists('customer_payment_method_id', $options) &&
            (! $defaultCard = $this->defaultCard())
        ) {
            throw new InvalidArgumentException('No payment source provided.');
        }

        if (! array_key_exists('invoice_id', $options) &&
            ! array_key_exists('email', $options) &&
            ! $this->hasIuguId()
        ) {
            throw new InvalidArgumentException(
                'No customer required data provided. '.
                'Pass invoice_id or email or customer_id or use createAsIuguCustomer() to create a customer.'
            );
        }

        if (! array_key_exists('method', $options) &&
            ! array_key_exists('token', $options) &&
            ! array_key_exists('customer_payment_method_id', $options) &&
            $defaultCard = isset($defaultCard) ? $defaultCard : $this->defaultCard()
        ) {
            $options['customer_payment_method_id'] = $defaultCard->id;
        }

        Iugu::setApiKey($this->getApiKey());

        return IuguCharge::create($options);
    }

    /**
     * Get the default card for the entity.
     *
     * @return \Iugu_Payment
     */
    public function defaultCard()
    {
        $customer = $this->asIuguCustomer();

        return $customer->default_payment_method();
    }

    /**
     * Get a collection of the entity's cards.
     *
     * @param  array $parameters
     * @return \Illuminate\Support\Collection
     */
    public function cards($parameters = [])
    {
        $cards = [];

        $parameters = array_merge(['item_type' => 'credit_card'], $parameters);

        $iuguCards = $this->asIuguCustomer()->payment_methods()->search(
            $parameters
        );

        if (! is_null($iuguCards)) {
            foreach ($iuguCards->results() as $card) {
                $cards[] = new Card($this, $card);
            }
        }

        return new Collection($cards);
    }

    public function newSubscription($subscription, $plan, $additionalData = [], $options = [])
    {
        return new SubscriptionBuilder($this, $subscription, $plan, $additionalData, $options);
    }

    /**
     * Create a Iugu customer for the given user.
     *
     * @param  string $token
     * @param  array $options
     * @return \Iugu_Customer
     */
    public function createAsIuguCustomer($token = null, array $options = [])
    {
        $options = array_merge($options, ['email' => $this->email]);

        Iugu::setApiKey($this->getApiKey());

        // Here we will create the customer instance on Iugu and store the ID of the
        // user from Iugu. This ID will correspond with the Iugu user instances
        // and allow us to retrieve users from Iugu later when we need to work.
        $customer = IuguCustomer::create(
            $options
        );

        // If exists error, return the object with the errors immediately
        if (isset($customer->errors)) {
            return $customer;
        }

        $this->setUserIuguId($customer->id);

        $this->save();

        // Next we will add the credit card to the user's account on Iugu using this
        // token that was provided to this method. This will allow us to bill users
        // when they subscribe to plans or we need to do one-off charges on them.
        if (! is_null($token)) {
            $paymentMethod = $this->updateCard($token);

            // If exists error, return the object with the errors immediately
            if (isset($paymentMethod->errors)) {
                $customer->errors = $paymentMethod->errors;

                return $customer;
            }
        }

        return $customer;
    }

    /**
     * Create credit card to customer's payment methods.
     *
     * @param  string $token
     * @param  array  $options
     * @return \Potelo\GuPayment\Card
     */
    public function createCard($token, array $options = [])
    {
        $customer = $this->asIuguCustomer();

        if (! array_key_exists('description', $options)) {
            $options['description'] = 'Credit card';
        }

        if (! array_key_exists('set_as_default', $options)) {
            $options['set_as_default'] = true;
        }

        $options['token'] = $token;

        return new Card($this, $customer->payment_methods()->create($options));
    }

    /**
     * Find a card by ID;
     *
     * @param  stirng $key
     * @return \Potelo\GuPayment\Card | null
     */
    public function findCard($key)
    {
        try {
            $customer = $this->asIuguCustomer();

            return new Card($this, $customer->payment_methods()->fetch($key));
        } catch (Exception $e) {
            //
        }
    }

    /**
     * Find a card or throw a 404 error.
     *
     * @param  string $key
     * @return \Potelo\GuPayment\Card
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function findCardOrFail($key)
    {
        $card = $this->findCard($key);

        if (is_null($card)) {
            throw new NotFoundHttpException('Cartão não encontrado.');
        }

        return $card;
    }

    /**
     * Delete a entity's card.
     *
     * @param  \Potelo\GuPayment\Card $card
     * @return void
     *
     * @todo Check if deleted card if default card,
     *       and set another default if exists
     */
    public function deleteCard(Card $card)
    {
        $card->delete();
    }

    /**
     * Delete the entity's cards.
     *
     * @return void
     */
    public function deleteCards()
    {
        $this->cards()->each(function ($card) {
            $this->deleteCard($card);
        });
    }

    /**
     * Create a Iugu subscription.
     *
     * @param $options
     * @return IuguSubscription
     */
    public function createIuguSubscription($options)
    {
        Iugu::setApiKey($this->getApiKey());

        return IuguSubscription::create($options);
    }

    /**
     * Create a Iugu invoice.
     *
     * @param $options
     * @return IuguInvoice
     */
    public function createIuguInvoice($options)
    {
        Iugu::setApiKey($this->getApiKey());

        return IuguInvoice::create($options);
    }

    /**
     * Get a Iugu subscription.
     *
     * @param $subscriptionId
     * @return IuguSubscription
     */
    public function getIuguSubscription($subscriptionId)
    {
        Iugu::setApiKey($this->getApiKey());

        return IuguSubscription::fetch($subscriptionId);
    }
    
    /**
     * Get user's Iugu Subscriptions
     *
     * @param array $params
     * @return void|object|array
     */
    public function getIuguSubscriptions($params = [])
    {
        Iugu::setApiKey($this->getApiKey());

        $customer = $this->asIuguCustomer();
        return IuguSubscription::search(array_merge(['customer_id' => $customer->id], $params));
    }


    /**
     * Determine if the entity is on the given plan.
     *
     * @param  string  $plan
     * @return bool
     */
    public function onPlan($plan)
    {
        $iuguSubscriptionModelPlanColumn = getenv('IUGU_SUBSCRIPTION_MODEL_PLAN_COLUMN') ?: config('services.iugu.subscription_model_plan_column', 'iugu_plan');

        $onPlan = $this->subscriptions->where($iuguSubscriptionModelPlanColumn, $plan)->first();

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
        $iuguSubscriptionModelPlanColumn = getenv('IUGU_SUBSCRIPTION_MODEL_PLAN_COLUMN') ?: config('services.iugu.subscription_model_plan_column', 'iugu_plan');

        $subscription = $this->subscription($subscription);

        if (is_null($subscription)) {
            return false;
        }

        if (is_null($plan)) {
            return $subscription->valid();
        }

        return $subscription->valid() &&
            $subscription->{$iuguSubscriptionModelPlanColumn} === $plan;
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
        })
            ->where('name', $subscription)
            ->first();
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

        $iuguInvoices = IuguInvoice::search($parameters)->results();

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
            return new Invoice($this, IuguInvoice::fetch($id));
        } catch (IuguObjectNotFound $e) {
            //
        }

        return null;
    }

    /**
     * Create invoice.
     *
     * @param $amount
     * @param $dueDate
     * @param string $description
     * @param array $options
     * @return \Iugu_SearchResult|null
     */
    public function createInvoice($amount, $dueDate, $description = 'Nova fatura', array $options = [])
    {
        $invoiceBuilder = $this->newInvoice($dueDate);

        $invoiceBuilder->addItem($amount, $description, 1);

        return $invoiceBuilder->create($options);
    }

    /**
     * @param $dueDate
     * @return InvoiceBuilder
     */
    public function newInvoice($dueDate)
    {
        return new InvoiceBuilder($this, $dueDate);
    }

    /**
     * Determine if the entity has a Iugu customer ID.
     *
     * @return bool
     */
    public function hasIuguId()
    {
        return ! is_null($this->getIuguUserId());
    }

    /**
     * Find an invoice or throw a 404 error.
     *
     * @param  string  $id
     * @return \Potelo\GuPayment\Invoice
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
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
     *
     * @throws \InvalidArgumentException
     */
    public function asIuguCustomer()
    {
        if (! $this->getIuguUserId()) {
            throw new InvalidArgumentException(class_basename($this).' is not a Iugu customer. See the createAsIuguCustomer method.');
        }

        Iugu::setApiKey($this->getApiKey());

        return IuguCustomer::fetch($this->getIuguUserId());
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
     * Get the Iugu User Id.
     *
     * @return string
     */
    public function getIuguUserId()
    {
        $column = getenv('IUGU_USER_MODEL_COLUMN') ?: config('services.iugu.user_model_column', 'iugu_id');

        return $this->{$column};
    }

    /**
     * Set the Iugu User Id.
     * @param $iuguId
     * @return string
     */
    public function setUserIuguId($iuguId)
    {
        $column = getenv('IUGU_USER_MODEL_COLUMN') ?: config('services.iugu.user_model_column', 'iugu_id');

        $this->{$column} = $iuguId;
    }

    /**
     * Update customer's credit card.
     *
     * @param string $token
     * @return \Iugu_Customer
     */
    public function updateCard($token)
    {
        $customer = $this->asIuguCustomer();

        return $customer->payment_methods()->create([
            "description" => "Credit card",
            "token" => $token,
            "set_as_default" => true
        ]);
    }

    /**
     * Refund an invoice.
     *
     * @param string $id the invoice id
     * @return bool
     */
    public function refund($id)
    {
        $iuguInvoice = $this->findInvoice($id)->asIuguInvoice();

        return $iuguInvoice->refund();
    }

    /**
     * Duplicate an in progress invoice
     * @param string $id the invoice id
     * @param array $config configuration of the duplicate
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function duplicate($id,$config)
    {
        $iuguInvoice = $this->findInvoice($id)->asIuguInvoice();

        return $iuguInvoice->duplicate($config);
    }
}
