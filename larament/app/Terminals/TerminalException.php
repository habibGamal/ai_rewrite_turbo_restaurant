<?php

namespace App\Terminals;

use RuntimeException;

/**
 * A drawer action that cannot be done; the message is shown to the cashier.
 */
class TerminalException extends RuntimeException {}
