<?php

declare(strict_types=1);

namespace App\Module\Gateway\Services;

use App\Core\Webhooks\DTO\CreateWebhookDTO;
use App\Enum\GatewayProvider;
use App\Exceptions\RulesMismatchedException;
use App\Exceptions\SignatureMismatchException;
use App\Module\Gateway\Contracts\Repositories\DepositQueryRepository;
use App\Module\Gateway\Contracts\Services\ConfigurationService;
use App\Module\Gateway\Contracts\Services\WebhookDepositService;
use App\Module\Gateway\Events\DepositConfirmedEvent;
use App\Module\Gateway\Contracts\Services\BuildSignatureService as BuildSignatureServiceContract;
use App\Module\Gateway\Contracts\Services\ConfirmDepositService as ConfirmDepositServiceContract;
use App\Module\Gateway\Contracts\Services\DepositWebhookStatusService as DepositWebhookStatusServiceContract;
use App\Module\Gateway\DTO\ConfirmDepositDTO;
use App\Module\Gateway\Enum\Status;
use App\Services\DepositService;
use Illuminate\Http\Request;

final class ConfirmDepositService implements ConfirmDepositServiceContract
{
    public function __construct(
        private readonly DepositQueryRepository $depositQueryRepository,
        private readonly BuildSignatureServiceContract $buildSignatureService,
        private readonly ConfigurationService $configurationService,
        private readonly WebhookDepositService $webhookService,
        private readonly DepositWebhookStatusServiceContract $depositWebhookStatusService,
        private readonly DepositService $depositService,
    ) {

    }

    public function confirmDeposit(ConfirmDepositDTO $confirmDepositDTO)
    {
        $deposit = $this->depositQueryRepository->findByUuid($confirmDepositDTO->orderId);
        if (GatewayProvider::GATEWAY->value !== $deposit->gateway->provider) {
            throw new RulesMismatchedException(__('Invalid gateway'));
        }

        $this->hasValidSignature($deposit->gateway_id, $deposit->merchant_id, $confirmDepositDTO);

        $isRejected = $confirmDepositDTO->status == Status::FAIL->value;

        $webhookDTO = new CreateWebhookDTO();
        $webhookDTO->webhookStatus = $this->depositWebhookStatusService->getWebhookStatus($confirmDepositDTO->status);
        $webhookDTO->orderId = $deposit->id;
        $webhookDTO->gatewayId = $deposit->gateway_id;
        $webhookDTO->rejectMessage = $isRejected ? $confirmDepositDTO->message : null;
        $this->webhookService->createDepositWebhook($webhookDTO, $confirmDepositDTO->signature);

        if (empty($deposit->transaction_id && ! empty($confirmDepositDTO->invoiceId))) {
            $deposit->transaction_id = $confirmDepositDTO->invoiceId;
        }

        if ($confirmDepositDTO->status == Status::SUCCESS->value) {
            $this->depositService->processPaidOnCallback($deposit, $confirmDepositDTO->amount);
        }

        if ($confirmDepositDTO->status == Status::FAIL->value) {
            $this->depositService->processRejectedOnCallback($deposit);
        }

        event(new DepositConfirmedEvent($deposit));
    }

    /**
     * @throws SignatureMismatchException
     */
    private function hasValidSignature(int $gatewayId, int $merchantId, ConfirmDepositDTO $confirmDepositDTO): void
    {
        $config = $this->configurationService->getConfigurationBy($gatewayId, $merchantId);

        /** @var Request $request */
        $request = app(Request::class);

        if ($confirmDepositDTO->signature !== $this->buildSignatureService->build($config['merchant_apiv5_key'], $request->except('signature'))) {
            throw new SignatureMismatchException(__('Signature mismatch'));
        }
    }
}
