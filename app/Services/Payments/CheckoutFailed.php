<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * The provider could not start a checkout (network, keys, API error). The
 * order stays awaiting_payment, so the customer can retry or pick another method.
 */
class CheckoutFailed extends RuntimeException {}
