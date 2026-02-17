<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mailbox for lot chat (IMAP).
 * Created when lot is placed. Dovecot reads from this table for auth.
 */
class LotMailbox extends Model
{
    protected $table = 'lot_mailboxes';

    protected $fillable = [
        'local_part',
        'domain',
        'password_hash',
        'currency',
    ];

    protected $hidden = [
        'password_hash',
    ];

    public function getEmailAttribute(): string
    {
        return $this->local_part . '@' . $this->domain;
    }
}
