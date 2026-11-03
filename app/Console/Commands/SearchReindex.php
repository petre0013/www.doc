<?php

namespace App\Console\Commands;

use App\Support\SearchVector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('search:reindex')]
#[Description('Rebuild the search_vector tsvector for all documents')]
class SearchReindex extends Command
{
    public function handle(): int
    {
        $lang = config('database.search_language', 'english');

        $count = DB::affectingStatement(
            'UPDATE documents SET search_vector = ' . SearchVector::expression(),
            [$lang, $lang]
        );

        $this->info("Reindexed {$count} document(s).");

        return self::SUCCESS;
    }
}
