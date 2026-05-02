<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Live;

use PHPUnit\Framework\TestCase;

/**
 * Placeholder Live test.
 *
 * The Live testsuite is reserved for v0.2+ scenarios that need a real
 * external dependency (NER service, LLM-backed detector, real KMS
 * tokenisation backend). It is NEVER run by CI. Every Live test
 * self-skips unless `PII_REDACTOR_LIVE=1` is set in the environment.
 *
 * See README "Testing — Default + Live" for invocation details.
 */
final class PlaceholderLiveTest extends TestCase
{
    public function test_live_suite_is_opt_in(): void
    {
        if (getenv('PII_REDACTOR_LIVE') !== '1') {
            $this->markTestSkipped('Live suite is opt-in. Set PII_REDACTOR_LIVE=1 to run.');
        }

        $this->assertTrue(true, 'Live placeholder ran successfully.');
    }
}
