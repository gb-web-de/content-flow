<?php
/**
 *  $ .Build/bin/php-cs-fixer fix --config Build/php-cs-fixer/php-cs-rules.php
 */
if (PHP_SAPI !== 'cli') {
    die('This script supports command line usage only. Please check your command.');
}

$finder = PhpCsFixer\Finder::create()
    ->ignoreVCSIgnored(true)
    ->exclude([
        '.Build/',
        'Build/',
        'var/',
    ])
    ->in(realpath(__DIR__ . '/../../'));

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'no_superfluous_elseif' => true,
        'no_useless_else' => true,
        'nullable_type_declaration' => ['syntax' => 'question_mark'],
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'new_with_parentheses' => true,
    ])
    ->setFinder($finder);
