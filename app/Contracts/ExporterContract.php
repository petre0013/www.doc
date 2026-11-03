<?php

namespace App\Contracts;

use App\Models\Document;

interface ExporterContract
{
    /**
     * Export the document and return the Storage-relative path to the output file.
     */
    public function export(Document $document): string;
}
