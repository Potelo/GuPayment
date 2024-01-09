<?php

namespace Potelo\GuPayment\Tests;

use Iugu;
use Potelo\GuPayment\GuPaymentServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Iugu::setLogErrors(false);
    }

    protected function getPackageProviders($app)
    {
        return [
            GuPaymentServiceProvider::class,
        ];
    }
}
