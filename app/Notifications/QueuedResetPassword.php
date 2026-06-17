<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Password-reset notification, queued on the dedicated "mail" queue.
 *
 * Laravel's default ResetPassword is sent synchronously during the
 * forgot-password request — the same failure mode as the verification
 * email: a slow or rejecting mail provider (e.g. AWS SES sandbox) would
 * 500 the request. Queueing decouples delivery from the request.
 *
 * Requires a worker consuming the "mail" queue to actually deliver.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $token
     */
    public function __construct($token)
    {
        parent::__construct($token);
        $this->onQueue('mail');
    }
}
