<?php

declare(strict_types=1);

namespace TwigStan\EndToEnd\IncludeFromTwig;

use TwigStan\EndToEnd\AbstractEndToEndTestCase;

final class IncludeFromTwigTest extends AbstractEndToEndTestCase
{
    public function test(): void
    {
        parent::runAnalysis(__DIR__);
    }
}
