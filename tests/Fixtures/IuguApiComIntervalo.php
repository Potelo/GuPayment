<?php

namespace Potelo\GuPayment\Tests\Fixtures;

use Iugu_APIRequest;

/**
 * Espaça as chamadas reais da suíte para respeitar o sandbox da Iugu.
 */
class IuguApiComIntervalo extends Iugu_APIRequest
{
    public $ultimaRequisicao;

    private $api;

    private $aguardar;

    public function __construct(?Iugu_APIRequest $api = null, ?callable $aguardar = null)
    {
        $this->api = $api ?: new Iugu_APIRequest();
        $this->aguardar = $aguardar ?: function ($microssegundos) {
            usleep($microssegundos);
        };
    }

    public function request($method, $url, $data = [], $headers = [])
    {
        $this->ultimaRequisicao = [$method, $url, $data, $headers];

        // 1,5 s mantém a suíte abaixo das 50 requisições/minuto do sandbox.
        ($this->aguardar)(1500000);

        return $this->api->request($method, $url, $data, $headers);
    }
}
