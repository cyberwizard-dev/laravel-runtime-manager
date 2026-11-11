<?php

namespace Cyberwizard\RuntimeManager\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Mail\Events\MessageSent;
use Throwable;

/**
 * Optional, off-by-default visibility into the application's OUTGOING mail.
 *
 * Records a row when the mail transport accepts a message (Laravel's
 * MessageSent event). It stores recipient *domains*, a recipient count, the
 * subject and the mailer — never full addresses or message bodies.
 *
 * Important and deliberate: a MessageSent event means the transport accepted
 * the handoff. It is NOT proof of inbox delivery — spam filtering, bounces and
 * downstream rejection happen after this point. The dashboard says so.
 */
class MailMonitor
{
    public function __construct(private DatabaseManager $db)
    {
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(MessageSent::class, function (MessageSent $e) {
            $this->record($e);
        });
    }

    private function record(MessageSent $e): void
    {
        try {
            $message = $e->message; // Symfony\Component\Mime\Email
            $recipients = method_exists($message, 'getTo') ? (array) $message->getTo() : [];

            $domains = [];
            foreach ($recipients as $address) {
                $addr = method_exists($address, 'getAddress') ? $address->getAddress() : (string) $address;
                $at = strrchr($addr, '@');
                $domains[] = $at ? substr($at, 1) : 'unknown';
            }
            $domains = array_values(array_unique($domains));

            $this->db->table('runtime_mail_log')->insert([
                'mailer'     => $e->data['mailer'] ?? (string) config('mail.default'),
                'to_domains' => mb_strimwidth(implode(', ', $domains) ?: 'unknown', 0, 250, '…'),
                'recipients' => count($recipients),
                'subject'    => $message && method_exists($message, 'getSubject')
                    ? mb_strimwidth((string) $message->getSubject(), 0, 200, '…')
                    : null,
                'status'     => 'sent',
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Monitoring must never disrupt the application's mail.
        }
    }
}
