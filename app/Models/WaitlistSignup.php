<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A pre-launch waitlist signup from the coming-soon page. Deduped by email.
 */
class WaitlistSignup extends Model
{
    protected $fillable = ['email', 'source', 'ip'];
}
