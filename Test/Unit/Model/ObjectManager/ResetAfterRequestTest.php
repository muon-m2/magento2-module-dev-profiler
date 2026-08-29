<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\ObjectManager;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Muon\DevProfiler\Model\Run\Gate;
use Muon\DevProfiler\Model\Run\RunContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every singleton in this module that holds per-request state must be resettable.
 *
 * The module's own collectors are `shared` by default, so in a stateful runtime — an application
 * server, or the long-lived CLI process this module already had to guard against — one request's
 * recording would otherwise be attributed to the next. Core's own DB\Logger\LoggerProxy, the very
 * class this module plugs, implements this interface for the same reason.
 *
 * The list is asserted rather than described, so a seventh stateful singleton added later fails
 * here instead of quietly leaking.
 */
#[AllowMockObjectsWithoutExpectations]
class ResetAfterRequestTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function statefulSingletons(): array
    {
        return [
            'RunContext' => [\Muon\DevProfiler\Model\Run\RunContext::class],
            'Gate' => [\Muon\DevProfiler\Model\Run\Gate::class],
            'QueryLogger' => [\Muon\DevProfiler\Plugin\Db\QueryLogger::class],
            'FallbackRecorder' => [\Muon\DevProfiler\Plugin\View\FallbackRecorder::class],
            'TemplateHints' => [\Muon\DevProfiler\Plugin\View\TemplateHints::class],
            'ShadowResolver' => [\Muon\DevProfiler\Model\Analysis\ShadowResolver::class],
        ];
    }

    /**
     * @param class-string $class
     * @return void
     */
    #[DataProvider('statefulSingletons')]
    public function testItDeclaresItselfResettable(string $class): void
    {
        self::assertTrue(
            (new ReflectionClass($class))->implementsInterface(ResetAfterRequestInterface::class),
            $class . ' holds per-request state but cannot be reset'
        );
    }

    /**
     * Any mutable, non-readonly property must actually return to its declared default — an
     * implementation that satisfies the interface and resets nothing is worse than none, because
     * it reads as handled.
     *
     * @param class-string $class
     * @return void
     */
    #[DataProvider('statefulSingletons')]
    public function testResetStateReturnsEveryMutablePropertyToItsDefault(string $class): void
    {
        $reflection = new ReflectionClass($class);

        /** @var ResetAfterRequestInterface $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $defaults = $reflection->getDefaultProperties();

        $dirtied = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->isReadOnly() || $property->isStatic()) {
                continue;
            }

            $name = $property->getName();
            $property->setValue($instance, $this->dirtyValueFor($defaults[$name] ?? null));
            $dirtied[$name] = $defaults[$name] ?? null;
        }

        self::assertNotSame([], $dirtied, $class . ' has no mutable state; it should not implement the interface');

        $instance->_resetState();

        foreach ($dirtied as $name => $default) {
            self::assertSame(
                $default,
                $reflection->getProperty($name)->getValue($instance),
                $class . '::$' . $name . ' did not return to its default after _resetState()'
            );
        }
    }

    /**
     * A value of the property's own type that is definitely not its default.
     *
     * @param mixed $default
     * @return mixed
     */
    private function dirtyValueFor(mixed $default): mixed
    {
        return match (true) {
            is_array($default) => ['dirty'],
            is_bool($default) => !$default,
            is_float($default) => 1.5,
            is_int($default) => 99,
            is_string($default) => 'dirty',
            // Declared null: every such property here is ?string, ?bool or ?float, and each of
            // those accepts a float, so one sentinel covers them without knowing which is which.
            default => 1.5,
        };
    }

    public function testRunContextForgetsAWholeRequest(): void
    {
        $context = new RunContext();
        $context->push('fallback', ['file' => 'x.less']);
        $context->setMeta('layout_generated', true);

        self::assertNotSame([], $context->all('fallback'));

        $context->_resetState();

        self::assertSame([], $context->all('fallback'), "one page's fallbacks must not appear in the next");
        self::assertNull($context->meta('layout_generated'));
    }

    public function testGateAsksAgainAfterAReset(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn(State::MODE_DEVELOPER);
        $state->method('getAreaCode')->willReturn(Area::AREA_FRONTEND);
        $state->method('isAreaCodeEmulated')->willReturn(false);

        $gate = new Gate($state);
        $gate->isProfiled();

        self::assertTrue($gate->isDecided());

        $gate->_resetState();

        self::assertFalse($gate->isDecided(), 'the next request must be asked afresh');
    }
}
