<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__) // Escanea el directorio actual
    ->exclude([
        'vendor',       // Ignorar vendor/
        'assets',       // Ignorar assets/
        'node_modules', // Ignorar node_modules/
        'tests/js',     // Ignorar tests/js/
        'wp',           // Ignorar wp/
        'wp-content',   // Ignorar wp-content/
    ])
    ->name('*.php') // Procesar solo archivos PHP
    ->notPath('wp-content/plugins/some-plugin'); // Ejemplo adicional de exclusión

return (new Config())
    // ->setRules([
    //     '@PSR12' => true, // Define las reglas a usar (puedes personalizar más)
    //     'yoda_style' => ['equal' => true, 'identical' => true, 'less_and_greater' => false],
    //     // Agrega más reglas según tus necesidades
    // ])
    ->setRules([
        '@PSR12' => true,
        'yoda_style' => ['equal' => true, 'identical' => true, 'less_and_greater' => false],
        'array_syntax' => ['syntax' => 'short'], // Uso de sintaxis corta para arrays
        'no_unused_imports' => true, // Elimina imports no utilizados
        'no_trailing_whitespace' => true, // Elimina espacios en blanco al final de líneas
        'single_quote' => true, // Usa comillas simples donde sea posible
        'binary_operator_spaces' => [
            'default' => 'align_single_space_minimal',
        ],
        // Agrega más reglas que coincidan con WordPress
    ])

    ->setFinder($finder)
    ->setUsingCache(true);
