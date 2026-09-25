<?php

namespace Mascardi\Mail;

/**
 * A string sent to the IMAP server as a literal: its length first, then
 * its bytes verbatim once the server says to go ahead. Used for anything
 * quoting cannot carry — a message being filed, or a password or search
 * term with characters outside plain ASCII.
 */
final class Literal
{
    public function __construct(public readonly string $value)
    {
    }
}
