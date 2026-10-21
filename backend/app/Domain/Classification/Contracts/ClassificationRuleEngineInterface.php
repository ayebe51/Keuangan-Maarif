<?php

namespace App\Domain\Classification\Contracts;

use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Classification\DTOs\ClassificationMatchResult;

interface ClassificationRuleEngineInterface
{
    /**
     * Evaluate a bank transaction through the classification pipeline.
     */
    public function evaluate(BankTransaction $transaction, array $context = []): ClassificationMatchResult;
}
