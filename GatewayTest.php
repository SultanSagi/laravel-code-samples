<?php

namespace Tests\Feature\PayPort;

use App\Enum\DepositStatus;
use App\Enum\WithdrawStatus;
use App\Models\Configuration;
use App\Models\Currency;
use App\Models\Deposit;
use App\Models\Gateway;
use App\Models\Paymethod;
use App\Models\Withdraw;
use App\Module\Gateway\Events\DepositPaidEvent;
use App\Module\Gateway\Events\WithdrawPaidEvent;
use App\Module\PayPort\Contracts\Repositories\CreateDepositRepository;
use App\Module\PayPort\Contracts\Repositories\CreateWithdrawRepository;
use App\Module\PayPort\Contracts\Services\BuildSignatureService;
use App\Module\PayPort\Drivers\PayPortDriver;
use App\Module\PayPort\Models\Payport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\PayPort\Repositories\PayportTestRepository;
use Tests\TestCase;

class PayPortTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    public function testBuildSignature()
    {
        /** @var BuildSignatureService $buildSignatureService */
        $buildSignatureService = $this->app->make(BuildSignatureService::class);

        $params = [
            'method' => 'orderstatus',
            'order_id' => 1,
            'shopid' => '123',
        ];

        $sign = $buildSignatureService->build('', $params);

        $this->assertEquals($sign, '87aa8f9a2a65ae65935977c2376073d88249911c');
    }

    public function testConfirmDeposit()
    {
        Event::fake([DepositPaidEvent::class]);

        $gateway = Gateway::factory()->create([
            'name' => Payport::GATEWAY_NAME,
            'provider' => Payport::GATEWAY_NAME,
        ]);

        /** @var Deposit $deposit */
        $deposit = Deposit::factory()->create([
            'transaction_id' => Str::uuid()->toString(),
            'gateway_id' => $gateway,
            'status' => DepositStatus::PENDING,
        ]);

        $config = Configuration::factory()->create([
            'gateway_id' => $gateway->id, 'merchant_id' => $deposit->merchant_id, 'key' => 'merchant_apiv5_key',
            'value' => 'specialword',
        ]);

        /** @var BuildSignatureService $buildSignatureService */
        $buildSignatureService = $this->app->make(BuildSignatureService::class);

        $data = [
            'status' => 1,
            'invoice_id' => $deposit->transaction_id,
            'order_id' => $deposit->uuid,
            'amount_currency' => '123.456',
        ];

        $data['signature'] = $buildSignatureService->build($config->value, $data);

        $response = $this->postJson(route('payport.confirm-deposit'), $data);

        $response->assertJson([
            'data' => 'Deposit successfully confirmed',
            'error' => 0,
        ]);

        $this->assertDatabaseHas('deposits', [
            'id' => $deposit->id,
            'status' => DepositStatus::PAID,
            'amount' => $data['amount_currency'],
        ]);

        $this->assertDatabaseHas('webhooks', [
            'gateway_id' => $deposit->gateway->id,
            'status' => DepositStatus::PAID,
            'deposit_id' => $deposit->id,
        ]);
    }
}
