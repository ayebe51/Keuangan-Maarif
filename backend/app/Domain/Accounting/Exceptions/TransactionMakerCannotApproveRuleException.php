<?php

namespace App\Domain\Accounting\Exceptions;

class TransactionMakerCannotApproveRuleException extends SegregationOfDutiesException
{
    public function __construct(int $userId, string $identifier, string $message = '')
    {
        $msg = $message ?: "Segregation of Duties Violation: Transaction Maker (User #{$userId}) cannot approve rule for transaction '{$identifier}'. Operator cannot approve rules to auto-post their own transactions.";
        parent::__construct($userId, $identifier, $msg);
    }
}
