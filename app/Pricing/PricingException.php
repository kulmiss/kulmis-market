<?php

namespace App\Pricing;

use RuntimeException;

/** A customer-facing pricing problem (bad coupon, item not purchasable...). */
class PricingException extends RuntimeException {}
