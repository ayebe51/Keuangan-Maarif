<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class UnauthorizedRuleApproverException extends RuntimeException
{
    public function __construct(int $approverId, string $message = '')
    {
        $msg = $message ?: "User #{$approverId} is not an authorized approver or lacks required 'journal.approve' permission.";
        parent::__construct($msg, 403);
    }
}
