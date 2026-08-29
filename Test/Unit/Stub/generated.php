<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

/**
 * Minimal stand-ins for Magento's *generated* factory classes.
 *
 * `Magento\Developer\Model\TemplateEngine\Decorator\DebugHintsFactory` has no source file: Magento
 * generates it into `generated/code` on demand. A full install therefore has it and a bare
 * `composer install` — which is exactly what CI does — does not, so a test that doubles it passes
 * locally and errors in CI with "Class or interface ... does not exist".
 *
 * Declaring it here only when it is genuinely absent keeps these tests running everywhere rather
 * than skipping them in CI, which would put them back in the category this module just left: tests
 * that exist and never run.
 */

if (!class_exists(\Magento\Developer\Model\TemplateEngine\Decorator\DebugHintsFactory::class, false)) {
    // phpcs:disable
    eval(
        'namespace Magento\Developer\Model\TemplateEngine\Decorator;'
        . 'class DebugHintsFactory {'
        . '    public function create(array $data = []): DebugHints { throw new \LogicException("stub"); }'
        . '}'
    );
    // phpcs:enable
}
