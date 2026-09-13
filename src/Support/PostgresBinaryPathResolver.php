<?php

declare(strict_types=1);

namespace Apistolic\LaravelProdSnapshot\Support;

final class PostgresBinaryPathResolver
{
    public function pgDump(): string
    {
        return $this->resolve('pg_dump');
    }

    public function psql(): string
    {
        return $this->resolve('psql');
    }

    public function pgRestore(): string
    {
        return $this->resolve('pg_restore');
    }

    public function resolve(string $binary): string
    {
        $configured = (string) config("db-sync.binaries.{$binary}", $binary);

        if ($configured !== $binary && $configured !== '') {
            return $configured;
        }

        if (PHP_OS_FAMILY !== 'Darwin') {
            return $binary;
        }

        $resolved = $this->resolveOnMac($binary);

        return $resolved ?? $binary;
    }

    private function resolveOnMac(string $binary): ?string
    {
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            return null;
        }

        $candidates = [
            $home.'/Library/Application Support/Herd/config/postgresql/*/bin/'.$binary,
            '/opt/homebrew/opt/postgresql@*/bin/'.$binary,
            '/usr/local/opt/postgresql@*/bin/'.$binary,
        ];

        foreach ($candidates as $pattern) {
            $matches = glob($pattern);
            if (is_array($matches) && $matches !== []) {
                $path = $matches[0];
                if (is_file($path) && is_executable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }
}
