<?php

declare(strict_types=1);

namespace Apistolic\LaravelProdSnapshot\Support;

use Illuminate\Support\Sleep;
use RuntimeException;
use Symfony\Component\Process\Process;

final class SshTunnel
{
    private ?Process $process = null;

    /** @param string[] $command */
    public function __construct(
        private readonly array $command,
        private readonly int $localPort,
    ) {}

    public static function forward(
        string $sshHost,
        string $sshUser,
        int $sshPort,
        string $remoteHost,
        int $remotePort,
        int $localPort,
    ): self {
        return new self([
            'ssh',
            '-N',
            '-L', "{$localPort}:{$remoteHost}:{$remotePort}",
            '-p', (string) $sshPort,
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            "{$sshUser}@{$sshHost}",
        ], $localPort);
    }

    public function start(float $timeoutSeconds = 15.0): void
    {
        $this->process = new Process($this->command);
        $this->process->start();

        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (! $this->process->isRunning()) {
                $error = trim($this->process->getErrorOutput()) ?: trim($this->process->getOutput());
                throw new RuntimeException("SSH tunnel process exited before forwarding was established: {$error}");
            }

            $socket = @fsockopen('127.0.0.1', $this->localPort, $errno, $errstr, 0.5);
            if ($socket !== false) {
                fclose($socket);

                return;
            }

            Sleep::usleep(200_000);
        }

        $this->stop();

        throw new RuntimeException("Timed out waiting for SSH tunnel to open on local port {$this->localPort}.");
    }

    public function stop(): void
    {
        $this->process?->stop(3);
        $this->process = null;
    }
}
