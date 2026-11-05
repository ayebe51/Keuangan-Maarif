<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

class UnsupportedPostingCategoryException extends RuntimeException
{
    public function __construct(string $category, string $message = '')
    {
        $msg = $message ?: "Unsupported posting category '{$category}'. Transaction cannot be resolved to a double-entry matrix.";
        parent::__construct($msg, 422);
    }
}
