<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by RedactorEngine after a redact() call when audit_trail.enabled is true.
 *
 * GDPR-friendly: carries ONLY counts, NEVER the original PII or the redacted
 * output. Listeners can correlate counts to time + tenant + request_id via the
 * host's standard logging context — that's the host's responsibility, not this
 * package's.
 */
final class PiiRedactionPerformed
{
    use Dispatchable;

    /**
     * @param  array<string, int>  $countsByDetector  detector_name => match count
     */
    public function __construct(
        public readonly array $countsByDetector,
        public readonly int $total,
        public readonly string $strategyName,
    ) {}
}
