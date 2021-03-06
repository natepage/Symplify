<?php

declare(strict_types=1);

namespace Symplify\CodingStandard\Tests\Rules\NoReturnArrayVariableList\Fixture;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;

final class SkipNews
{
    public function run()
    {
        return [new FrameworkBundle(), new TwigBundle()];
    }
}

