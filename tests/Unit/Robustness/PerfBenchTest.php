<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Robustness;

use Padosoft\PiiRedactor\Detectors\AddressItalianDetector;
use Padosoft\PiiRedactor\Detectors\CodiceFiscaleDetector;
use Padosoft\PiiRedactor\Detectors\CreditCardDetector;
use Padosoft\PiiRedactor\Detectors\EmailDetector;
use Padosoft\PiiRedactor\Detectors\IbanDetector;
use Padosoft\PiiRedactor\Detectors\PartitaIvaDetector;
use Padosoft\PiiRedactor\Detectors\PhoneItalianDetector;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Robustness tests covering category 6 (performance budgets).
 *
 * Pins per-call latency budgets for the full Italian detector stack
 * across realistic input sizes (empty → 1 MB) plus a peak-memory
 * ceiling on the largest input. The thresholds are deliberately
 * generous so they pass on a typical developer laptop (Apple M-series
 * / Herd PHP 8.4 on Windows), but tight enough to catch the obvious
 * regressions: someone introducing an O(n²) loop or an unbounded
 * intermediate buffer.
 *
 * Slow CI runners (shared GitHub-hosted x64 minutes during peak load)
 * may want to skip the perf budget — every method is tagged
 * `#[Group('perf')]` so `phpunit --exclude-group perf` opts out
 * cleanly. The default `phpunit tests/Unit/` run includes the group;
 * Lorenzo's local Herd setup runs all of them green in well under a
 * second total.
 *
 * Memory ceilings rely on `memory_get_peak_usage()` between calls; we
 * snapshot before the redact() call to discount whatever the test
 * harness has already allocated, then assert delta < ceiling.
 */
#[Group('perf')]
final class PerfBenchTest extends TestCase
{
    private function buildEngine(): RedactorEngine
    {
        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        $engine->register(new EmailDetector);
        $engine->register(new IbanDetector);
        $engine->register(new CreditCardDetector);
        $engine->register(new CodiceFiscaleDetector);
        $engine->register(new PartitaIvaDetector);
        $engine->register(new PhoneItalianDetector);
        $engine->register(new AddressItalianDetector);

        return $engine;
    }

    /**
     * Catches: a regression where the empty-text branch fans out into
     * the detector loop. `redact('')` must short-circuit immediately.
     */
    public function test_empty_document_is_under_one_millisecond(): void
    {
        $engine = $this->buildEngine();

        $start = microtime(true);
        $result = $engine->redact('');
        $elapsed = (microtime(true) - $start) * 1000.0;

        $this->assertSame('', $result);
        $this->assertLessThan(1.0, $elapsed, sprintf(
            'redact("") took %.3fms; the empty-string short-circuit must keep this under 1ms.',
            $elapsed,
        ));
    }

    /**
     * Catches: a single-detector quadratic regression. 1KB realistic
     * Italian prose with PII sprinkled in must redact in well under
     * 10ms on commodity hardware.
     */
    public function test_one_kilobyte_italian_text_is_under_ten_milliseconds(): void
    {
        $engine = $this->buildEngine();
        $text = $this->italianFixture(1024);

        $start = microtime(true);
        $engine->redact($text);
        $elapsed = (microtime(true) - $start) * 1000.0;

        $this->assertLessThan(10.0, $elapsed, sprintf(
            'redact() of 1KB Italian text took %.3fms; budget is 10ms.',
            $elapsed,
        ));
    }

    /**
     * Catches: an intermediate buffer that scales with `text * detectors`.
     * 100KB random Italian must complete inside 100ms — that is ~7
     * detectors over 100KB = 700KB of regex work, which PCRE handles
     * trivially when no catastrophic-backtracking pattern leaks in.
     */
    public function test_one_hundred_kilobyte_text_is_under_one_hundred_milliseconds(): void
    {
        $engine = $this->buildEngine();
        $text = $this->italianFixture(100 * 1024);

        $start = microtime(true);
        $engine->redact($text);
        $elapsed = (microtime(true) - $start) * 1000.0;

        $this->assertLessThan(100.0, $elapsed, sprintf(
            'redact() of 100KB Italian text took %.3fms; budget is 100ms.',
            $elapsed,
        ));
    }

    /**
     * Catches: an unbounded intermediate buffer or a quadratic
     * substr_replace loop. 1MB document must redact in under 2s.
     */
    public function test_one_megabyte_document_is_under_two_seconds(): void
    {
        $engine = $this->buildEngine();
        $text = $this->italianFixture(1024 * 1024);

        $start = microtime(true);
        $engine->redact($text);
        $elapsed = (microtime(true) - $start) * 1000.0;

        $this->assertLessThan(2000.0, $elapsed, sprintf(
            'redact() of 1MB document took %.3fms; budget is 2000ms.',
            $elapsed,
        ));
    }

    /**
     * Catches: a regression that builds the full detection list
     * in-memory simultaneously with the input copy and the output
     * buffer. The peak-memory delta on a 1MB document must stay under
     * 64 MB — comfortably above the input + 7 detector outputs but
     * far below "we accidentally cloned everything 10 times".
     */
    public function test_memory_peak_after_one_megabyte_document_under_sixty_four_megabytes(): void
    {
        $engine = $this->buildEngine();
        $text = $this->italianFixture(1024 * 1024);

        // Reset the peak so we measure the delta caused by redact()
        // alone, not whatever the test harness has already allocated.
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $beforePeak = memory_get_peak_usage(true);

        $engine->redact($text);

        $afterPeak = memory_get_peak_usage(true);
        $deltaBytes = $afterPeak - $beforePeak;
        $deltaMb = $deltaBytes / (1024 * 1024);

        // 64 MB ceiling — generous on purpose so this passes on every
        // PHP-INI default. A regression that double-buffers the input
        // would burst past 16 MB on a 1MB doc; we'd notice well before
        // the ceiling.
        $this->assertLessThan(64.0, $deltaMb, sprintf(
            'redact() of 1MB document raised peak memory by %.2f MB; budget is 64 MB.',
            $deltaMb,
        ));
    }

    /**
     * Build a deterministic Italian-prose fixture roughly $bytes long.
     * Mixes plain sentences with sprinkled PII (codice fiscale, P.IVA,
     * phone numbers, IBAN, email, addresses, credit-card numbers) so
     * every detector has a steady stream of work to do.
     */
    private function italianFixture(int $bytes): string
    {
        $blocks = [
            'La sede legale è in Via Roma 12, 50100 Firenze. ',
            "Contattare l'ufficio centrale tramite mario.rossi@example.com. ",
            'Telefono +39 02 1234567 oppure 348 1234567 nelle ore d\'ufficio. ',
            'Iban di riferimento IT60X0542811101000000123456 per i bonifici. ',
            'Carta di credito 4111 1111 1111 1111 (test data). ',
            'Codice fiscale RSSMRA80A01H501U registrato al servizio sanitario. ',
            'Partita IVA 12345678903 abilitata al regime ordinario. ',
            "Recapito alternativo: Via dell'Università 1, 40126 Bologna. ",
            'Cognome del titolare: Bianchi; tessera ISCR-987654 emessa nel 2019. ',
            'Per eventuali chiarimenti scrivere a info@example.it grazie. ',
        ];

        $out = '';
        $i = 0;
        while (strlen($out) < $bytes) {
            $out .= $blocks[$i % count($blocks)];
            $i++;
        }

        return substr($out, 0, $bytes);
    }
}
