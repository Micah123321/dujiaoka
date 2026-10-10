<?php

namespace Tests\Concerns;

trait InteractsWithMailRuntime
{
    private function usesSymfonyMail(): bool
    {
        return method_exists(\Illuminate\Mail\Mailer::class, 'getSymfonyTransport');
    }

    private function mailTransport()
    {
        $mailer = app('mailer');
        return $this->usesSymfonyMail() ? $mailer->getSymfonyTransport() : $mailer->getSwiftMailer()->getTransport();
    }

    private function mailAddresses($message, string $field): array
    {
        $addresses = $message->{'get' . $field}();
        return $this->usesSymfonyMail()
            ? array_map(function ($address) { return $address->getAddress(); }, $addresses)
            : array_keys($addresses ?? []);
    }

    private function mailBody($message): string
    {
        return $this->usesSymfonyMail() ? $message->getHtmlBody() : $message->getBody();
    }

    private function useMailTransport($transport): void
    {
        if ($this->usesSymfonyMail()) {
            app('mail.manager')->extend('array', function () use ($transport) { return $transport; });
        } else {
            $this->app->afterResolving('swift.transport', function ($manager) use ($transport) {
                $manager->extend('array', function () use ($transport) { return $transport; });
            });
        }
    }

    private function transportException(): \Throwable
    {
        $message = 'PRIVATE SMTP credential buyer@example.test CARD-SNAPSHOT-A';
        return $this->usesSymfonyMail()
            ? new \Symfony\Component\Mailer\Exception\TransportException($message)
            : new \Swift_TransportException($message);
    }
}
