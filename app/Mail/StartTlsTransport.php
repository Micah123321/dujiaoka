<?php

namespace App\Mail;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class StartTlsTransport extends EsmtpTransport
{
    private $startTlsRequested = false;

    public function executeCommand(string $command, array $codes): string
    {
        $greeting = strpos($command, 'HELO ') === 0 && $codes === [250];
        if ($greeting) $this->startTlsRequested = false;
        $response = parent::executeCommand($command, $codes);
        if ($command === "STARTTLS\r\n") $this->startTlsRequested = true;
        // The parent can accept HELO fallback before checking require_tls.
        // Successful parent return after STARTTLS also proves startTLS() did not fail.
        if ($greeting && $this->isTlsRequired() && !$this->getStream()->isTLS() && !$this->startTlsRequested) {
            throw new TransportException('TLS required but SMTP greeting completed without STARTTLS.');
        }
        return $response;
    }
}
