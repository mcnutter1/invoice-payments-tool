<?php

namespace App\Services\Auth;

use RuntimeException;

/** Signature/identity rejected. Never include secrets or raw tokens in the message. */
class AuthRejected extends RuntimeException {}
