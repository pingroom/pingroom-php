<?php

declare(strict_types=1);

namespace PingRoom\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Starts PHP's built-in web server as a real HTTP peer, so the SDK's default
 * clients (Guzzle, Symfony) are exercised over a socket and not through a mock.
 */
abstract class LocalServerTestCase extends TestCase
{
    /** @var list<resource> */
    private array $processes = [];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->processes = [];
        $this->files = [];
    }

    /**
     * Start a server running `fixtures/<router>.php`; returns its base URL.
     *
     * @param array<string, string> $environment
     */
    protected function startServer(string $router, array $environment = []): string
    {
        if (!function_exists('proc_open') || !is_executable(PHP_BINARY)) {
            self::markTestSkipped('proc_open or the PHP binary is not available.');
        }
        $port = $this->freePort();
        $command = [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . "/fixtures/{$router}.php"];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, __DIR__, [...getenv(), ...$environment]);
        if (!is_resource($process)) {
            self::markTestSkipped('Could not start PHP\'s built-in web server.');
        }
        $this->processes[] = $process;

        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return "http://127.0.0.1:{$port}";
            }
            usleep(50_000);
        }
        self::markTestSkipped('The local web server did not start.');
    }

    /** A path for a log file the test removes afterwards. */
    protected function tempFile(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'pingroom-it');
        $this->files[] = $file;

        return $file;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function collected(string $file): array
    {
        $rows = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    private function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) {
            self::markTestSkipped('Cannot open a local socket.');
        }
        $name = stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }
}
