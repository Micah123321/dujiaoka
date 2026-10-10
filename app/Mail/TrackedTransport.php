<?php

namespace App\Mail;

class TrackedTransport implements \Swift_Transport
{
    private $transport;
    public $acceptedRecipients = 0;

    public function __construct(\Swift_Transport $transport)
    {
        $this->transport = $transport;
    }

    public function isStarted()
    {
        return $this->transport->isStarted();
    }

    public function start()
    {
        return $this->transport->start();
    }

    public function stop()
    {
        return $this->transport->stop();
    }

    public function ping()
    {
        return $this->transport->ping();
    }

    public function send(\Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $count = $this->transport->send($message, $failedRecipients);
        $this->acceptedRecipients += $count;
        return $count;
    }

    public function registerPlugin(\Swift_Events_EventListener $plugin)
    {
        return $this->transport->registerPlugin($plugin);
    }
}
