<?php

namespace Larabookir\Gateway\Saman;

use Illuminate\Support\Facades\Input;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;
use SoapClient;

class Saman extends PortAbstract implements PortInterface
{
    /**
     * Address of main SOAP server
     *
     * @var string
     */
    protected $serverUrl = 'https://sep.shaparak.ir/payments/referencepayment.asmx?wsdl';

    /**
     * @param int $amount
     *
     * @return $this
     */
    public function set($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * @return $this
     */
    public function ready()
    {
        $this->newTransaction();

        return $this;
    }

    /**
     * @return mixed
     */
    public function redirect()
    {
        return view('gateway::saman-redirector')->with(
            $this->redirectParameters()
        );
    }

    /**
     * @param object $transaction
     *
     * @return $this
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->userPayment();
        $this->verifyPayment();

        return $this;
    }

    /**
     * Set callback url.
     *
     * @param $url
     *
     * @return $this|string
     */
    function setCallback($url)
    {
        $this->callbackUrl = $url;
        return $this;
    }

    /**
     * Gets callback url.
     *
     * @return string
     */
    function getCallback()
    {
        if (!$this->callbackUrl)
            $this->callbackUrl = $this->config->get('gateway.saman.callback-url');

        $url = $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);

        return $url;
    }

    /**
     * Check user payment
     *
     * @return bool
     *
     * @throws SamanException
     */
    protected function userPayment()
    {
        $this->refId = Input::get('RefNum');
        $this->trackingCode = Input::get('ResNum');
        $payRequestRes = Input::get('State');
        $payRequestResCode = Input::get('StateCode');

        if ($payRequestRes == 'OK') {
            return true;
        }

        $this->transactionFailed();
        $this->newLog($payRequestResCode, @SamanException::$errors[$payRequestRes]);
        throw new SamanException($payRequestRes);
    }


    /**
     * Verify user payment from bank server.
     *
     * @return bool
     *
     * @throws \Larabookir\Gateway\Saman\SamanException
     * @throws \SoapFault
     */
    protected function verifyPayment()
    {
        $fields = array(
            "merchantID" => $this->config->get('gateway.saman.merchant'),
            "RefNum" => $this->refId,
            "password" => $this->config->get('gateway.saman.password'),
        );

        try {
            $soap = new SoapClient($this->serverUrl);
            $response = $soap->VerifyTransaction($fields["RefNum"], $fields["merchantID"]);
        } catch (\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

        $response = intval($response);

        if ($response != $this->amount) {

            //Reverse Transaction
            if($response>0){
                try {
                    $soap = new SoapClient($this->serverUrl);
                    $response = $soap->ReverseTransaction($fields["RefNum"], $fields["merchantID"], $fields["password"], $response);

                } catch (\SoapFault $e) {
                    $this->transactionFailed();
                    $this->newLog('SoapFault', $e->getMessage());
                    throw $e;
                }
            }

            //
            $this->transactionFailed();
            $this->newLog($response, SamanException::$errors[$response]);
            throw new SamanException($response);
        }


        $this->transactionSucceed();

        return true;
    }

    /**
     * Url which redirects to bank url.
     *
     * @return string
     */
    public function getGatewayUrl()
    {
        return 'https://sep.shaparak.ir/Payment.aspx';
    }

    /**
     * Parameters to pass to the gateway.
     *
     * @return array
     */
    public function redirectParameters()
    {
        return [
            'amount'      => $this->amount,
            'merchant'    => $this->config->get('gateway.saman.merchant'),
            'resNum'      => $this->transactionId(),
            'callBackUrl' => $this->getCallback()
        ];
    }
}
