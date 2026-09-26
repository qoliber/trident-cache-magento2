<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model\Fake;

/**
 * A real HTTP server on 127.0.0.1 (PHP's built-in server, one process),
 * for tests that must go through libcurl rather than a fake transport:
 *
 * - `/admin/events/*`: an SSE stream that sends three events and a cut-off
 *   fourth, then holds the connection open like Trident's — with a comment
 *   line every 0.2 s, so it notices when the client hangs up and frees the
 *   single-process server for the next request;
 * - `/admin/events/denied`: 401;
 * - anything else: `{"ok":true}`.
 */
class LocalServer
{
    /** @var resource|null */
    private $process = null;

    private string $dir;

    public readonly int $port;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/trident-local-server-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/admin/events/denied') {
    http_response_code(401);
    echo '{"error":"unauthorized"}';
    return true;
}
if (str_starts_with($path, '/admin/events/')) {
    header('Content-Type: text/event-stream');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    foreach (['/a', '/b', '/c'] as $url) {
        echo "event: request\ndata: " . json_encode(['url' => $url]) . "\n\n";
        flush();
        usleep(100000);
    }
    echo 'data: {"url":"/cut';
    flush();
    for ($i = 0; $i < 100 && !connection_aborted(); $i++) {
        usleep(200000);
        echo ":\n";
        flush();
    }
    return true;
}
header('Content-Type: application/json');
echo '{"ok":true}';
return true;
PHP);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        // A test must start a real server; not code a shop runs.
        // phpcs:ignore Magento2.Security.InsecureFunction
        $this->process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $this->dir . '/router.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH')]
        );
        for ($i = 0; $i < 50; $i++) {
            // Refused until the server listens: expected, not a test warning.
            set_error_handler(static fn (): bool => true);
            $probe = stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 0.1);
            restore_error_handler();
            if (is_resource($probe)) {
                fclose($probe);
                return;
            }
            usleep(100000);
        }
        throw new \RuntimeException('the local test server did not start');
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function __destruct()
    {
        if (is_resource($this->process)) {
            // phpcs:ignore Magento2.Security.InsecureFunction
            proc_terminate($this->process, 9);
            // phpcs:ignore Magento2.Security.InsecureFunction
            proc_close($this->process);
        }
        if (is_file($this->dir . '/router.php')) {
            unlink($this->dir . '/router.php');
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }
}
