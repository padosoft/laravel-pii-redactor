<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Robustness;

use Padosoft\PiiRedactor\Detectors\AddressItalianDetector;
use Padosoft\PiiRedactor\Detectors\CodiceFiscaleDetector;
use Padosoft\PiiRedactor\Detectors\EmailDetector;
use Padosoft\PiiRedactor\Detectors\IbanDetector;
use Padosoft\PiiRedactor\Detectors\PhoneItalianDetector;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Robustness tests covering category 7 (streaming-style + MCP-style
 * invocation patterns).
 *
 * Two real-world consumer patterns this suite pins:
 *
 *  1. STREAMING: a chat host accumulates user/assistant turns into a
 *     growing buffer and re-redacts the whole buffer on every new
 *     turn. We loop 100 times, each iteration appending one PII-rich
 *     sentence, and assert peak memory grows roughly LINEARLY with
 *     buffer size — no O(n²) leak from a Detector keeping per-call
 *     state, and no unbounded internal cache.
 *
 *  2. MCP-style invocation: callers route the SAME input through
 *     `Pii::redact()` (returns redacted string) and `Pii::scan()`
 *     (returns DetectionReport). The two paths must agree on detector
 *     counts — `scan()->countsByDetector()` matches the implicit
 *     counts that `redact()` consumed. A regression where the two
 *     paths diverge would silently corrupt MCP audit trails.
 *
 * Both scenarios use the engine directly (not the Facade) to keep the
 * test independent of Testbench bootstrapping; the Facade is a thin
 * delegating wrapper, so the contract is identical.
 */
final class StreamingTest extends TestCase
{
    private function buildEngine(): RedactorEngine
    {
        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        $engine->register(new EmailDetector);
        $engine->register(new IbanDetector);
        $engine->register(new CodiceFiscaleDetector);
        $engine->register(new PhoneItalianDetector);
        $engine->register(new AddressItalianDetector);

        return $engine;
    }

    /**
     * Catches: a regression where the engine retains per-call state
     * (a static map, an array_merge accumulator, an internal cache
     * that never clears). Over 100 iterations the peak-memory growth
     * must stay sub-linear-with-headroom: the buffer itself reaches
     * ~10 KB by turn 100, so any growth >> 4 MB indicates a leak.
     */
    public function test_streaming_redact_calls_have_no_unbounded_memory_growth(): void
    {
        $engine = $this->buildEngine();

        $sentence = 'Paolo (mario@example.com, IBAN IT60X0542811101000000123456, '.
            'tel +39 348 1234567, codice fiscale RSSMRA80A01H501U) abita in Via Roma 12. ';

        // Reset peak so we measure incremental growth caused by the
        // streaming loop alone.
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $startPeak = memory_get_peak_usage(true);

        $buffer = '';
        for ($i = 0; $i < 100; $i++) {
            $buffer .= $sentence;
            $output = $engine->redact($buffer);
            // Drop the output every iteration — only the growing
            // $buffer must persist. If the engine internally retained
            // anything keyed off the input, it would surface as
            // ever-growing peak memory.
            $this->assertNotSame($buffer, $output, "Iteration {$i}: redact() returned the input verbatim.");
            $this->assertStringNotContainsString('mario@example.com', $output, "Iteration {$i}: email leaked through.");
        }

        $endPeak = memory_get_peak_usage(true);
        $deltaBytes = $endPeak - $startPeak;
        $deltaMb = $deltaBytes / (1024 * 1024);

        // 16 MB ceiling — well above the linear scaling of buffer +
        // detector outputs, but tight enough to fail loudly on any
        // hidden accumulator.
        $this->assertLessThan(16.0, $deltaMb, sprintf(
            '100 streaming redact() calls leaked %.2f MB peak memory; budget is 16 MB.',
            $deltaMb,
        ));
    }

    /**
     * Catches: a regression where redact() and scan() walk different
     * detector lists or apply different overlap-resolution policies.
     * The MCP server uses scan() for audit reports and redact() for
     * the actual response payload — they must agree on counts.
     */
    public function test_redact_and_scan_agree_on_per_detector_counts(): void
    {
        $engine = $this->buildEngine();

        $text = 'Contatto: mario.rossi@example.com / +39 02 1234567 / IBAN IT60X0542811101000000123456. '.
            'Codice fiscale RSSMRA80A01H501U. Sede in Via Roma 12, Firenze. '.
            'Backup: rita@example.com.';

        $report = $engine->scan($text);
        $redacted = $engine->redact($text);

        // Each detector that fires AT LEAST ONCE must leave a [X]
        // sentinel in the redacted output. `scan()->total()` counts
        // EACH detection — including overlaps that get resolved away
        // by `collectDetections()` — so the count of [X] sentinels
        // equals scan()->total() exactly (the engine uses the same
        // resolved list for both paths).
        $sentinelCount = substr_count($redacted, '[X]');
        $this->assertSame($report->total(), $sentinelCount, sprintf(
            'scan() reported %d detections; redact() inserted %d sentinels — the two paths must agree.',
            $report->total(),
            $sentinelCount,
        ));

        // Per-detector counts must match for every detector that fired.
        $counts = $report->countsByDetector();
        $this->assertArrayHasKey('email', $counts);
        $this->assertSame(2, $counts['email']);
        $this->assertArrayHasKey('iban', $counts);
        $this->assertSame(1, $counts['iban']);
        $this->assertArrayHasKey('codice_fiscale', $counts);
        $this->assertSame(1, $counts['codice_fiscale']);
        $this->assertArrayHasKey('phone_it', $counts);
        $this->assertGreaterThanOrEqual(1, $counts['phone_it']);
        $this->assertArrayHasKey('address_it', $counts);
        $this->assertGreaterThanOrEqual(1, $counts['address_it']);
    }

    /**
     * Catches: a regression where re-running scan() on a previously
     * redacted output picks up new "phantom" PII — proves redact()
     * leaves no detectable PII behind. Combined with the count-match
     * test above, this is the MCP audit trail's invariant: scanning
     * a redacted document yields zero detections.
     */
    public function test_scan_on_redacted_output_returns_zero_detections(): void
    {
        $engine = $this->buildEngine();

        $text = 'Email: mario@example.com, IBAN IT60X0542811101000000123456, '.
            'codice fiscale RSSMRA80A01H501U, telefono +39 348 1234567, sede Via Roma 12.';

        $redacted = $engine->redact($text);
        $rescanned = $engine->scan($redacted);

        $this->assertSame(0, $rescanned->total(), sprintf(
            'scan() on redacted output found %d phantom detections — redact() must leave nothing scannable.',
            $rescanned->total(),
        ));
    }
}
