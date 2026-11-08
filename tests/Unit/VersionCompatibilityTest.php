<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Real module constructor/install against PrestaShop's native fresh-install gate.
 *
 * The isolated base contains the exact ModuleCore::install prefix through the
 * version refusal and checkCompliancy() from official PrestaShop 9.0.3
 * classes/module/Module.php (SHA256 118acf210021467fcff02e1e47c632562ba666f35ed3d212f1f16c4f2761ab9b).
 * The preinstall hook/logger are controlled; a sentinel replaces everything
 * after compatibility, before dependencies/module insertion or subclass setup.
 * This does not model an already-installed module's upgrade/enable path.
 */
final class VersionCompatibilityTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function versions(): array
    {
        return [
            '9.0.0 lacks country-selection hook' => ['9.0.0', false],
            '9.0.1 lacks country-selection hook' => ['9.0.1', false],
            '9.0.2 lacks country-selection hook' => ['9.0.2', false],
            '9.0.3 first hook release' => ['9.0.3', true],
            '9.1.5 existing tested profile' => ['9.1.5', true],
        ];
    }

    #[DataProvider('versions')]
    public function testNativeFreshInstallEnforcesTheRequiredCountryHookRelease(string $version, bool $supported): void
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-r', self::program(), $version, dirname(__DIR__, 2) . '/tridentcache.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 65537);
        $stderr = stream_get_contents($pipes[2], 65537);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, (string) $stderr);
        self::assertSame('', $stderr);
        self::assertSame([
            'compatible' => $supported,
            'post_compatibility_boundary_reached' => $supported,
            'returned_false' => !$supported,
            'preinstall_hooks' => ['actionModuleInstallBefore'],
            'preinstall_logs' => 1,
            'validation_errors' => $supported ? 0 : 1,
        ], json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR));
    }

    public static function program(): string
    {
        return <<<'PHP'
declare(strict_types=1);
define('_PS_VERSION_', $argv[1]);
final class PostCompatibilityBoundary extends RuntimeException {}
final class Hook { public static array $calls=[]; public static function exec($name,$args) { self::$calls[]=$name; } }
final class PrestaShopLogger {
    public const LOG_SEVERITY_LEVEL_INFORMATIVE=1;
    public static int $calls=0;
    public static function addLog(...$args) { ++self::$calls; }
}
final class Validate { public static function isModuleName($name) { return $name==='tridentcache'; } }
final class Context {
    public static function getContext() { return new self(); }
    public function getTranslator() { return $this; }
    public function trans($text,...$args) { return $text; }
}
#[AllowDynamicProperties]
class Module {
    public array $_errors=[];
    public function __construct() {}
    public function install()
    {
        Hook::exec('actionModuleInstallBefore', ['object' => $this]);

        PrestaShopLogger::addLog(
            Context::getContext()->getTranslator()->trans(
                'Starting module install: %s v%s',
                [$this->name, $this->version],
                'Admin.Modules.Notification'
            ),
            PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE,
            null,
            'Module',
            null,
            true
        );
        if ($this->_errors) {
            return false;
        }

        // Check module name validation
        if (!Validate::isModuleName($this->name)) {
            $this->_errors[] = Context::getContext()->getTranslator()->trans('Unable to install the module (Module name is not valid).', [], 'Admin.Modules.Notification');

            return false;
        }

        // Check PS version compliancy
        if (!$this->checkCompliancy()) {
            $this->_errors[] = Context::getContext()->getTranslator()->trans('The version of your module is not compliant with your PrestaShop version.', [], 'Admin.Modules.Notification');

            return false;
        }

        throw new PostCompatibilityBoundary();
    }

    public function checkCompliancy()
    {
        if (version_compare(_PS_VERSION_, $this->ps_versions_compliancy['min'], '<') || version_compare(_PS_VERSION_, $this->ps_versions_compliancy['max'], '>')) {
            return false;
        } else {
            return true;
        }
    }


}
require $argv[2];
$module=new TridentCache();
$compatible=$module->checkCompliancy();
$reached=false;$returnedFalse=false;
try {
    $returnedFalse=$module->install()===false;
} catch (PostCompatibilityBoundary $expected) {
    $reached=true;
}
echo json_encode([
    'compatible'=>$compatible,
    'post_compatibility_boundary_reached'=>$reached,
    'returned_false'=>$returnedFalse,
    'preinstall_hooks'=>Hook::$calls,
    'preinstall_logs'=>PrestaShopLogger::$calls,
    'validation_errors'=>count($module->_errors),
],JSON_THROW_ON_ERROR),"\n";
PHP;
    }
}
