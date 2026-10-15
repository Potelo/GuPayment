<?php

namespace Potelo\GuPayment\Tests;

use Iugu_APIRequest;
use IuguRequestException;
use PHPUnit\Framework\TestCase;
use Potelo\GuPayment\Tests\Fixtures\IuguApiComIntervalo;

class IuguApiComIntervaloTest extends TestCase
{
    /**
     * Aguarda antes de cada chamada e preserva método, URL, payload e resposta do SDK.
     */
    public function testEspacaTodasAsRequisicoesSemAlterarOsDados(): void
    {
        $eventos = [];
        $resposta = (object) ['id' => 'cliente_teste'];
        $dados = ['email' => 'cliente@example.com'];
        $headers = ['X-Request-Id: teste'];
        $url = 'https://api.iugu.com/v1/customers';
        $api = $this->createMock(Iugu_APIRequest::class);
        $api->expects($this->exactly(2))->method('request')
            ->with('POST', $url, $dados, $headers)
            ->willReturnCallback(function () use (&$eventos, $resposta) {
                $eventos[] = 'requisicao';
                return $resposta;
            });

        $cliente = new IuguApiComIntervalo($api, function ($microssegundos) use (&$eventos) {
            $eventos[] = $microssegundos;
        });

        $this->assertSame($resposta, $cliente->request('POST', $url, $dados, $headers));
        $this->assertSame($resposta, $cliente->request('POST', $url, $dados, $headers));
        $this->assertSame([1500000, 'requisicao', 1500000, 'requisicao'], $eventos);
        $this->assertSame(['POST', $url, $dados, $headers], $cliente->ultimaRequisicao);
    }

    /**
     * Preserva erros do SDK sem repetir operações que podem criar cobranças.
     */
    public function testPropagaFalhaSemRepetirACobranca(): void
    {
        $api = $this->createMock(Iugu_APIRequest::class);
        $erro = new IuguRequestException('Falha na cobrança.');
        $api->expects($this->once())->method('request')->willThrowException($erro);
        $cliente = new IuguApiComIntervalo($api, function () {
        });

        $this->expectExceptionObject($erro);
        $cliente->request('POST', 'https://api.iugu.com/v1/charge');
    }
}
