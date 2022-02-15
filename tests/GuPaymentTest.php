<?php

namespace Potelo\GuPayment\Tests;

use Exception;
use Carbon\Carbon;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Illuminate\Support\Collection;
use Potelo\GuPayment\Tests\Fixtures\User;
use Iugu_Subscription as IuguSubscription;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Potelo\GuPayment\Http\Controllers\WebhookController;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GuPaymentTest extends TestCase
{
    use WithFaker;

    protected $iuguUserModelColumn;

    protected $iuguSubscriptionModelIdColumn;

    protected $iuguSubscriptionModelPlanColumn;

    public static function setUpBeforeClass(): void
    {
        if (file_exists(__DIR__ . '/../.env')) {
            $dotenv = \Dotenv\Dotenv::create(__DIR__, '/../.env');
            $dotenv->load();
        } else {
            throw new Exception('You need to create an .env file');
        }
    }

    public function setUp(): void
    {
        parent::setUp();

        Eloquent::unguard();

        $db = new DB;
        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $db->bootEloquent();
        $db->setAsGlobal();

        $iuguUserModelColumn = getenv('IUGU_USER_MODEL_COLUMN') ?: 'iugu_id';
        $this->iuguUserModelColumn = $iuguUserModelColumn;

        $this->schema()->create('users', function ($table) use ($iuguUserModelColumn) {
            $table->increments('id');
            $table->string('email');
            $table->string('name');
            $table->string($iuguUserModelColumn)->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_four')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $iuguSubscriptionModelIdColumn = getenv('IUGU_SUBSCRIPTION_MODEL_ID_COLUMN') ?: 'iugu_id';
        $this->iuguSubscriptionModelIdColumn = $iuguSubscriptionModelIdColumn;

        $iuguSubscriptionModelPlanColumn = getenv('IUGU_SUBSCRIPTION_MODEL_PLAN_COLUMN') ?: 'iugu_plan';
        $this->iuguSubscriptionModelPlanColumn = $iuguSubscriptionModelPlanColumn;

        $this->schema()->create('subscriptions', function ($table) use ($iuguSubscriptionModelIdColumn, $iuguSubscriptionModelPlanColumn) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('name');
            $table->string($iuguSubscriptionModelIdColumn);
            $table->string($iuguSubscriptionModelPlanColumn);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        $this->faker = $this->faker('pt_BR');
    }

    public function tearDown(): void
    {
        $this->schema()->drop('users');
        $this->schema()->drop('subscriptions');
    }

    /**
     * Tests.
     */
    public function testSubscriptionsCanBeCreated()
    {
        $user = $this->createUser();

        // Create Subscription
        $user->newSubscription('main', 'gold')->payWith('credit_card')->create($this->getTestToken());

        $this->assertEquals(1, $user->subscriptions()->count());
        $this->assertNotNull($user->subscription('main')->{$this->iuguSubscriptionModelIdColumn});

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->onPlan('gold'));
        $this->assertFalse($user->onPlan('something'));
        $this->assertTrue($user->subscribed('main', 'gold'));
        $this->assertFalse($user->subscribed('main', 'gold-2'));
        $this->assertTrue($user->subscription('main')->active());
        $this->assertFalse($user->subscription('main')->cancelled());
        $this->assertFalse($user->subscription('main')->onGracePeriod());

        // Cancel Subscription
        $subscription = $user->subscription('main');
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertTrue($subscription->onGracePeriod());

        // Modify Ends Date To Past
        $oldGracePeriod = $subscription->ends_at;
        $subscription->fill(['ends_at' => Carbon::now()->subDays(5)])->save();

        $this->assertFalse($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        $subscription->fill(['ends_at' => $oldGracePeriod])->save();

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        $simulation = $subscription->swapPlanSimulation('silver');

        $this->assertEquals(500, $simulation->cost);
        $this->assertEquals(2500, $simulation->discount);
        $this->assertEquals(2, $simulation->cycles);
        $this->assertEquals('gold', $simulation->old_plan);
        $this->assertEquals('silver', $simulation->new_plan);

        // Swap Plan
        $subscription->swap('silver');
        $this->assertEquals('silver', $subscription->{$this->iuguSubscriptionModelPlanColumn});

        // Delay, wait for iugu register invoice
        sleep(2);

        // Invoice Tests
        $invoices = $user->invoices();
        $invoice = $invoices->first();

        $this->assertEquals('R$ 5,00', $invoice->total());
        $this->assertEquals($invoice->items[0]->description, 'Mudança de Plano: [antigo] Silver');
        $this->assertFalse($invoice->hasDiscount());
        $this->assertInstanceOf(Carbon::class, $invoice->date());


        // Invoice PDF test
        /*$pdf = $user->downloadInvoice($invoice->id, [
                'vendor'  => 'Sua Empresa',
                'product' => 'Seu Produto'
        ]);

        // Then just save it like this
        $this->assertStringStartsWith('%PDF-1.3', $pdf->getContent());*/

        // Swap plan, but skip charge
        // Swap Plan
        $subscription->swap('gold', true);
        $this->assertEquals('gold', $subscription->{$this->iuguSubscriptionModelPlanColumn});

        // Delay, wait for iugu register invoice
        sleep(2);

        // no new invoice created
        $invoices = $user->invoices();
        $this->assertEquals(2, $invoices->count());

        $user = $this->createUser();

        // Create Subscription if charge
        $user->newSubscription('main', 'gold')->chargeOnSuccess()->create($this->getTestFailToken());

        $this->assertFalse($user->subscribed('main'));
        $this->assertFalse($user->onPlan('gold'));
    }

    public function testCreatingSubscriptionWithTrial()
    {
        $user = $this->createUser();

        // Create Subscription
        $user->newSubscription('main', 'gold')
            ->trialDays(7)->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trial_ends_at->day);

        // Cancel Subscription
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onGracePeriod());

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->onTrial());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trial_ends_at->day);
    }

    public function testCreatingSubscriptionAfterBadGatewayException()
    {
        $user = \Mockery::mock(User::class)->makePartial();

        $user->email = $this->faker->safeEmail;
        $user->name = $this->faker->name;

        $this->getTestToken();
        $subscription = $user->subscription('main');
        $this->assertNull($subscription);

        $user->shouldReceive('createIuguSubscription')->andReturnUsing(function ($options) {
            IuguSubscription::create($options);
            throw new \IuguRequestException('', 502);
        });

        // Create Subscription
        $subscription = $user->newSubscription('main', 'gold')->create();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onTrial());
        $this->assertEquals($subscription->user_id, $user->id);
    }

    public function testCreatingSubscriptionWithDaysToExpire()
    {
        $user = $this->createUser();

        // Create Subscription
        $user->newSubscription('main', 'gold')
            ->daysToExpire(5)->create();

        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onTrial());

        $iuguSubsciption = $subscription->asIuguSubscription();

        $this->assertEquals($iuguSubsciption->expires_at, Carbon::now()->addDays(5)->format('Y-m-d'));
    }

    public function testMarkingAsCancelledFromWebhook()
    {
        $user = $this->createUser();

        // Create Subscription
        $user->newSubscription('main', 'gold')
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $request = Request::create('/', 'POST', [
            'event' => 'subscription.expired',
            'data' => [
                "id"  => $subscription->{$this->iuguSubscriptionModelIdColumn},
                "customer_name" => "Gabriel Peixoto",
                "customer_email" => "gabriel@teste.com.br",
                "expires_at" => Carbon::now()->format('Y-m-d')
            ],
        ]);
        $controller = new WebhookController();
        $response = $controller->handleWebhook($request);
        $this->assertEquals(200, $response->getStatusCode());
        $user = $user->fresh();
        $subscription = $user->subscription('main');
        $this->assertTrue($subscription->cancelled());
    }

    /*
     * Charge Tests
     */
    public function testGetCardsFromIugoCustomer()
    {
        $user = $this->createUser();

        $user->createAsIuguCustomer($token = $this->getTestToken());

        $cards = $user->cards();
        $this->assertInstanceOf(Collection::class, $cards);
        $this->assertEquals($cards->count(), 1);

        $card = $cards->first()->asIuguCard()->data;

        $this->assertEquals($token->extra_info->bin, $card->bin);
        $this->assertEquals($token->extra_info->year, $card->year);
        $this->assertEquals($token->extra_info->month, $card->month);
        $this->assertEquals(strtolower($token->extra_info->brand), strtolower($card->brand));
        $this->assertEquals($token->extra_info->holder_name, $card->holder_name);
        $this->assertEquals($token->extra_info->display_number, $card->display_number);
    }

    public function testCreateCardsToIuguCustomer()
    {
        $user = $this->createUser();

        $user->createAsIuguCustomer($token = $this->getTestToken());
        $card = $user->createCard($token = $this->getTestTokenMasterCard());

        $this->assertEquals($token->extra_info->bin, $card->data->bin);
        $this->assertEquals($token->extra_info->year, $card->data->year);
        $this->assertEquals($token->extra_info->month, $card->data->month);
        $this->assertEquals(strtolower($token->extra_info->brand), strtolower($card->data->brand));
        $this->assertEquals($token->extra_info->holder_name, $card->data->holder_name);
        $this->assertEquals($token->extra_info->display_number, $card->data->display_number);
    }

    public function testCreateCardWithoutIuguCustomer()
    {
        $user = $this->createUser();

        try {
            $user->createCard($this->getTestToken());
        } catch (Exception $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
        }
    }

    public function testGetCardFromCustomerCards()
    {
        $user = $this->createUser();

        try {
            $user->createAsIuguCustomer();
            $createdCard = $user->createCard($token = $this->getTestToken());

            $card = $user->findCard($createdCard->id);
        } catch (\IuguObjectNotFound $e) {
            $this->fail('Service unavailable.');
        }

        $this->assertEquals(
            $card->asIuguCard()->data,
            $createdCard->asIuguCard()->data
        );
    }

    public function testGetNotExistentCard()
    {
        $user = $this->createUser();

        $user->createAsIuguCustomer();

        $this->assertNull($user->findCard(1));
    }

    public function testDeleteACardFromCustomerCards()
    {
        $user = $this->createUser();

        try {
            $user->createAsIuguCustomer();
            $cardCreated = $user->createCard($token = $this->getTestToken());

            $user->deleteCard($cardCreated);
        } catch (\IuguObjectNotFound $e) {
            $this->fail('Service unavailable.');
        }

        try {
            $user->findCardOrFail($cardCreated->id);
        } catch (Exception $e) {
            $this->assertInstanceOf(NotFoundHttpException::class, $e);
        }
    }

    public function testFindCardOrFailWithExistentCard()
    {
        $user = $this->createUser();

        $user->createAsIuguCustomer();
        $createdCard = $user->createCard($this->getTestToken())->asIuguCard();
        $foundCard = $user->findCardOrFail($createdCard->id)->asIuguCard();

        $this->assertEquals($createdCard->id, $foundCard->id);
        $this->assertEquals($createdCard->description, $foundCard->description);
        $this->assertEquals($createdCard->item_type, $foundCard->item_type);
        $this->assertEquals($createdCard->customer_id, $foundCard->customer_id);
        $this->assertEquals($createdCard->data, $foundCard->data);
    }

    public function testDeleteAllCustomerCards()
    {
        $user = $this->createUser();

        $user->createAsIuguCustomer();
        $firstCard = $user->createCard($this->getTestToken());
        $secondCard = $user->createCard($this->getTestTokenMasterCard());

        $user->deleteCards();

        try {
            $user->findCardOrFail($firstCard->id);
        } catch (Exception $e) {
            $this->assertInstanceOf(NotFoundHttpException::class, $e);
        }

        try {
            $user->findCardOrFail($secondCard->id);
        } catch (Exception $e) {
            $this->assertInstanceOf(NotFoundHttpException::class, $e);
        }
    }

    public function testFindCardOrFailOfAnotherUser()
    {
        $user = $this->createUser();

        try {
            $user->createAsIuguCustomer();
            $createdCard = $user->createCard($token = $this->getTestToken());
        } catch (\IuguObjectNotFound $e) {
            $this->fail('Service unavailable.');
        }

        $anotherUser = $this->createUser();

        try {
            $anotherUser->createAsIuguCustomer();
            $anotherUser->findCardOrFail($createdCard->id);
        } catch (Exception $e) {
            $this->assertInstanceOf(NotFoundHttpException::class, $e);
        }
    }

    public function testCreatingOneSingleChargeWithNewCard()
    {
        $user = $this->createUser();

        try {
            $user->createAsIuguCustomer();
            $card = $user->createCard($token = $this->getTestTokenMasterCard());

            $charge = $user->charge(250, [
                'customer_payment_method_id' => $card->id,
            ]);
        } catch (\IuguObjectNotFound $e) {
            $this->fail('Service unavailable.');
        }

        $this->assertEquals(
            $card->asIuguCard()->id,
            $charge->customer_payment_method_id
        );
        $this->assertEquals($user->{$this->iuguUserModelColumn}, $charge->customer_id);
    }

    public function testCreatingOneSingleChargeWithBankSlipMethod()
    {
        $user = $this->createUser();

        try {
            $user->createAsIuguCustomer();
            $charge = $user->charge(250, [
                'method' => 'bank_slip',
                'payer' => [
                    'cpf_cnpj' => $this->faker->unique()->cpf,
                    'name' => $this->faker->firstName,
                    'email' => $this->faker->unique()->safeEmail,
                    'phone_prefix' => $this->faker->areaCode,
                    'phone' => $this->faker->cellphone,
                    'address' => [
                        'street'     => $this->faker->streetName,
                        'number'     => $this->faker->buildingNumber,
                        'district'   => $this->faker->streetAddress,
                        'city'       => $this->faker->city,
                        'state'      => $this->faker->stateAbbr,
                        'zip_code'   => '72603-212',
                        'complement' => $this->faker->secondaryAddress,
                    ]
                ]
            ]);
        } catch (\IuguObjectNotFound $e) {
            $this->fail('Service unavailable.');
        }

        $this->assertTrue($charge->success);
        $this->assertEquals($charge->method, 'bank_slip');
    }

    public function testSingleBankSlipChargeWithDefaultPaymentCardDefined()
    {
        $user = $this->createUser();

        try {
            $user->createAsIuguCustomer($this->getTestToken());
            $charge = $user->charge(250, [
                'method' => 'bank_slip',
                'payer' => [
                    'cpf_cnpj' => $this->faker->unique()->cpf,
                    'name' => $this->faker->firstName,
                    'email' => $this->faker->unique()->safeEmail,
                    'phone_prefix' => $this->faker->areaCode,
                    'phone' => $this->faker->cellphone,
                    'address' => [
                        'street'     => $this->faker->streetName,
                        'number'     => $this->faker->buildingNumber,
                        'district'   => $this->faker->streetAddress,
                        'city'       => $this->faker->city,
                        'state'      => $this->faker->stateAbbr,
                        'zip_code'   => '72603-212',
                        'complement' => $this->faker->secondaryAddress,
                    ]
                ]
            ]);
        } catch (\IuguObjectNotFound $e) {
            $this->fail('Service unavailable.');
        }

        $this->assertTrue($charge->success);
        $this->assertEquals($charge->method, 'bank_slip');
    }

    public function testCreatingOneSingleChargeWithoutPaymentSource()
    {
        $user = $this->createUser();

        $user->createAsIuguCustomer();

        try {
            $user->charge(100);
        } catch (Exception $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
        }
    }

    public function testCreatingOneSingleChargeWithoutCustomer()
    {
        $user = $this->createUser();

        $token = $this->getTestToken();

        try {
            $charge = $user->charge(100, [
                'token' => $token->id,
            ]);
        } catch (Exception $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
        }
    }

    public function testCreatingOneSingleChargeWithJustInvoiceId()
    {
        $user = $this->createUser();
        $user->createAsIuguCustomer($this->getTestToken());
        $charge = $user->charge(100);

        $anotherCharge = $user->charge(200, [
            'invoice_id' => $charge->invoice_id
        ]);

        // The error message 'A fatura cobrada precisa estar pendente' is returned
        $this->assertTrue(isset($anotherCharge->errors));
    }

    public function testCreatingOneSingleCharge()
    {
        $user = $this->createUser();

        try {
            $user->createAsIuguCustomer($this->getTestToken());
            $charge = $user->charge(100);
        } catch (\IuguObjectNotFound $e) {
            $this->fail('Service unavailable');
        }

        $this->assertTrue($charge->success);
        $this->assertEquals($charge->items[0]['quantity'], 1);
        $this->assertEquals($charge->items[0]['price_cents'], 100);
    }

    public function testCreatingOneSingleChargePassingItems()
    {
        $user = $this->createUser();

        try {
            $user->createAsIuguCustomer($this->getTestToken());
            $charge = $user->charge(100, [
                'items' => [
                    ['description' => 'First Item',  'quantity' => 1, 'price_cents' => 100],
                    ['description' => 'Second Item', 'quantity' => 1, 'price_cents' => 250],
                    ['description' => 'Third Item',  'quantity' => 2, 'price_cents' => 150],
                ],
            ]);
        } catch (\IuguObjectNotFound $e) {
            $this->fail('Service unavailable');
        }

        $this->assertTrue($charge->success);

        $this->assertEquals($charge->items[0]['description'], 'First Item');
        $this->assertEquals($charge->items[0]['quantity'], 1);
        $this->assertEquals($charge->items[0]['price_cents'], 100);

        $this->assertEquals($charge->items[1]['description'], 'Second Item');
        $this->assertEquals($charge->items[1]['quantity'], 1);
        $this->assertEquals($charge->items[1]['price_cents'], 250);

        $this->assertEquals($charge->items[2]['description'], 'Third Item');
        $this->assertEquals($charge->items[2]['quantity'], 2);
        $this->assertEquals($charge->items[2]['price_cents'], 150);
    }

    public function testCreatingOneSingleChargePassingToken()
    {
        $user = $this->createUser();

        try {
            $token = $this->getTestToken();
            $charge = $user->charge(100, [
                'token' => $token->id,
                'email' => $user->email,
            ]);
        } catch (\IuguObjectNotFound $e) {
            $this->fail('Service unavailable');
        }

        $this->assertTrue($charge->success);
        $this->assertEquals($charge->token, $token->id);
        $this->assertEquals($charge->email, $user->email);
        $this->assertEquals($charge->items[0]['quantity'], 1);
        $this->assertEquals($charge->items[0]['price_cents'], 100);
    }

    public function testCreatingOneSingleChargePassingItemsAndToken()
    {
        $user = $this->createUser();

        try {
            $token = $this->getTestToken();
            $charge = $user->charge(100, [
                'token' => $token->id,
                'email' => $user->email,
                'items' => [
                    ['description' => 'First Item',  'quantity' => 1, 'price_cents' => 100],
                    ['description' => 'Second Item', 'quantity' => 1, 'price_cents' => 250],
                    ['description' => 'Third Item',  'quantity' => 2, 'price_cents' => 150],
                ],
            ]);
        } catch (\IuguObjectNotFound $e) {
            $this->fail('Service unavailable');
        }

        $this->assertTrue($charge->success);
        $this->assertEquals($charge->token, $token->id);
        $this->assertEquals($charge->email, $user->email);

        $this->assertEquals($charge->items[0]['description'], 'First Item');
        $this->assertEquals($charge->items[0]['quantity'], 1);
        $this->assertEquals($charge->items[0]['price_cents'], 100);

        $this->assertEquals($charge->items[1]['description'], 'Second Item');
        $this->assertEquals($charge->items[1]['quantity'], 1);
        $this->assertEquals($charge->items[1]['price_cents'], 250);

        $this->assertEquals($charge->items[2]['description'], 'Third Item');
        $this->assertEquals($charge->items[2]['quantity'], 2);
        $this->assertEquals($charge->items[2]['price_cents'], 150);
    }

    public function testRefundInvoice()
    {
        $user = $this->createUser();

        try {
            $user->createAsIuguCustomer($this->getTestToken());
            $charge = $user->charge(100);
        } catch (\IuguObjectNotFound $e) {
            $this->fail('Service unavailable');
        }

        $status = $user->refund($charge->invoice_id);

        $this->assertTrue($charge->success);
        $this->assertTrue($status);
    }

    public function testValidateCardWithTrial()
    {
        $user = $this->createUser();

        // Create Subscription
        $user->newSubscription('main', 'gold', [], ['payable_with' => 'credit_card'])->validateCard()->trialDays(30)->create($this->getTestToken());

        $this->assertEquals(1, $user->subscriptions()->count());
        $this->assertEquals(1, $user->invoices(true)->count());
        $this->assertEquals('refunded', $user->invoices(true)->first()->status);
        $this->assertEquals(100, $user->invoices(true)->first()->total_cents);

        $subscriptionIugu = $user->subscription('main')->asIuguSubscription();

        $this->assertEquals('credit_card', $subscriptionIugu->payable_with);
    }

    public function testCreateInvoice()
    {
        $user = $this->createUser();

        $user->createAsIuguCustomer($token = $this->getTestToken());

        $options = ['payer' => [
            'cpf_cnpj' => '169.893.520-00',
            'address' => [
                'zip_code' => '41150-120',
                'number' => '1'
            ],
            'name' => $user->name
        ]];

        $invoice = $user->createInvoice(100, Carbon::now(), 'Um item', $options);

        $this->assertEquals($invoice->payable_with, 'all');
        $this->assertEquals($invoice->total, 'R$ 1,00');

        $invoice = $user->newInvoice(Carbon::now());

        $invoice->addItem(100, 'Item 1', 1);
        $invoice->addItem(200);
        $invoice = $invoice->create($options);

        $this->assertEquals($invoice->payable_with, 'all');
        $this->assertEquals($invoice->total, 'R$ 3,00');
    }

    public function testCreatingSubscriptionWithRecurrentDiscount()
    {
        $user = $this->createUser();

        $item1 = [
            'description' => 'Desconto recorrente',
            'price_cents' => -900,
            'quantity' => 1,
            'recurrent' => true,
        ];

        $item2 = [
            'description' => 'Adicional não recorrente',
            'price_cents' => 250,
            'quantity' => 1,
            'recurrent' => false,
        ];

        $subItems = [$item1, $item2];

        // Create Subscription
        $user->newSubscription('main', 'gold')
            ->payWith('credit_card')
            ->subItems($subItems)
            ->create($this->getTestToken());

        $subscriptionIugu = $user->subscription('main')->asIuguSubscription();

        $this->assertEquals(1, count($subscriptionIugu->subitems));
        $this->assertArraySubset($item1, (array)$subscriptionIugu->subitems[0]);
    }

    public function testCanRetrieveSoftDeletedUser()
    {
        $user = $this->createUser();

        // Create Subscription
        $subscription = $user->newSubscription('main', 'gold')
            ->payWith('credit_card')
            ->create($this->getTestToken());

        $user->delete();

        $this->assertInstanceOf(User::class, $subscription->user);
    }

    public function testDuplicateInvoice()
    {
        $user = $this->createUser();

        $user->createAsIuguCustomer($token = $this->getTestToken());

        $options = ['payer' => [
            'cpf_cnpj' => '169.893.520-00',
            'address' => [
                'zip_code' => '41150-120',
                'number' => '1'
            ],
            'name' => $user->name
        ]];

        $invoice = $user->createInvoice(100, Carbon::now(), 'Um item', $options);

        $config = [
            'due_date' => Carbon::now()->addDays(3),
            'keep_early_payment_discount' => true
        ];

        $invoiceDuplicate = $user->duplicate($invoice->id, $config);
        $canceledInvoice = $user->findInvoice($invoice->id);
        $this->assertEquals($canceledInvoice->status, 'canceled');
        $this->assertEquals($invoiceDuplicate->status, 'pending');
        $this->assertEquals($canceledInvoice->total_cents, $invoiceDuplicate->total_cents);
        $this->assertEquals($invoiceDuplicate->logs[0]->notes, "Segunda via gerada da FATURA # " . $canceledInvoice->id);
        $this->assertEquals(count($canceledInvoice->items), count($invoiceDuplicate->items));
    }

    protected function createUser()
    {
        return User::create([
            'email' => $this->faker->safeEmail,
            'name' => $this->faker->name,
        ]);
    }

    protected function getTestToken()
    {
        \Iugu::setApiKey(getenv('IUGU_APIKEY'));
        \Iugu::setLogErrors(getenv("IUGU_LOG_ERRORS"));

        return \Iugu_PaymentToken::create([
            "account_id" =>  getenv('IUGU_ID'),
            "method" => "credit_card",
            "data" => [
                "number" => "4111111111111111",
                "verification_value" => "123",
                "first_name" => "Joao",
                "last_name" => "Silva",
                "month" => "12",
                "year" => Carbon::now()->addYear()->year,
            ],
            "test" => 1
        ]);
    }

    protected function getTestTokenMasterCard()
    {
        \Iugu::setApiKey(getenv('IUGU_APIKEY'));
        \Iugu::setLogErrors(getenv("IUGU_LOG_ERRORS"));

        return \Iugu_PaymentToken::create([
            "account_id" =>  getenv('IUGU_ID'),
            "method" => "credit_card",
            "data" => [
                "number" => "5555555555554444",
                "verification_value" => "123",
                "first_name" => "Joao",
                "last_name" => "Silva",
                "month" => "12",
                "year" => Carbon::now()->addYear()->year,
            ],
            "test" => 1
        ]);
    }

    protected function getTestFailToken()
    {
        \Iugu::setApiKey(getenv('IUGU_APIKEY'));
        \Iugu::setLogErrors(getenv("IUGU_LOG_ERRORS"));

        return \Iugu_PaymentToken::create([
            "account_id" =>  getenv('IUGU_ID'),
            "method" => "credit_card",
            "data" => [
                "number" => "4012888888881881",
                "verification_value" => "123",
                "first_name" => "Joao",
                "last_name" => "Silva",
                "month" => "12",
                "year" => Carbon::now()->addYear()->year,
            ],
            "test" => 1
        ]);
    }

    /**
     * Schema Helpers.
     */
    protected function schema()
    {
        return $this->connection()->getSchemaBuilder();
    }

    protected function connection()
    {
        return Eloquent::getConnectionResolver()->connection();
    }
}
