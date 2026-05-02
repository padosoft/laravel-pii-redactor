<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Facades;

use Illuminate\Support\Facades\Facade;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Reports\DetectionReport;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;

/**
 * Public facade for the redactor engine.
 *
 * @method static string redact(string $text, ?RedactionStrategy $override = null)
 * @method static DetectionReport scan(string $text)
 * @method static void register(Detector $detector)
 * @method static void extend(string $alias, Detector $detector)
 * @method static array<string,Detector> detectors()
 * @method static RedactionStrategy strategy()
 * @method static bool isEnabled()
 * @method static RedactorEngine withEnabled(bool $enabled)
 *
 * @see RedactorEngine
 */
final class Pii extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'pii-redactor';
    }
}
