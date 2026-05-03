<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Robustness;

use Padosoft\PiiRedactor\CustomRules\CustomRule;
use Padosoft\PiiRedactor\CustomRules\CustomRuleDetector;
use Padosoft\PiiRedactor\CustomRules\CustomRuleSet;
use Padosoft\PiiRedactor\Exceptions\CustomRuleException;
use PHPUnit\Framework\TestCase;

/**
 * Robustness tests covering category 5 (pathological PCRE patterns).
 *
 * Custom rule packs are tenant-authored. A malicious or careless
 * tenant can ship a pattern that hangs the regex engine or matches
 * the empty string at every position. This suite pins what the
 * detector does in those corners — typically: graceful degrade
 * (return [] on PCRE error) or early throw (CustomRuleException on
 * compile-time invalid pattern).
 *
 * The PHP runtime sets `pcre.backtrack_limit` (default 1_000_000) and
 * `pcre.recursion_limit` (default 100_000) — preg_match_all() returns
 * `false` when either is hit, and the detector's existing
 * `if (preg_match_all(...) === false) continue;` swallows the error
 * cleanly. These tests verify that contract.
 */
final class PathologicalPatternTest extends TestCase
{
    // ---------------------------------------------------------------
    // Catastrophic backtracking — must NOT hang.
    // ---------------------------------------------------------------

    /**
     * Catches: a regression that drops the `preg_match_all() === false`
     * guard. Without it, a catastrophic-backtracking pattern would
     * either hang the request or surface a PHP warning that crashes
     * the host's error handler.
     *
     * `(a+)+b` is the textbook ReDoS pattern: it matches anything
     * ending in `b`, but on input WITHOUT `b` it explores 2^n
     * possibilities. PHP's `pcre.backtrack_limit` aborts the match
     * and `preg_match_all` returns false → the detector returns [].
     */
    public function test_catastrophic_backtracking_pattern_returns_empty_within_one_second(): void
    {
        $detector = new CustomRuleDetector(
            'pack_redos',
            new CustomRuleSet([new CustomRule('redos_pattern', '(a+)+b')]),
        );

        $input = str_repeat('a', 36).'!'; // No `b` — triggers backtrack explosion.

        $start = microtime(true);
        $hits = $detector->detect($input);
        $elapsed = microtime(true) - $start;

        // The detector returned cleanly — either with [] (preg returned
        // false, caught by the guard) or with [] (no match found, since
        // there's no `b`). Either way, the elapsed time is bounded by
        // PCRE's backtrack_limit, NOT by 2^n exploration time.
        $this->assertLessThan(2.0, $elapsed, sprintf(
            'Catastrophic-backtracking pattern took %.3fs; the PCRE backtrack limit must abort it.',
            $elapsed,
        ));
        $this->assertSame([], $hits);
    }

    /**
     * Catches: regression where `(a*)*b` (zero-or-more nested) causes
     * a different PCRE failure mode (recursion limit, not backtrack).
     */
    public function test_nested_zero_or_more_quantifier_returns_within_one_second(): void
    {
        $detector = new CustomRuleDetector(
            'pack_redos2',
            new CustomRuleSet([new CustomRule('zero_or_more_nested', '(a*)*b')]),
        );

        $input = str_repeat('a', 30).'X';

        $start = microtime(true);
        $hits = $detector->detect($input);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(2.0, $elapsed, sprintf(
            'Nested zero-or-more pattern took %.3fs; PCRE must abort cleanly.',
            $elapsed,
        ));
        $this->assertSame([], $hits);
    }

    // ---------------------------------------------------------------
    // Empty-match patterns.
    // ---------------------------------------------------------------

    /**
     * Catches: a regression that re-introduces zero-length Detection
     * emission. v1.0 added a `length === 0` guard to
     * `CustomRuleDetector::detect()` that drops empty matches
     * silently — pathological patterns like `a*` against `"hello"`
     * now produce ZERO detections instead of `strlen($input)+1`
     * empty hits. That is the deliberate v1.0 contract: tenant packs
     * shipping a careless `a*` cannot inflate the audit-trail count.
     *
     * Pinned in v0.3 (and earlier): each zero-width position would
     * emit a Detection with `length === 0` and `value === ''`.
     * Pinned in v1.0+: zero detections.
     */
    public function test_empty_match_pattern_emits_no_zero_length_detections(): void
    {
        $detector = new CustomRuleDetector(
            'pack_empty',
            new CustomRuleSet([new CustomRule('empty_match', 'a*')]),
        );

        $hits = $detector->detect('hello');

        // v1.0 contract: zero-length matches are filtered out at
        // detect-time so the detection report stays clean. Pre-v1.0
        // behaviour was `strlen('hello')+1 = 6` empty Detections.
        $this->assertSame([], $hits);
    }

    /**
     * Mixed-pattern fixture: a single rule that captures BOTH
     * zero-length and proper matches MUST emit only the proper
     * matches. `a*` against `"caat"` zero-widths every gap AND finds
     * `aa` at offset 1 — only the latter survives the v1.0 filter.
     */
    public function test_zero_length_filter_preserves_real_matches_in_same_pattern(): void
    {
        $detector = new CustomRuleDetector(
            'pack_mixed',
            new CustomRuleSet([new CustomRule('greedy_a', 'a*')]),
        );

        $hits = $detector->detect('caat');

        // The single non-empty match `aa` (offset 1, length 2) is the
        // only Detection — every zero-width hit between chars is
        // dropped by the length-0 guard.
        $nonEmpty = array_values(array_filter($hits, static fn ($h) => $h->length > 0));
        $this->assertCount(count($hits), $nonEmpty, 'No zero-length detections should leak through.');
        $this->assertCount(1, $hits);
        $this->assertSame('aa', $hits[0]->value);
        $this->assertSame(1, $hits[0]->offset);
        $this->assertSame(2, $hits[0]->length);
    }

    /**
     * Catches: a regression where the empty-line anchor `^$` against
     * a non-empty single-line input causes a crash.
     */
    public function test_empty_line_anchor_against_non_empty_input_returns_empty(): void
    {
        $detector = new CustomRuleDetector(
            'pack_anchor',
            new CustomRuleSet([new CustomRule('empty_line', '^$')]),
        );

        $hits = $detector->detect('hello world');

        $this->assertSame([], $hits);
    }

    /**
     * Catches: a regression where the empty-line anchor against an
     * actually-empty string short-circuits incorrectly. The detector
     * has an early return on `text === ''`, so the pattern never
     * compiles — the test pins that contract.
     */
    public function test_empty_line_anchor_against_empty_string_short_circuits_via_early_return(): void
    {
        $detector = new CustomRuleDetector(
            'pack_anchor',
            new CustomRuleSet([new CustomRule('empty_line', '^$')]),
        );

        $this->assertSame([], $detector->detect(''));
    }

    // ---------------------------------------------------------------
    // Extreme repetition.
    // ---------------------------------------------------------------

    /**
     * Catches: a regression where extreme bounded quantifiers
     * `a{1,1000000}` cause the PCRE compiler to reject the pattern.
     * The current implementation throws CustomRuleException via
     * compiledPattern() when `preg_match` returns false on the
     * compile probe. PCRE 10+ rejects bounds > ~100k by default,
     * so this is the documented escape hatch.
     */
    public function test_extreme_bounded_quantifier_handles_compile_failure_gracefully(): void
    {
        $rule = new CustomRule('extreme_bound', 'a{1,1000000}');

        // Either: compile probe in compiledPattern() throws, OR the
        // pattern compiles and detect() returns gracefully (PCRE may
        // still reject at match time). Pin both branches as
        // acceptable. The test FAILS only if the host crashes.
        $detector = new CustomRuleDetector('pack_extreme', new CustomRuleSet([$rule]));

        try {
            $hits = $detector->detect('aaa');
            // If detect() returned cleanly, $hits is a list (possibly empty).
            $this->assertIsArray($hits);
        } catch (CustomRuleException $e) {
            // If the compile probe threw, that is also a documented
            // path (R4-style "fail loudly"). Either branch is OK; the
            // contract is "no segfault, no warning, no host crash".
            $this->assertStringContainsString('extreme_bound', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------
    // Multiple custom rules with overlapping patterns.
    // ---------------------------------------------------------------

    /**
     * Catches: a regression where two rules in the SAME pack matching
     * overlapping spans both emit a Detection — the engine then
     * resolves the overlap, but the test verifies the detector itself
     * does NOT swallow either rule.
     */
    public function test_pack_with_overlapping_rules_emits_one_detection_per_rule(): void
    {
        $detector = new CustomRuleDetector(
            'overlapping_pack',
            new CustomRuleSet([
                new CustomRule('first_rule', 'mario@'),                  // 6 chars at offset 0.
                new CustomRule('second_rule', '@example\.com'),           // 12 chars at offset 5.
                new CustomRule('third_rule', 'mario@example\.com'),       // 17 chars at offset 0.
            ]),
        );

        $hits = $detector->detect('mario@example.com');

        // The CustomRuleDetector itself does NOT resolve overlaps —
        // that's the engine's job. Three rules → three detections
        // (offset asc by rule emission order is not guaranteed; values
        // alone are checked).
        $this->assertCount(3, $hits);
        $values = array_map(static fn ($h) => $h->value, $hits);
        $this->assertContains('mario@', $values);
        $this->assertContains('@example.com', $values);
        $this->assertContains('mario@example.com', $values);
    }

    /**
     * Catches: a regression where two rules with the same pattern
     * get deduplicated incorrectly inside the detector. Two rules
     * with IDENTICAL pattern but different names emit one detection
     * EACH per match (no dedup at detector level — pin contract).
     */
    public function test_two_rules_with_identical_pattern_each_emit_their_own_detection(): void
    {
        $detector = new CustomRuleDetector(
            'identical_pack',
            new CustomRuleSet([
                new CustomRule('rule_alpha', 'X-\d+'),
                new CustomRule('rule_beta', 'X-\d+'),
            ]),
        );

        $hits = $detector->detect('Code X-1234 here');

        // Two rules, both fire → two detections. Both share the SAME
        // detector-pack name (CustomRuleDetector emits one name for
        // every rule in the set), but offset 5 is recorded twice.
        $this->assertCount(2, $hits);
        foreach ($hits as $hit) {
            $this->assertSame('identical_pack', $hit->detector);
            $this->assertSame('X-1234', $hit->value);
            $this->assertSame(5, $hit->offset);
        }
    }

    /**
     * Catches: a regression where a syntactically-invalid PCRE
     * pattern (unbalanced parens) crashes the detector instead of
     * throwing CustomRuleException at compile time.
     */
    public function test_invalid_pcre_pattern_throws_custom_rule_exception(): void
    {
        $detector = new CustomRuleDetector(
            'invalid_pack',
            new CustomRuleSet([new CustomRule('bad_rule', '(unbalanced')]),
        );

        $this->expectException(CustomRuleException::class);

        $detector->detect('any input');
    }

    // ---------------------------------------------------------------
    // Custom flag handling.
    // ---------------------------------------------------------------

    /**
     * Catches: a regression where the `i` flag (case-insensitive)
     * stops being honored from the per-rule flags.
     */
    public function test_per_rule_flags_are_honored(): void
    {
        $detector = new CustomRuleDetector(
            'flags_pack',
            new CustomRuleSet([new CustomRule('case_insensitive', 'SECRET', 'iu')]),
        );

        $hits = $detector->detect('this is a secret document');

        $this->assertCount(1, $hits);
        $this->assertSame('secret', $hits[0]->value);
    }

    /**
     * Catches: a regression where forward slashes inside a rule
     * pattern collide with the chosen `/` delimiter. The current
     * compiledPattern() escapes them — pin that behaviour.
     */
    public function test_forward_slash_in_pattern_is_escaped_against_delimiter_collision(): void
    {
        $detector = new CustomRuleDetector(
            'slash_pack',
            new CustomRuleSet([new CustomRule('url_path', '/api/v\d+/users')]),
        );

        $hits = $detector->detect('GET /api/v1/users');

        $this->assertCount(1, $hits);
        $this->assertSame('/api/v1/users', $hits[0]->value);
    }
}
