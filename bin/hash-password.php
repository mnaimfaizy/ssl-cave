#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$password = $argv[1] ?? '';
if ($password === '') {
    fwrite(STDERR, "Usage: php bin/hash-password.php 'your-password'\n");
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
