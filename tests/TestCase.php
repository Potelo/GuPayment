<?php

namespace Potelo\GuPayment\Tests;

use Iugu;
use APIResource;
use ReflectionProperty;
use Potelo\GuPayment\Tests\Fixtures\IuguApiComIntervalo;
use Potelo\GuPayment\GuPaymentServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private $apiOriginal;

    protected function setUp(): void
    {
        parent::setUp();
        Iugu::setLogErrors(false);

        // O SDK mantém o transporte privado e estático, sem setter público.
        $transporte = new ReflectionProperty(APIResource::class, '_apiRequester');
        $transporte->setAccessible(true);
        $this->apiOriginal = $transporte->getValue();
        $transporte->setValue(null, new IuguApiComIntervalo());
    }

    protected function tearDown(): void
    {
        $transporte = new ReflectionProperty(APIResource::class, '_apiRequester');
        $transporte->setAccessible(true);
        $transporte->setValue(null, $this->apiOriginal);

        try {
            parent::tearDown();
        } finally {
            // Também separa os cenários que compartilham a mesma conta sandbox.
            sleep(2);
        }
    }

    protected function getPackageProviders($app)
    {
        return [
            GuPaymentServiceProvider::class,
        ];
    }
}
