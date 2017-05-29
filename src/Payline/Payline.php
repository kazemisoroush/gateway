<?php

namespace Larabookir\Gateway\Payline;

use Illuminate\Support\Facades\Input;
use Larabookir\Gateway\Enum;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;

class Payline extends PortAbstract implements PortInterface {

    /**
     * Address of main CURL server
     *
     * @var string
     */
    protected $serverUrl = 'http://payline.ir/payment/gateway-send';

    /**
     * Address of CURL server for verify payment
     *
     * @var string
     */
    protected $serverVerifyUrl = 'http://payline.ir/payment/gateway-result-second';

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'http://payline.ir/payment/gateway-';

    /**
     * {@inheritdoc}
     */
    public function set($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function ready()
    {
        $this->sendPayRequest();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        return redirect()->to($this->gateUrl . $this->refId);
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->userPayment();
        $this->verifyPayment();

        return $this;
    }

    /**
     * Sets callback url
     *
     * @param $url
     */
    function setCallback($url)
    {
        $this->callbackUrl = $url;

        return $this;
    }

    /**
     * Gets callback url
     * @return string
     */
    function getCallback()
    {
        if( ! $this->callbackUrl)
            $this->callbackUrl = $this->config->get('gateway.payline.callback-url');

        return urlencode($this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]));
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws PaylineSendException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        $fields = [
            'api'      => $this->config->get('gateway.payline.api'),
            'amount'   => $this->amount,
            'redirect' => $this->getCallback(),
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        if(is_numeric($response) && $response > 0) {
            $this->refId = $response;
            $this->transactionSetRefId();

            return true;
        }

        $this->transactionFailed();
        $this->newLog($response, PaylineSendException::$errors[$response]);
        throw new PaylineSendException($response);
    }

    /**
     * Check user payment with GET data
     *
     * @return bool
     *
     * @throws PaylineReceiveException
     */
    protected function userPayment()
    {
        $this->refIf = Input::get('id_get');
        $trackingCode = Input::get('trans_id');

        if(is_numeric($trackingCode) && $trackingCode > 0) {
            $this->trackingCode = $trackingCode;

            return true;
        }

        $this->transactionFailed();
        $this->newLog(- 4, PaylineReceiveException::$errors[- 4]);
        throw new PaylineReceiveException(- 4);
    }

    /**
     * Verify user payment from zarinpal server
     *
     * @return bool
     *
     * @throws PaylineReceiveException
     */
    protected function verifyPayment()
    {
        $fields = [
            'api'      => $this->config->get('gateway.payline.api'),
            'id_get'   => $this->refId(),
            'trans_id' => $this->trackingCode()
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverVerifyUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        if($response == 1) {
            $this->transactionSucceed();
            $this->newLog($response, Enum::TRANSACTION_SUCCEED_TEXT);

            return true;
        }

        $this->transactionFailed();
        $this->newLog($response, PaylineReceiveException::$errors[$response]);
        throw new PaylineReceiveException($response);
    }

    /**
     * Url which redirects to bank url.
     *
     * @return string
     */
    public function getGatewayUrl()
    {
        return $this->gateUrl . $this->refId;
    }

    /**
     * Parameters to pass to the gateway.
     *
     * @return array
     */
    public function redirectParameters()
    {
        return [];
    }
}
