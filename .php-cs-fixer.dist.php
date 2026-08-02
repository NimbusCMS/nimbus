<?php

declare(strict_types=1);

/**
 * Conservative, PSR-12-oriented formatting. The point is to end whitespace
 * arguments before external contributions arrive, not to impose a house style
 * that rewrites everyone's code — so this is PSR-12 plus a few safe, widely
 * agreed tidy-ups, and nothing opinionated enough to cause a large diff.
 */
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin'])
    ->append([__DIR__ . '/bootstrap.php', __FILE__])
    // Theme templates are HTML-first PHP; PSR-12 rules fight their markup.
    ->notPath('View/themes');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12'                      => true,
        'array_syntax'               => ['syntax' => 'short'],
        'no_unused_imports'          => true,
        'ordered_imports'            => ['sort_algorithm' => 'alpha'],
        'single_quote'               => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_trailing_whitespace'     => true,
        'no_whitespace_in_blank_line' => true,
        'blank_line_after_opening_tag' => true,
    ])
    ->setFinder($finder);
