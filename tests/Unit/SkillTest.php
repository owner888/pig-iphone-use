<?php

declare(strict_types=1);

namespace PigIphoneUse\Test\Unit;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Prompt\Skills;

final class SkillTest extends TestCase
{
    /** One skill, and its description stays short: it is in every request's system prompt. */
    public function testThePackageHasOneSkillWithAShortDescription(): void
    {
        [$skills, $warnings] = Skills::fromDirectories([dirname(__DIR__, 2) . '/skills']);

        $this->assertSame([], $warnings);
        $this->assertSame(['iphone-use'], array_map(static fn ($s): string => $s->name, $skills));
        $this->assertLessThanOrEqual(250, mb_strlen($skills[0]->description));
    }
}
