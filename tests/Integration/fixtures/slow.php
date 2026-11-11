<?php

declare(strict_types=1);

// A local server that takes longer to answer than a test's timeout.
sleep(3);
header('Content-Type: application/json');
echo '{}';
