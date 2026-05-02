<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\CodiceFiscaleDetector;
use PHPUnit\Framework\TestCase;

final class CodiceFiscaleDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('codice_fiscale', (new CodiceFiscaleDetector)->name());
    }

    public function test_detects_a_valid_codice_fiscale(): void
    {
        // RSSMRA85T10A562S — Mario Rossi, 10/12/1985, Bologna. Valid CIN.
        $detector = new CodiceFiscaleDetector;
        $hits = $detector->detect('Codice fiscale: RSSMRA85T10A562S, archiviato.');

        $this->assertCount(1, $hits);
        $this->assertSame('RSSMRA85T10A562S', $hits[0]->value);
        $this->assertSame('codice_fiscale', $hits[0]->detector);
        $this->assertSame(16, $hits[0]->offset);
    }

    public function test_detects_lowercase_input(): void
    {
        $detector = new CodiceFiscaleDetector;
        $hits = $detector->detect('cf=rssmra85t10a562s end');

        $this->assertCount(1, $hits);
        $this->assertSame('rssmra85t10a562s', $hits[0]->value);
    }

    public function test_rejects_invalid_checksum(): void
    {
        // Same payload as above, swap the CIN to a wrong letter.
        $detector = new CodiceFiscaleDetector;
        $hits = $detector->detect('Codice fiscale fasullo: RSSMRA85T10A562X.');

        $this->assertSame([], $hits);
    }

    public function test_rejects_wrong_length_or_shape(): void
    {
        $detector = new CodiceFiscaleDetector;

        $this->assertSame([], $detector->detect('RSSMRA85T10A562'));    // 15 chars.
        $this->assertSame([], $detector->detect('RSSMRA85Q10A562S'));   // 'Q' is not in month set.
        $this->assertSame([], $detector->detect('123456781234567X'));   // all digits.
        $this->assertSame([], $detector->detect(''));
    }

    public function test_finds_multiple_codici_fiscali(): void
    {
        $detector = new CodiceFiscaleDetector;
        $text = 'A: RSSMRA85T10A562S, B: NRELRT75D03H501K.';
        $hits = $detector->detect($text);

        $this->assertCount(2, $hits);
        $this->assertSame('RSSMRA85T10A562S', $hits[0]->value);
        $this->assertSame('NRELRT75D03H501K', $hits[1]->value);
    }

    public function test_detects_single_position_omocodia_substitution(): void
    {
        // Canonical RSSMRA85T10A562S; position 14 (city digit '2')
        // substituted with omocodia letter 'N'. The CIN recomputes to
        // 'S' under the standard 1976 odd/even table once the
        // substitution is normalised back to a digit.
        $detector = new CodiceFiscaleDetector;
        $hits = $detector->detect('Codice fiscale: RSSMRA85T10A56NS, archiviato.');

        $this->assertCount(1, $hits);
        $this->assertSame('RSSMRA85T10A56NS', $hits[0]->value);
    }

    public function test_detects_multi_position_omocodia_substitution(): void
    {
        // Same canonical, two substitutions: position 6 (year digit
        // '8'->'U') and position 14 (city digit '2'->'N'). CIN remains
        // 'S' after omocodia normalisation.
        $detector = new CodiceFiscaleDetector;
        $hits = $detector->detect('CF: RSSMRAU5T10A56NS rilasciato.');

        $this->assertCount(1, $hits);
        $this->assertSame('RSSMRAU5T10A56NS', $hits[0]->value);
    }
}
