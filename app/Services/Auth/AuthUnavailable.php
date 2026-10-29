<?php

namespace App\Services\Auth;

use RuntimeException;

/** The central auth service could not be reached or answered ambiguously. Callers must fail closed. */
class AuthUnavailable extends RuntimeException {}
