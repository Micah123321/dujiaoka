# Mail runtime compatibility

MailSend keeps the Laravel 6 / Swift path and selects the Symfony path only when the installed Laravel Mailer exposes getSymfonyTransport. No root dependency or default payment-mail flag changes are required. Old serialized jobs still default to non-strict delivery checking.

## Worker configuration

Each job reads the latest system-setting cache and rebuilds its mailer. Swift re-registers its service and clears the mailer facade instance. Symfony forgets named mailers on the actual mail.manager, preserving custom transport creators, and clears that facade key. Tests prewarm both facade and mailer before sending consecutive jobs with changed hosts, users and sender addresses.

SMTP socket waits remain 15 seconds; this is not a total-session deadline. Existing encryption choices remain explicit: ssl uses implicit TLS (smtps), tls uses mandatory STARTTLS, and empty encryption uses plaintext without opportunistic upgrade. The choice is retained on both a custom port and port 465, instead of allowing the new transport to infer it from the port.

A scripted AbstractStream reproduced Symfony 7.4.19 accepting HELO fallback after EHLO failure even with require_tls=true. StartTlsTransport guards greeting completion before MAIL FROM when STARTTLS was requested but not negotiated. The guard shares the configured stream, so socket timeout/options remain intact; username/password/local domain are preserved. Failed TLS negotiation and missing STARTTLS raise TransportException before mail data. This uses app code rather than a vendor patch.

## Transport acceptance

The encrypted payment-mail outbox requires positive transport acceptance. Swift retains TrackedTransport recipient counting and failures checking, restoring the original Swift mailer in finally. Symfony requires a non-null Laravel SentMessage and a nonempty result envelope. Cancellation, null transport results, empty recipient envelopes and exceptions leave delivered_at unset and retain the encrypted snapshot for bounded retry. An accepted transport result clears the snapshot when the outbox acknowledgement commits.

SMTP acceptance is not final inbox delivery. Array/log drivers retain their own acceptance semantics. Loss after acceptance but before acknowledgement, or an exception in a MessageSent listener, can cause retry duplicates; exactly-once inbox delivery is not claimed.

## Verification

- Current Laravel 6 full suite: 2100 tests / 16764 assertions, including mail worker and outbox 26 / 184.
- Current Laravel 12 full Unit/Feature plus SMTP protocol suite: 2106 tests / 16306 assertions, including mail worker/outbox 26 / 164 and SMTP protocol 6 / 16.
- SMTP protocol uses the real EsmtpTransport and MailSend with a local scripted stream, without sockets. Cases cover successful STARTTLS plus final DATA250, EHLO-to-HELO fallback, missing STARTTLS, failed crypto, RCPT550 and final DATA451. It does not verify real certificates, live SMTP providers or inbox arrival.
- Outbox cases include actual transport exceptions with redacted error categories, null/zero acceptance, empty envelopes, cancellation, repaired transport retries, encrypted content preservation, transaction-level zero during sending, duplicate callbacks and old manual-status behavior.
- At the mail milestone, unrestricted discovery had 13 v1 failures (414 / 3577). [SDK-free v1 compatibility](paypal-v1-compatibility.md) subsequently closed them. The current candidate test config discovers every Unit/Feature file and adds the six SMTP cases, with no mixed classes excluded.

Run commands and the remaining framework acceptance scope are in [the isolated runtime README](../tests/Compatibility/Laravel12/README.md). No production DB, SMTP delivery, environment credentials, deployment or system PHP settings were used.
