<?php

declare(strict_types=1);

use Apistolic\LaravelProdSnapshot\Support\SshTunnel;

function freeLocalPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, strrpos($name, ':') + 1);
}

it('reports success once the local port is listening', function (): void {
    $port = freeLocalPort();
    $tunnel = new SshTunnel(['nc', '-l', (string) $port], $port);

    $tunnel->start(timeoutSeconds: 5.0);
    $tunnel->stop();
})->throwsNoExceptions();

it('throws when the underlying process exits before forwarding is established', function (): void {
    $port = freeLocalPort();
    $tunnel = new SshTunnel(['php', '-r', 'exit(1);'], $port);

    $tunnel->start(timeoutSeconds: 5.0);
})->throws(RuntimeException::class, 'exited before forwarding was established');

it('throws a timeout error when the port never opens', function (): void {
    $port = freeLocalPort();
    $tunnel = new SshTunnel(['sleep', '5'], $port);

    try {
        $tunnel->start(timeoutSeconds: 0.5);
    } finally {
        $tunnel->stop();
    }
})->throws(RuntimeException::class, 'Timed out waiting for SSH tunnel');
