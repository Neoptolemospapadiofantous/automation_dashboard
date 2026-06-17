<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Email-verification notification, queued.
 *
 * Laravel's default VerifyEmail is sent *synchronously* during the
 * registration request (and the /email/verification-notification resend
 * route). When the mail provider is slow or rejects the recipient — e.g.
 * AWS SES in sandbox mode rejecting an unverified address — that send
 * throws and the whole request 500s, leaving a half-registered user.
 *
 * Queueing decouples delivery from the request: registration always
 * succeeds and the email is sent by the queue worker, with normal retry
 * semantics. Branded copy is inherited from the VerifyEmail::toMailUsing()
 * callback registered in AppServiceProvider.
 *
 * Routed to the dedicated "mail" queue so transactional auth email is
 * isolated from broadcasts/indexing and (later) bulk sends. Requires a
 * worker consuming the "mail" queue to actually deliver.
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('mail');
    }
}
