<?php

declare(strict_types=1);

// A local server that records every request it receives, so a test can prove a
// request never reached it.
$log = getenv('COLLECT_FILE');
if (is_string($log)) {
    file_put_contents(
        $log,
        json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            'body' => file_get_contents('php://input'),
            // PHP parses a multipart body into $_FILES and leaves php://input empty.
            'file' => isset($_FILES['file']['tmp_name']) && is_string($_FILES['file']['tmp_name'])
                ? [
                    'name' => $_FILES['file']['name'],
                    'type' => $_FILES['file']['type'],
                    'content' => base64_encode((string) file_get_contents($_FILES['file']['tmp_name'])),
                ]
                : null,
        ], JSON_THROW_ON_ERROR) . "\n",
        FILE_APPEND,
    );
}
header('Content-Type: application/json');
echo '{"success":true,"id":"x","attachment":{"id":"att_1"}}';
