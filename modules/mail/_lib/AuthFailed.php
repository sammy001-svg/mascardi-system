<?php

namespace Mascardi\Mail;

use RuntimeException;

/**
 * The mail server refused the address or password.
 *
 * Its own type because it is the one failure a member of staff can fix
 * themselves — by typing their password again — and deserves to be told
 * so plainly rather than shown a server error.
 */
final class AuthFailed extends RuntimeException
{
}
