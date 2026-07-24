<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use PHPUnit\Framework\TestCase;

final class SymfonyServeCommandTest extends TestCase
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $scriptPath = '';

    private string $stdoutPath = '';

    private string $stderrPath = '';

    private int $port = 0;

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process, SIGTERM);
                usleep(500_000);
            }
            proc_close($this->process);
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];

        foreach ([$this->scriptPath, $this->stdoutPath, $this->stderrPath] as $path) {
            if ($path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_symfony_serve_command_starts_and_stops_with_sigterm(): void
    {
        $this->port = $this->findFreePort();
        $this->scriptPath = tempnam(sys_get_temp_dir(), 's3-symfony-command-') ?: '';
        $this->stdoutPath = tempnam(sys_get_temp_dir(), 's3-symfony-command-stdout-') ?: '';
        $this->stderrPath = tempnam(sys_get_temp_dir(), 's3-symfony-command-stderr-') ?: '';

        self::assertNotSame('', $this->scriptPath);
        file_put_contents($this->scriptPath, $this->scriptContents());

        $process = proc_open(
            sprintf(
                'exec php %s --host=127.0.0.1 --port=%d --admin-token=test-admin-token',
                escapeshellarg($this->scriptPath),
                $this->port,
            ),
            [
                0 => ['pipe', 'r'],
                1 => ['file', $this->stdoutPath, 'a'],
                2 => ['file', $this->stderrPath, 'a'],
            ],
            $this->pipes,
        );

        if (!is_resource($process)) {
            self::fail('Failed to start the Symfony serve command.');
        }
        $this->process = $process;
        fclose($this->pipes[0]);
        unset($this->pipes[0], $this->pipes[1], $this->pipes[2]);

        $this->waitForServer();

        proc_terminate($process, SIGTERM);

        $stopped = false;
        for ($i = 0; $i < 30; $i++) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $stopped = true;
                break;
            }
            usleep(100_000);
        }

        self::assertTrue($stopped, $this->logs());
    }

    private function findFreePort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($sock === false) {
            self::fail('Failed to create a socket for the Symfony test server.');
        }
        if (!socket_bind($sock, '127.0.0.1', 0)
            || !socket_getsockname($sock, $addr, $port)
            || !is_int($port)) {
            socket_close($sock);
            self::fail('Failed to reserve a port for the Symfony test server.');
        }
        socket_close($sock);

        return $port;
    }

    private function waitForServer(): void
    {
        if (!is_resource($this->process)) {
            self::fail('Symfony serve command process is not running.');
        }
        $process = $this->process;
        for ($i = 0; $i < 100; $i++) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                self::fail('Symfony serve command exited before accepting connections.' . "\n" . $this->logs());
            }

            $sock = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($sock !== false) {
                fclose($sock);

                return;
            }

            usleep(50_000);
        }

        self::fail('Symfony serve command did not start listening.' . "\n" . $this->logs());
    }

    private function logs(): string
    {
        return sprintf(
            "stdout:\n%s\nstderr:\n%s",
            $this->stdoutPath !== '' && file_exists($this->stdoutPath) ? file_get_contents($this->stdoutPath) : '',
            $this->stderrPath !== '' && file_exists($this->stderrPath) ? file_get_contents($this->stderrPath) : '',
        );
    }

    private function scriptContents(): string
    {
        $autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
        self::assertIsString($autoload);

        $script = <<<'PHP'
<?php

declare(strict_types=1);

require __AUTOLOAD__;

use OpsFour\S3Server\Symfony\Command\S3ServerServeCommand;
use OpsFour\S3Server\Symfony\DependencyInjection\S3ServerExtension;
use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\ContainerBuilder;

$container = new ContainerBuilder;
(new S3ServerExtension)->load([[
    'server' => [
        'host' => '127.0.0.1',
        'port' => 65535,
    ],
    'storage' => [
        'driver' => 'memory',
        'path' => sys_get_temp_dir() . '/opsfour-s3-symfony-command',
    ],
    'metadata' => [
        'path' => ':memory:',
    ],
    'credentials' => [
        'driver' => 'memory',
        'access_key' => 'symfonyAccessKey',
        'secret_key' => 'symfonySecretKey',
        'owner_id' => 'symfony-owner',
        'display_name' => 'Symfony Owner',
    ],
]], $container);
$container->compile();

$app = new Application('OpsFour S3 Symfony Smoke');
$registerCommand = method_exists($app, 'addCommand') ? 'addCommand' : 'add';
$app->{$registerCommand}($container->get(S3ServerServeCommand::class));
$app->setDefaultCommand('opsfour:s3:serve', true);
$app->run();
PHP
        ;

        return str_replace(
            ['__AUTOLOAD__'],
            [var_export($autoload, true)],
            $script,
        );
    }
}
