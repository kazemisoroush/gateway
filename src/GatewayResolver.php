<?php

namespace Larabookir\Gateway;

use Illuminate\Support\Facades\DB;
use Larabookir\Gateway\Asanpardakht\Asanpardakht;
use Larabookir\Gateway\Exceptions\InvalidRequestException;
use Larabookir\Gateway\Exceptions\NotFoundTransactionException;
use Larabookir\Gateway\Exceptions\PortNotFoundException;
use Larabookir\Gateway\Exceptions\RetryException;
use Larabookir\Gateway\JahanPay\JahanPay;
use Larabookir\Gateway\Mellat\Mellat;
use Larabookir\Gateway\Parsian\Parsian;
use Larabookir\Gateway\Payline\Payline;
use Larabookir\Gateway\Sadad\Sadad;
use Larabookir\Gateway\Saman\Saman;
use Larabookir\Gateway\Zarinpal\Zarinpal;

class GatewayResolver {

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var array
     */
    public $config;

    /**
     * Keep current port driver
     *
     * @var string
     */
    protected $port;

    /**
     * Gateway constructor.
     *
     * @param null | array  $config
     * @param null | string $port
     */
    public function __construct($config = null, $port = null)
    {
        $this->config = app('config');
        $this->request = app('request');

        if($this->config->has('gateway.timezone')) {
            date_default_timezone_set($this->config->get('gateway.timezone'));
        }

        if( ! is_null($port)) {
            $this->make($port);
        }
    }

    /**
     * Get supported ports
     *
     * @return array
     */
    public function getSupportedPorts()
    {
        return [
            Enum::MELLAT,
            Enum::SADAD,
            Enum::ZARINPAL,
            Enum::PAYLINE,
            Enum::JAHANPAY,
            Enum::PARSIAN,
            Enum::PASARGAD,
            Enum::SAMAN,
            Enum::ASANPARDAKHT
        ];
    }

    /**
     * Call methods of current driver
     *
     * @param $name
     * @param $arguments
     *
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        // calling by this way ( Gateway::mellat()->.. , Gateway::parsian()->.. )
        if(in_array(strtoupper($name), $this->getSupportedPorts())) {
            return $this->make($name);
        }

        return call_user_func_array([$this->port, $name], $arguments);
    }

    /**
     * Gets query builder from you transactions table
     * @return mixed
     */
    function getTable()
    {
        return DB::table($this->config->get('gateway.table'));
    }

    /**
     * Get transaction id returning from gateway.
     *
     * @return integer
     * @throws InvalidRequestException
     */
    public function getTransactionId()
    {
        if( ! $this->request->has('transaction_id') && ! $this->request->has('iN')) {
            throw new InvalidRequestException;
        }

        if($this->request->has('transaction_id')) {
            $id = intval($this->request->get('transaction_id'));
        } else {
            $id = intval($this->request->get('iN'));
        }

        return $id;
    }

    /**
     * Callback
     *
     * @return PortAbstract
     *
     * @throws InvalidRequestException
     * @throws NotFoundTransactionException
     * @throws PortNotFoundException
     * @throws RetryException
     */
    public function verify()
    {
        $id = $this->getTransactionId();

        $transaction = $this->getTable()->whereId($id)->first();

        if( ! $transaction) {
            throw new NotFoundTransactionException;
        }

        if(in_array($transaction->status, [Enum::TRANSACTION_SUCCEED, Enum::TRANSACTION_FAILED])) {
            throw new RetryException;
        }

        $this->make($transaction->port);

        return $this->port->verify($transaction);
    }

    /**
     * Create new object from port class
     *
     * @param int $port
     *
     * @return $this
     * @throws PortNotFoundException
     */
    function make($port)
    {
        if($port InstanceOf Mellat) {
            $name = Enum::MELLAT;
        } else if($port InstanceOf Parsian) {
            $name = Enum::PARSIAN;
        } else if($port InstanceOf Saman) {
            $name = Enum::SAMAN;
        } else if($port InstanceOf Asanpardakht) {
            $name = Enum::ASANPARDAKHT;
        } else if($port InstanceOf Payline) {
            $name = Enum::PAYLINE;
        } else if($port InstanceOf Zarinpal) {
            $name = Enum::ZARINPAL;
        } else if($port InstanceOf JahanPay) {
            $name = Enum::JAHANPAY;
        } else if($port InstanceOf Sadad) {
            $name = Enum::SADAD;
        } else if(in_array(strtoupper($port), $this->getSupportedPorts())) {
            $port = ucfirst(strtolower($port));
            $name = strtoupper($port);
            $class = __NAMESPACE__ . '\\' . $port . '\\' . $port;
            $port = new $class;
        } else {
            throw new PortNotFoundException;
        }

        $this->port = $port;
        $this->port->setConfig($this->config); // injects config
        $this->port->setPortName($name); // injects config
        $this->port->boot();

        return $this;
    }
}
