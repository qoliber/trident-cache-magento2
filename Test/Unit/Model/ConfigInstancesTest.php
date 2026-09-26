<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\Config;
use Qoliber\Trident\Delivery\Instance;

/**
 * X03: the instances come from env.php, layered over the admin setting —
 * env.php wins, and what an instance leaves out comes from the admin.
 */
class ConfigInstancesTest extends TestCase
{
    /**
     * @param mixed $instances What env.php holds under trident/instances.
     */
    private function config(mixed $instances, string $adminUrl = 'http://admin-edge:9301'): Config
    {
        $values = [
            Config::XML_TRIDENT_API_URL => $adminUrl,
            Config::XML_TRIDENT_API_TOKEN => '0:3:admin-encrypted',
            Config::XML_TRIDENT_INSTANCES => $instances,
        ];
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn (string $path): mixed => $values[$path] ?? null);
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(fn (string $v): string => 'decrypted(' . $v . ')');

        return new Config($scope, $encryptor);
    }

    /**
     * @param list<Instance> $instances
     * @return array<int, array{string, string, string}>
     */
    private static function flat(array $instances): array
    {
        return array_map(fn (Instance $i): array => [$i->name, $i->apiUrl, $i->apiToken], $instances);
    }

    public function testWithoutInstancesTheAdminSettingIsTheOneInstance(): void
    {
        $config = $this->config(null);

        $this->assertSame(
            [['default', 'http://admin-edge:9301', 'decrypted(0:3:admin-encrypted)']],
            self::flat($config->getInstances())
        );
        $this->assertSame([], $config->getInstanceErrors());
    }

    public function testEnvInstancesReplaceTheAdminUrlAndInheritItsToken(): void
    {
        $config = $this->config([
            'edge-1' => ['api_url' => 'http://10.0.0.11:9301'],
            'edge-2' => ['api_url' => 'http://10.0.0.12:9301', 'api_token' => 'plain-edge-2'],
            'edge-3' => ['api_url' => 'http://10.0.0.13:9301', 'api_token' => '0:3:looks-encrypted'],
        ]);

        $this->assertSame([
            ['edge-1', 'http://10.0.0.11:9301', 'decrypted(0:3:admin-encrypted)'],
            ['edge-2', 'http://10.0.0.12:9301', 'plain-edge-2'],
            // Plain text, even when it looks like Magento's cipher format:
            // guessing would turn a real token into garbage and 401s forever.
            ['edge-3', 'http://10.0.0.13:9301', '0:3:looks-encrypted'],
        ], self::flat($config->getInstances()));
    }

    public function testAnEntryWithoutAnApiUrlIsSkippedAndReported(): void
    {
        $config = $this->config([
            'edge-1' => ['api_url' => 'http://10.0.0.11:9301'],
            'edge-2' => ['api_token' => 'x'],
            'edge-3' => 'http://10.0.0.13:9301',
        ]);

        $this->assertSame(['edge-1'], array_map(fn (Instance $i): string => $i->name, $config->getInstances()));
        $this->assertSame(
            ['edge-2: no api_url', 'edge-3: expected an array with api_url'],
            $config->getInstanceErrors()
        );
    }

    /**
     * Nothing usable must not mean "purge nowhere".
     */
    public function testWhenNoEntryIsUsableTheAdminSettingIsUsed(): void
    {
        $config = $this->config(['edge-1' => ['api_url' => '  ']]);

        $this->assertSame(['default'], array_map(fn (Instance $i): string => $i->name, $config->getInstances()));
        $this->assertSame(['edge-1: no api_url'], $config->getInstanceErrors());
    }

    public function testAListWithoutNamesIsNumbered(): void
    {
        $config = $this->config([
            ['api_url' => 'http://10.0.0.11:9301'],
            ['api_url' => 'http://10.0.0.12:9301'],
        ]);

        $this->assertSame(
            ['instance-1', 'instance-2'],
            array_map(fn (Instance $i): string => $i->name, $config->getInstances())
        );
    }

    public function testANameTooLongToStoreIsRefused(): void
    {
        $long = str_repeat('e', 65);
        $config = $this->config([$long => ['api_url' => 'http://10.0.0.11:9301']]);

        $this->assertSame(['default'], array_map(fn (Instance $i): string => $i->name, $config->getInstances()));
        $this->assertStringContainsString('1-64 characters', $config->getInstanceErrors()[0]);
    }

    /**
     * The library's rules, shared by every Trident platform integration: a
     * name is stored with each pending purge and compared case-sensitively,
     * so it is plain ASCII; an admin API is an http(s) URL.
     */
    public function testANameWithCharactersTheOutboxCannotHoldIsRefused(): void
    {
        $config = $this->config([
            'edge 1' => ['api_url' => 'http://10.0.0.11:9301'],
            'edge-2' => ['api_url' => 'http://10.0.0.12:9301'],
        ]);

        $this->assertSame(['edge-2'], array_map(fn (Instance $i): string => $i->name, $config->getInstances()));
        $this->assertStringStartsWith('edge 1: name must be', $config->getInstanceErrors()[0]);
    }

    public function testAnApiUrlThatIsNotHttpIsRefused(): void
    {
        $config = $this->config([
            'edge-1' => ['api_url' => 'file:///etc/passwd'],
            'edge-2' => ['api_url' => 'http://10.0.0.12:9301/'],
        ]);

        $this->assertSame(
            [['edge-2', 'http://10.0.0.12:9301', 'decrypted(0:3:admin-encrypted)']],
            self::flat($config->getInstances()),
            'the trailing slash is trimmed: paths are appended to it'
        );
        $this->assertStringContainsString('is not an http(s) URL', $config->getInstanceErrors()[0]);
    }

    /**
     * The module used to hand the URL to libcurl, which assumes http when a
     * URL has no scheme — so `trident:9301` worked and must keep working.
     */
    public function testAUrlWithoutASchemeIsHttpAsBefore(): void
    {
        $config = $this->config(['edge-1' => ['api_url' => '10.0.0.11:9301']], 'trident:9301');

        $this->assertSame('http://10.0.0.11:9301', $config->getInstances()[0]->apiUrl);
        $this->assertSame('http://trident:9301', $this->config(null, 'trident:9301')->getInstances()[0]->apiUrl);
    }

    public function testAnAdminUrlWithAnotherSchemeLeavesNoInstanceAndSaysWhy(): void
    {
        $config = $this->config(null, 'gopher://trident:9301');

        $this->assertSame([], $config->getInstances());
        $this->assertStringContainsString('is not an http(s) URL', $config->getInstanceErrors()[0]);
    }
}
