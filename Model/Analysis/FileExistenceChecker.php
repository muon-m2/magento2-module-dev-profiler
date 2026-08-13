<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Analysis;

/**
 * A seam over "does this file exist".
 *
 * Exists so ShadowResolver can be unit tested against a stub instead of a real theme tree. The
 * alternative — asserting against files on disk — would make the tests depend on which themes
 * happen to be installed, which is exactly the variable the resolver is meant to explain.
 */
class FileExistenceChecker
{
    /**
     * Whether a regular file exists at the given absolute path.
     *
     * @param string $path
     * @return bool
     */
    public function exists(string $path): bool
    {
        return is_file($path);
    }
}
