<?php

namespace App\Domain\Bank\Exceptions;

use Exception;

class DuplicateImportException extends Exception
{
    public function __construct(
        public readonly string $filename,
        public readonly string $fileHash,
        public readonly int $bankAccountId,
        string $message = ""
    ) {
        parent::__construct(
            $message ?: "File '{$filename}' (Hash: {$fileHash}) has already been imported for Bank Account ID {$bankAccountId}."
        );
    }
}
