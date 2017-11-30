# GuPayment

## Introdução

GuPayment é baseado no Laravel Cashier e fornece uma interface para controlar assinaturas do iugu.com

## Instalação Laravel 5.x

Instale esse pacote pelo composer:

```
composer require potelo/gu-payment
```

Adicione o ServiceProvider em config/app.php

```php
Potelo\GuPayment\GuPaymentServiceProvider::class,
```

Antes de usar o GuPayment você precisa preparar o banco de dados. Você precisa adicionar algumas colunas no seu modelo
de Usuario e criar uma tabela para as assinaturas com o nome 'subscriptions'.

```php
Schema::table('[SUA_TABELA_DE_USUARIO]', function ($table) {
    $table->string('iugu_id')->nullable();
});

Schema::create('[SUA_TABELA_DE_ASSINATURAS]', function ($table) {
    $table->increments('id');
    $table->integer('user_id');
    $table->string('name');
    $table->string('iugu_id');
    $table->string('iugu_plan');
    $table->timestamp('trial_ends_at')->nullable();
    $table->timestamp('ends_at')->nullable();
    $table->timestamps();
});
```

Uma vez criado os migrations, basta rodar o comando php artisan migrate.

Vamos agora adicionar o Trait ao seu modelo do usuário.

```php
use Potelo\GuPayment\GuPaymentTrait;

class User extends Authenticatable
{
    use GuPaymentTrait;
}
```

Agora vamos adicionar em config/services.php duas configurações. A classe do usuário e o nome da tabela
utilizada para gerenciar as assinaturas, a mesma escolhida na criação do migration

```php
'iugu' => [
    'model'  => App\User::class,
    'key' => env('IUGU_APIKEY'),
    'signature_table' => env('GUPAYMENT_SIGNATURE_TABLE')
]
```

e no seu arquivo .env você coloca as informações do nome da tabela e da sua chave de api que o Iugu fornece.

```
IUGU_APIKEY=SUA_CHAVE
GUPAYMENT_SIGNATURE_TABLE=usuario_assinatura
```

## Assinaturas

### Criando assinaturas

Para criar uma assinatura, primeiro você precisa ter uma instância de um usuário que extende o GuPaymentTrait. Você então deve usar o método `newSubscription` para criar uma assinatura:
```php
$user = User::find(1);

$user->newSubscription('main', 'gold')->create($creditCardToken);
```
O primeiro argumento deve ser o nome da assinatura. Esse nome não será utilizado no Iugu.com, apenas na sua aplicação. Se sua aplicação tiver apenas um tipo de assinatura, você pode chamá-la de principal ou primária. O segundo argumento é o identificador do plano no Iugu.com.

O método `create` automaticamente criará uma assinatura no Iugu.com e atualizará o seu banco de dados com o ID do cliente referente ao Iugu e outras informações relevantes. Você pode chamar o `create` sem passar nenhum parâmetro ou informar o token do cartão de crédito para que o usuário tenha uma forma de pagamento padrão. Veja como gerar o token em [iugu.js](https://iugu.com/referencias/iugu-js)

Caso queira que a assinatura seja criada apenas após a comprovação do pagamento, basta chamar o método  `chargeOnSuccess` após `newSubscription`. **IMPORTANTE**: Esse modo de criar uma assinatura só funciona para o cliente que tenha um método de pagamento padrão, não funciona com boleto.
```php
$user = User::find(1);

$user->newSubscription('main', 'gold')
->chargeOnSuccess()
->create($creditCardToken);
```
#### Dados adicionais
Se você desejar adicionar informações extras ao usuário e assinatura, basta passar um terceiro parâmetro no método `newSubscription` para informações adicionais da assinatura e/ou um segundo parâmetro no método `create` para informações adicionais do cliente:
```php
$user = User::find(1);

$user->newSubscription('main', 'gold', ['adicional_assinatura' => 'boa assinatura'])
     ->create(NULL, [
         'name' => $user->nome,
         'adicional_cliente' => 'bom cliente'
     ]);
```
Para mais informações dos campos que são suportados pelo Iugu confira a [Documentação oficial](https://iugu.com/referencias/api#assinaturas)

#### Subitens
Se você desejar adicionar subitens na assinatura (para planos pagos), basta passar um quarto parâmetro no método `newSubscription`:
```php
$user = User::find(1);

$user->newSubscription('main', 'gold', ['adicional_assinatura' => 'boa assinatura'], [
        'description' => 'Desconto XXXXXX',
        'price_cents' => 500,
        'quantity' => 1,
        'recurrent' => false
    ])
     ->create(NULL, [
	     'name' => $user->nome,
	     'adicional_cliente' => 'bom cliente'
	 ]);
```
O valor (price_cents) pode ser negativo ou positivo, caso negativo, o subitem será criado como desconto em sua assinatura.
Para mais informações dos campos que são suportados pelo Iugu confira a [Documentação oficial](https://dev.iugu.com/v1.0/reference#criar-1)

### Checando status da assinatura

Uma vez que o usuário assine um plano na sua aplicação, você pode verificar o status dessa assinatura através de alguns métodos. O método `subscribed` retorna **true** se o usuário possui uma assinatura ativa, mesmo se estiver no período trial:
```php
if ($user->subscribed('main')) {
    //
}
```

O método `subscribed`pode ser utilizado em um route middleware, permitindo que você filtre o acesso de rotas baseado no status da assinatura do usuário:

```php
public function handle($request, Closure $next)
{
    if ($request->user() && ! $request->user()->subscribed('main')) {
        // This user is not a paying customer...
        return redirect('billing');
    }

    return $next($request);
}
```

Se você precisa saber se um a assinatura de um usuário está no período trial, você pode usar o método `onTrial`. Esse método pode ser útil para informar ao usuário que ele está no período de testes, por exemplo:
```php
if ($user->subscription('main')->onTrial()) {
    //
}
```
O método `onPlan` pode ser usado para saber se o usuário está assinando um determinado plano. Por exemplo, para verificar se o usuário assina o plano **gold**:
```php
if ($user->onPlan('gold')) {
    //
}
```

Para saber se uma assinatura foi cancelada, basta usar o método `cancelled` na assinatura:
```php
if ($user->subscription('main')->cancelled()) {
    //
}
```
Você também pode checar se uma assinatura foi cancelada mas o usuário ainda se encontra no "período de carência". Por exemplo, se um usuário cancelar a assinatura no dia 5 de Março mas a data de vencimento é apenas no dia 10, ele está nesse período de carência até o dia 10. Para saber basta utilizar o método `onGracePeriod`:

```php
if ($user->subscription('main')->onGracePeriod()) {
    //
}
```

### Mudando o plano da assinatura
Se um usuário já possui uma assinatura, ele pode querer mudar para algum outro plano. Por exemplo, um usuário do plano **gold** pode querer economizar e mudar para o plano **silver**. Para mudar o plano de um usuário em uma assinatura, basta usar o método `swap` da seguinte forma:

```php
$user = App\User::find(1);

$user->subscription('main')->swap('silver');
```
### Cancelando assinaturas
Para cancelar uma assinatura, basta chamar o método `cancel` na assinatura do usuário:
```php
$user->subscription('main')->cancel();
```

### Reativando assinaturas
Se um usuário tem uma assinatura cancelada e gostaria de reativá-la, basta utilizar o método `resume`. Ele precisa está no "período de carência" para conseguir reativá-la:
```php
$user->subscription('main')->resume();
```
## Assinatura trial

Se você desejar oferecer um período trial para os usuários, você pode usar o método `trialDays` ao criar uma assinatura:
```php
$user = User::find(1);

$user->newSubscription('main', 'gold')
            ->trialDays(10)
            ->create($creditCardToken);
```
O usuário só sebra cobrado, após o período trial. Lembrando que para verificar se um usuário está com a assinatura no período trial, basta chamar o método `onTrial`:
```php
if ($user->subscription('main')->onTrial()) {
    //
}
```
## Tratando os gatilhos (ou Webhooks)
[Gatilhos (ou Webhooks)](https://iugu.com/referencias/gatilhos) são endereços (URLs) para onde a Iugu dispara avisos (Via método POST) para certos eventos que ocorrem em sua conta. Por exemplo, se uma assinatura do usuário for cancelada e você precisar registrar isso em seu banco, você pode usar o gatilho. Para utilizar você precisa apontar uma rota para o método `handleWebhook`, a mesma rota que você configurou no seu painel do Iugu:
```php
Route::post('webhook', '\Potelo\GuPayment\Http\Controllers\WebhookController@handleWebhook');
```
O GuPayment tem métodos para atualizar o seu banco de dados caso uma assinatura seja suspensa ou ela expire. Apontando a rota para esse método, isso ocorrerá de forma automática.
Lembrando que você precisa desativar a [proteção CRSF](https://laravel.com/docs/5.2/routing#csrf-protection) para essa rota. Você pode colocar a URL em `except` no middleware `VerifyCsrfToken`:
```php
protected $except = [
   'webhook',
];
```
### Outros gatilhos
O Iugu possui vários outros gatilhos e para você criar para outros eventos basta estender o `WebhookController`. Seus métodos devem corresponder a **handle** + o nome do evento em "camelCase". Por exemplo, ao criar uma nova fatura, o Iugu envia um gatilho com o seguinte evento: `invoice.created`, então basta você criar um método chamado `handleInvoiceCreated`.
```php
Route::post('webhook', 'MeuWebhookController@handleWebhook');
```

```php
<?php

namespace App\Http\Controllers;

use Potelo\GuPayment\Http\Controllers\WebhookController;

class MeuWebhookController extends WebhookController {

    public function handleInvoiceCreated(array $payload)
    {
        return 'Fatura criada: ' . $payload['data']['id'];
    }
}
```

Caso queira testar os webhooks em ambiente local, você pode utilizar o [ngrok](https://ngrok.com/).
## Faturas
Você pode facilmente pegar as faturas de um usuário através do método `invoices`:
```php
$invoices = $user->invoices();
```
Esse método irá trazer apenas as faturas que já foram pagas, caso queira incluir as faturas pendentes, basta passar o primeiro parâmetro como `true`:
```php
$invoices = $user->invoices(true);
```
Você pode listar as faturas de um usuário e disponibilizar pdfs de cada uma delas. Por exemplo:
```html
<table>
  @foreach ($user->invoices() as $invoice)
    <tr>
      <td>{{ $invoice->date() }}</td>
      <td>{{ $invoice->total() }}</td>
      <td><a href="/user/invoice/{{ $invoice->id }}">Download</a></td>
    </tr>
  @endforeach
</table>
```
Para gerar o pdf basta utilizar o método `downloadInvoice`:
```php
return $user->downloadInvoice($invoiceId, [
        'vendor'  => 'Sua Empresa',
        'product' => 'Seu Produto',
    ]);
```

## Clientes e métodos de Pagamento (Cartões)

Para gerenciar os métodos de pagamento, o cliente precisa existir no Iugu. Quando você utiliza o método `newSubscription` o cliente é criado automaticamente. Porém para criar um cliente manualmente, você pode utilizar o método `createAsIuguCustomer`.
```php
// Criar cliente no Iugu
$user->createAsIuguCustomer();

// Criar cliente no Iugu com token do cartão de crédito
$user->createAsIuguCustomer($creditCardToken);

```

Após ter um cliente cadastrado no Iugu, você pode gerenciar seus métodos de pagamento. Para criar um cartão utilize o método `createCard`:

```php
$user->createCard($creditCardToken);
```

O método aceita um array como segundo argumento com as opções disponíveis para criação de um método de pagamento. O cartão é criado sendo definido como `default` nos cartões do cliente. Se quiser alterar esse comportamento passe a chave `set_as_default` com o valor `false` nas opções do segundo parâmetro do método:

```
$user->createCard($creditCardToken, [
    'set_as_default' => false,
]);
```

Para obter os cartões de um cliente você pederá utilizar os métodos `cards` (Retorna uma `Illuminate\Support\Collection` de cartões), `findCard` (Retorna uma instância de `Potelo\GuPayment\Card` ou `null` se o cartão não for encontrado) ou `findCardOrFail` (Retorna uma instância de `Potelo\GuPayment\Card` ou lança uma exceção caso o cartão não seja encontrado):

```php
// Coleção de cartões
$user->cards();

// Um cartão ou null
$card = $user->findCard($cardId);

try {
    $card = $user->findCardOrFail($cardId);
} catch(Exception $e) {
    //
}
```

Para deletar um cartão apenas obtenha uma instância de `Potelo\GuPayment\Card` e use o metodo `deleteCard`:

```php
$card = $user->findCard($cardId);

$user->deleteCard($card);

```

Para deletar todos os cartões use `deleteCards`:

```php
$user->deleteCards();
```


## Cobrança simples

Se você quiser fazer uma cobrança simples com o cartão de crédito, você pode usar o método de `charge` em uma instância de um usuário que use o Trait `GuPaymentTrait`. Para utilizar a cobrança simples nesse pacote, é necessário que o cliente já esteja cadastrado no Iugu.

```php
// Iugu aceita cobranças em centavos
$user->charge(100);
```

O método `charge` aceita um array como segundo parâmetro, permitindo que você passe algumas opções desejadas para criação de uma cobrança no Iugu. Consulte a [documentação do Iugu](https://dev.iugu.com/v1.0/reference#testinput-1) para saber as opções disponíveis ao criar uma cobrança:

```php
$user->charge(100, [
    'customer_payment_method_id' => $card->id,
]);
```

Por padrão um item será criado com as seguintes definições:

```
description = 'Nova cobrança'
quantity = 1
price_cents = Valor do primeiro parâmetro
```

Sinta-se livre para adicionar seus próprios items como preferir no segundo parâmetro:

```
user->charge(null, [
    'items' => [
        ['description' => 'Primeiro Item', 'quantity' => 10, 'price_cents' => 200],
        ['description' => 'Segundo Item', 'quantity' => 2, 'price_cents' => 200],
    ]
]);
```

OBS: Se um array de items for passado no segundo argumento o item padrão não será adicionado.

