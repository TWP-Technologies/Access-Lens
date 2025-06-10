<?php
require_once dirname(__DIR__) . '/includes/pml-headless-sanitization.php';

$input = "https://example.com/path\r\nLocation: https://evil.com";
$expected = "https://example.com/pathLocation:https://evil.com";
$result = pml_headless_sanitize_location($input);

if ($result !== $expected) {
    echo "Sanitization failed: $result\n";
    exit(1);
}

if (strpos($result, "\r") !== false || strpos($result, "\n") !== false) {
    echo "Header injection not removed\n";
    exit(1);
}

echo "Sanitization test passed\n";

