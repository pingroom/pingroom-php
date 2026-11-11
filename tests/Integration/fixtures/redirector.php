<?php

declare(strict_types=1);

// A local server that answers every request with a redirect to another server.
http_response_code((int) (getenv('REDIRECT_STATUS') ?: 307));
header('Location: ' . (getenv('REDIRECT_TO') ?: 'http://127.0.0.1:1/'));
