<?php

// Generates the small .docx fixtures used by the import e2e specs.
// Run from the repo root inside the app container:
//   docker compose exec -T app php tests/e2e/fixtures/make_fixtures.php

require __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

foreach (['alpha-runbook' => 'Alpha Runbook', 'beta-checklist' => 'Beta Checklist'] as $slug => $heading) {
    $pw = new PhpWord();
    $pw->addTitleStyle(1, ['size' => 16, 'bold' => true]);
    $section = $pw->addSection();
    $section->addTitle($heading, 1);
    $section->addText("Body paragraph of the {$heading} fixture document.");

    IOFactory::createWriter($pw, 'Word2007')->save(__DIR__ . "/{$slug}.docx");
    echo "{$slug}.docx\n";
}
