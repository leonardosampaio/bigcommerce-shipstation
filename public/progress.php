<?php
/**
 * Reads progress json file
 */

$tempFile = __DIR__.'/../temp/progress.json';

header('Content-type: application/json');
echo file_exists($tempFile) ? file_get_contents($tempFile) : json_encode([]);