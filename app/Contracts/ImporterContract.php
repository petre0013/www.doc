<?php

namespace App\Contracts;

interface ImporterContract
{
    /**
     * Import a file and return a TipTap document array plus detected title.
     * $uploadedById attributes any assets extracted from the file (imports run
     * on the queue, where auth() is empty); null means "unattributed".
     *
     * @return array{title: string, content: array}
     */
    public function import(string $filePath, ?int $uploadedById = null): array;
}
