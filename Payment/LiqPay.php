<?php

namespace WH1\PaygateLiqPay\Payment;

use Exception;
use XF;
use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Http\Request;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;

class LiqPay extends AbstractProvider
{
	public function getTitle(): string
	{
		return 'LiqPay';
	}

	public function getApiEndpoint(): string
	{
		return 'https://www.liqpay.ua/api/3/checkout';
	}

	public function verifyConfig(array &$options, &$errors = []): bool
	{
		if (empty($options['private_key']) || empty($options['public_key']))
		{
			$errors[] = XF::phrase('wh1_liqpay_you_must_provide_all_data');
		}

		return !$errors;
	}

	public function getPaymentParams(PurchaseRequest $purchaseRequest, Purchase $purchase): array
	{
		$profileOptions = $purchase->paymentProfile->options;

		$payment = [
			'version'     => 3,
			'public_key'  => $profileOptions['public_key'],
			'action'      => 'pay',
			'amount'      => $purchase->cost,
			'currency'    => $purchase->currency,
			'description' => $purchase->title,
			'order_id'    => $purchaseRequest->request_key,

			'result_url' => $purchase->returnUrl,
			'server_url' => $this->getCallbackUrl(),
		];

		return $this->getDataAndSignature($payment, $profileOptions['private_key']);
	}

	public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase): XF\Mvc\Reply\Redirect
	{
		$paymentParams = $this->getPaymentParams($purchaseRequest, $purchase);

		return $controller->redirect($this->getApiEndpoint() . '?' . http_build_query($paymentParams));
	}

	public function setupCallback(Request $request): CallbackState
	{
		$state = new CallbackState();

		$state->dataRaw = $request->filter('data', 'str');
		$state->data = json_decode(base64_decode($state->dataRaw), true);
		$state->signature = $request->filter('signature', 'str');

		$state->amount = $state->data['amount'] ?? 0;
		$state->currency = $state->data['currency'] ?? '';
		$state->status = $state->data['status'] ?? '';
		$state->subscriberId = $state->data['customer'] ?? 0;

		$state->ip = $request->getIp();

		$state->httpCode = 200;

		return $state;
	}

	public function validateCallback(CallbackState $state): bool
	{
		$state->requestKey = $state->data['order_id'] ?? '';
		$state->transactionId = $state->data['payment_id'] ?? '';

		if (!empty($state->signature) && $state->data['version'] == 3 && $state->data['action'] == 'pay')
		{
			return true;
		}

		$state->logType = 'info';
		$state->logMessage = 'Auth failed';

		return false;
	}

	public function validateTransaction(CallbackState $state): bool
	{
		if (!$state->requestKey)
		{
			$state->logType = 'info';
			$state->logMessage = 'Metadata is empty!';

			return false;
		}

		if(!empty($state->data['err_code']))
		{
			$state->logType = 'error';
			$state->logMessage = $state->data['err_description'] ?? $state->data['err_code'];

			return false;
		}

		return parent::validateTransaction($state);
	}

	public function validatePurchasableData(CallbackState $state): bool
	{
		$paymentProfile = $state->getPaymentProfile();

		$options = $paymentProfile->options;
		if (!empty($options['public_key']) && !empty($options['private_key']) && !empty($state->signature))
		{
			if ($this->verifySignature($state->dataRaw, $state->signature, $options['private_key']))
			{
				return true;
			}

			$state->logType = 'error';
			$state->logMessage = "Invalid signature";

			return false;
		}

		$state->logType = 'error';
		$state->logMessage = 'Invalid public_key or secret_key.';

		return false;
	}

	public function validateCost(CallbackState $state): bool
	{
		$purchaseRequest = $state->getPurchaseRequest();

		if (($state->currency == $purchaseRequest->cost_currency)
			&& round($state->amount, 2) == round($purchaseRequest->cost_amount, 2))
		{
			return true;
		}

		$state->logType = 'error';
		$state->logMessage = 'Invalid cost amount.';

		return false;
	}

	public function getPaymentResult(CallbackState $state): void
	{
		if (strtolower($state->status) == 'success')
		{
			$state->paymentResult = CallbackState::PAYMENT_RECEIVED;
		}
	}

	public function prepareLogData(CallbackState $state): void
	{
		$state->logDetails = array_merge($state->data, [
			'ip' => $state->ip,
			'signature' => $state->signature
		]);
	}

	public function verifyCurrency(PaymentProfile $paymentProfile, $currencyCode): bool
	{
		return in_array($currencyCode, $this->supportedCurrencies);
	}

	protected $supportedCurrencies = [
		'RUB', 'USD', 'EUR', 'UAH', 'BYN', 'KZT'
	];

	public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING): bool
	{
		$result = self::ERR_NO_RECURRING;

		return false;
	}

	protected function getDataAndSignature(array $payment, string $privateKey): array
	{
		$data = base64_encode(json_encode($payment));
		$signature = base64_encode(hash('sha1', $privateKey . $data . $privateKey, true));

		return [
			'data'      => $data,
			'signature' => $signature
		];
	}

	protected function verifySignature(string $data, string $signature, string $privateKey): bool
	{
		return hash_equals($signature, base64_encode(hash('sha1', $privateKey . $data . $privateKey, true)));
	}
}