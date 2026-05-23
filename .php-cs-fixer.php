<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/Classes', __DIR__ . '/Tests'])
    ->exclude('vendor');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2x0' => true,
        '@PER-CS2x0:risky' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
