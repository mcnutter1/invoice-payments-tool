<?php

namespace App\Exceptions;

use RuntimeException;

/** A business-rule violation whose message is safe to show to the user. */
class DomainException extends RuntimeException {}
