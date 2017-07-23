<?php

namespace Larabookir\Gateway\AsanPardakht;

use Illuminate\Support\Facades\Input;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;
use SoapClient;
use SoapFault;

class AsanPardakht extends PortAbstract implements PortInterface {

    use AsanPardakhtHelper;

    /**
     * Address of main SOAP server.
     *
     * @var string
     */
    protected $serverUrl = 'https://services.asanpardakht.net/paygate/merchantservices.asmx?WSDL';

    /**
     * This url is used to validate the IPG's IP address.
     *
     * @var string
     */
    protected $hostInfoUrl = "https://services.asanpardakht.net/utils/hostinfo.asmx?WSDL";

    /**
     * This url is used to check date and time in server.
     *
     * @var string
     */
    protected $serverTimeUrl = "https://services.asanpardakht.net/paygate/servertime.asmx?WSDL";

    /**
     * Parameters to send to gateway.
     *
     * @var array
     */
    protected $parameters = [];

    /**
     * Set the transaction amount.
     *
     * @param integer $amount
     *
     * @return $this
     */
    public function set($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Make the transaction and prepare the user to redirect to the payment page.
     *
     * @return $this
     */
    public function ready()
    {
        $this->testIp();

        $this->sendPayRequest();

        return $this;
    }

    /**
     * @return mixed
     */
    public function redirect()
    {
        return view('gateway::asan-pardakht-redirector')->with(
            $this->redirectParameters()
        );
    }

    /**
     * Verifies the transaction received from gateway.
     *
     * @param object $transaction
     *
     * @return $this
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->verifyPayment();

        return $this;
    }

    /**
     * Verify user payment from bank server.
     *
     * @return boolean
     *
     * @throws AsanPardakhtException
     */
    protected function verifyPayment()
    {
        // get and decrypt the returning parameters...
        $parameters = $this->decrypt(Input::get('ReturningParams'));

        // decode it to array...
        $parameters = explode(",", $parameters);

        // extract parameters...
        $amount = $parameters[0];
        $saleOrderId = $parameters[1];
        $refId = $parameters[2];
        $resCode = $parameters[3];
        $resMessage = $parameters[4];
        $payGateTranID = $parameters[5];
        $rrn = $parameters[6];
        $lastFourDigitOfPan = $parameters[7];

        // check the transaction status...
        if($resCode != '0' and $resCode != '00') {
            $this->transactionFailed();
            throw new AsanPardakhtException(- 998);
        }

        // set options and parameters to make client with...
        $options = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
        $parameters = ['stream_context' => stream_context_create($options)];

        try {
            // try to make the client...
            $client = @new soapclient($this->serverUrl, $parameters);
        } catch(SoapFault $exception) {
            $this->transactionFailed();
            // error in calling the web service...
            throw new AsanPardakhtException(- 999);
        }
        $username = $this->config->get('gateway.asan-pardakht.username');
        $password = $this->config->get('gateway.asan-pardakht.password');

        $encryptedCredentials = $this->encrypt("{$username},{$password}");
        $params = [
            'merchantConfigurationID' => $this->config->get('gateway.asan-pardakht.merchant-configuration-id'),
            'encryptedCredentials'    => $encryptedCredentials,
            'payGateTranID'           => $payGateTranID
        ];

        // verify the payment...
        if( ! ($result = $client->RequestVerification($params))) {
            $this->transactionFailed();
            throw new AsanPardakhtException(- 997);
        }

        // check if verification is successful...
        $result = $result->RequestVerificationResult;
        if($result != '500') {
            $this->transactionFailed();
            throw new AsanPardakhtException($result);
        }

        // settlement...
        if( ! ($result = $client->RequestReconciliation($params))) {
            $this->transactionFailed();
            throw new AsanPardakhtException(- 996);
        }

        // check the transaction settlement...
        $result = $result->RequestReconciliationResult;
        if($result != '600') {
            $this->transactionFailed();
            throw new AsanPardakhtException($result);
        }

        // check the amount...
        if($amount != $this->amount) {
            $this->transactionFailed();
            throw new AsanPardakhtException(- 995);
        }

        if($this->refId != $refId) {
            $this->transactionFailed();
            throw new AsanPardakhtException(- 994);
        }

        // set some variables...
        $this->refId = $refId;
        $this->trackingCode = $resCode;
        $this->cardNumber = $lastFourDigitOfPan;

        // transaction is successful...
        // $this->transactionSetRefId();

        return true;
    }

    /**
     * Set callback url.
     *
     * @param string $url
     *
     * @return $this | string
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
        if( ! $this->callbackUrl) {
            $this->callbackUrl = $this->config->get('gateway.asan-pardakht.callback-url');
        }

        $url = $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);

        return $url;
    }

    /**
     * Url which redirects to bank url.
     *
     * @return string
     */
    public function getGatewayUrl()
    {
        return 'https://asan.shaparak.ir';
    }

    /**
     * Parameters to pass to the gateway within redirect process.
     *
     * @return array
     */
    public function redirectParameters()
    {
        return $this->parameters;
    }

    /**
     * Send pay request to the payment server. It simply makes an encrypted request and sends it to the server through
     * soap client.
     *
     * @throws AsanPardakhtException
     */
    protected function sendPayRequest()
    {

        // gather the parameters to make raw request...
        $serviceCode = ServiceEnum::PURCHASE;
        $username = $this->config->get('gateway.asan-pardakht.username');
        $password = $this->config->get('gateway.asan-pardakht.password');
        // also make new transaction for this payment request...
        $orderId = $this->newTransaction();
        $amount = $this->getAmount();
        $date = date("Ymd His");
        $additionalData = "";

        // make the raw request string...
        $rawRequest = "{$serviceCode},{$username},{$password},{$orderId},{$amount},{$date},{$additionalData},{$this->getCallback()},0";

        // encrypt the request...
        $encryptedRequest = $this->encrypt($rawRequest);

        // set options and parameters to make client with...
        $options = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
        $parameters = ['stream_context' => stream_context_create($options)];

        try {
            // try to make the client...
            $client = @new soapclient($this->serverUrl, $parameters);
        } catch(SoapFault $exception) {
            // error in calling the web service...
            throw new AsanPardakhtException(- 999);
        }

        $parameters = [
            'merchantConfigurationID' => $this->config->get('gateway.asan-pardakht.merchant-configuration-id'),
            'encryptedRequest'        => $encryptedRequest
        ];

        if( ! ($result = $client->RequestOperation($parameters))) {
            // cannot call the request method...
            throw new AsanPardakhtException($result);
        }

        // array of result consists of two parts...
        // a status id in {0} and hashed string in {1}...
        $result = $result->RequestOperationResult;

        // if first part of result is zero then the operation is succeeded...
        if($result{0} == '0') {
            // we need to send this to payment url...
            $this->parameters = [
                'RefId' => $result{1}
            ];
        } else {
            // something went wrong...
            throw new AsanPardakhtException($result);
        }

        $this->transactionSetRefId();
    }

    /**
     * @throws AsanPardakhtException
     */
    protected function testIp()
    {
        try {
            $client = @new soapclient($this->hostInfoUrl);
        } catch(SoapFault $exception) {
            // error in calling the web service...
            throw new AsanPardakhtException(- 999);
        }

        if( ! ($result = $client->GetHostInfo())) {
            throw new AsanPardakhtException(- 993);
        }
    }

}
