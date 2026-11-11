<?php

declare(strict_types=1);

namespace PingRoom\Tests\Support;

/** Reads the vendored contract fixtures under `tests/contracts/`. */
final class Fixture
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function load(string $name): array
    {
        $path = __DIR__ . '/../contracts/' . $name . '.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        return $decoded;
    }
}
