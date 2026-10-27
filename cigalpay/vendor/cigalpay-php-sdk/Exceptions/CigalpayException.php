<?php

namespace Cigalpay\Exceptions;

/** Error from the CigalPay API: getCode() is the HTTP status (0 = network), getBody() the parsed reply. */
class CigalpayException extends \Exception
{
    /** @var mixed */
    private $body;

    public function __construct($message = '', $status = 0, $body = null)
    {
        parent::__construct($message, (int) $status);
        $this->body = $body;
    }

    public function getStatus()
    {
        return $this->getCode();
    }

    public function getBody()
    {
        return $this->body;
    }
}
