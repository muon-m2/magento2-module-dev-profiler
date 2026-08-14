<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\Sql;

use Muon\DevProfiler\Model\Sql\StatementOrigin;
use PHPUnit\Framework\TestCase;

/**
 * @see StatementOrigin
 */
class StatementOriginTest extends TestCase
{
    private StatementOrigin $origin;

    protected function setUp(): void
    {
        $this->origin = new StatementOrigin();
    }

    /**
     * The regression this class exists to prevent, in the layout the module is actually deployed
     * in. The skip list originally named only "/Muon/DevProfiler/" — the app/code path — so once
     * the module was installed by Composer at vendor/muon/module-dev-profiler, nothing matched and
     * every statement reported its origin as this module's own QueryLogger. True, and useless.
     */
    public function testSkipsThisModulesOwnFramesWhenInstalledByComposer(): void
    {
        $resolved = $this->origin->resolve([
            $this->frame('/var/www/magento/vendor/muon/module-dev-profiler/Plugin/Db/QueryLogger.php', 154),
            $this->frame('/var/www/magento/app/code/Muon/ProductDetail/Model/Loader.php', 88),
        ]);

        self::assertSame('app/code/Muon/ProductDetail/Model/Loader.php:88', $resolved['origin']);
        self::assertTrue($resolved['is_userland']);
    }

    public function testSkipsThisModulesOwnFramesWhenInstalledUnderAppCode(): void
    {
        $resolved = $this->origin->resolve([
            $this->frame('/var/www/magento/app/code/Muon/DevProfiler/Plugin/Db/QueryLogger.php', 154),
            $this->frame('/var/www/magento/vendor/magento/module-catalog/Model/Product.php', 42),
        ]);

        self::assertSame('vendor/magento/module-catalog/Model/Product.php:42', $resolved['origin']);
        self::assertFalse($resolved['is_userland']);
    }

    public function testSkipsTheDatabasePlumbingIncludingBundledZendDb(): void
    {
        $resolved = $this->origin->resolve([
            $this->frame('/var/www/magento/vendor/magento/framework/DB/Adapter/Pdo/Mysql.php', 10),
            $this->frame('/var/www/magento/vendor/magento/zend-db/library/Zend/Db/Adapter/Abstract.php', 20),
            $this->frame('/var/www/magento/generated/code/Some/Interceptor.php', 30),
            $this->frame('/var/www/magento/vendor/magento/framework/Interception/Chain/Chain.php', 40),
            $this->frame('/var/www/magento/app/code/Muon/Thing/Model/Repository.php', 50),
        ]);

        self::assertSame('app/code/Muon/Thing/Model/Repository.php:50', $resolved['origin']);
    }

    public function testReportsNoOriginWhenEveryFrameIsPlumbing(): void
    {
        $resolved = $this->origin->resolve([
            $this->frame('/var/www/magento/vendor/muon/module-dev-profiler/Plugin/Db/QueryLogger.php', 154),
            $this->frame('/var/www/magento/vendor/magento/framework/DB/Adapter/Pdo/Mysql.php', 10),
        ]);

        self::assertNull($resolved['origin']);
        self::assertFalse($resolved['is_userland']);
    }

    public function testFramesWithoutAFileAreIgnoredRatherThanFatal(): void
    {
        $resolved = $this->origin->resolve([
            ['function' => 'call_user_func'],
            ['file' => '', 'line' => 1],
            $this->frame('/var/www/magento/app/design/frontend/Muon/cosmic/template.phtml', 7),
        ]);

        self::assertSame('app/design/frontend/Muon/cosmic/template.phtml:7', $resolved['origin']);
        self::assertTrue($resolved['is_userland']);
    }

    /**
     * @param string $file
     * @param int $line
     * @return array<string,mixed>
     */
    private function frame(string $file, int $line): array
    {
        return ['file' => $file, 'line' => $line];
    }
}
