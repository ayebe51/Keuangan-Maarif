<?php

namespace App\Domain\Fund\Exceptions;

use Exception;

class NegativeFundBalanceException extends Exception
{
    public function __construct(
        public readonly string $fundCode,
        public readonly string $attemptedAmount,
        public readonly string $availableAmount,
        string $message = ""
    ) {
        parent::__construct(
            $message ?: "Cannot disburse/commit {$attemptedAmount} from Fund '{$fundCode}'. Available balance is only {$availableAmount}."
        );
    }
}
