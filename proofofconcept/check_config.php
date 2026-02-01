<?php
$c = require __DIR__ . '/config.php';

echo "=== CONFIG POC ===\n";
echo "Claude API: " . (empty($c['claude']['api_key']) ? 'NON CONFIGURÉ' : substr($c['claude']['api_key'], 0, 25) . '...') . "\n";
echo "OpenAI API: " . (empty($c['openai']['api_key']) ? 'NON CONFIGURÉ' : 'OK') . "\n";
echo "Ollama URL: " . $c['ollama']['url'] . "\n";
echo "DB: " . $c['db']['host'] . ":" . $c['db']['port'] . "/" . $c['db']['name'] . "\n";
