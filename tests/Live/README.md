# Live testsuite

The `Live` PHPUnit testsuite is reserved for scenarios that exercise a **real
external dependency**: a hosted NER service, an LLM-judge endpoint, a real
KMS-backed token store, etc. Every Live test is **opt-in** and **never runs in
CI** — they cost money, require credentials, and are rate-limited by upstream
providers.

This README documents the convention every Live test must follow so future
v0.3 / v0.4 / v1.0 contributors can drop new ones in without having to
reverse-engineer the pattern.

---

## When to use Live tests

Add a Live test when you need to assert an integration **as it really behaves
on the wire**:

- A new NER driver against a hosted model.
- An LLM-judge detector or evaluation harness.
- A KMS-backed `TokenStore` driver against a real key vault.
- Round-trip auth flows against a third-party identity provider.

Do **not** use Live tests for offline behaviour. Regex detectors, checksum
validators, hash / mask / tokenise strategies, and HTTP-shaped drivers
(`Http::fake()`) all live in the offline `Unit` suite where they run in
milliseconds and stay deterministic forever.

---

## How to run Live tests

The default invocation runs only the offline `Unit` suite:

```bash
vendor/bin/phpunit
```

To run the Live suite explicitly, opt in via the master env var:

```bash
PII_REDACTOR_LIVE=1 vendor/bin/phpunit --testsuite Live
```

Each Live test additionally requires its driver-specific credentials to be
present, otherwise the test self-skips with a helpful message.

---

## Required env vars per driver

| Test                            | Required env                                     |
|---------------------------------|--------------------------------------------------|
| `HuggingFaceNerDriverLiveTest`  | `HUGGINGFACE_API_KEY` (and `PII_REDACTOR_LIVE=1`)|
| `SpaCyNerDriverLiveTest`        | `SPACY_SERVER_URL`, optional `SPACY_API_KEY`     |

The single boolean `PII_REDACTOR_LIVE=1` is the master opt-in. Without it
**every** Live test self-skips, regardless of which driver-specific creds are
available in the environment. This guarantees a developer who only set
`HUGGINGFACE_API_KEY` for a different project does not accidentally rack up
charges by running the package's PHPUnit.

---

## CI policy

The `tests.yml` workflow runs only the offline suites:

```yaml
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Architecture
```

The `Live` suite is **never** invoked in CI — neither on push, PR, nor
scheduled cron. Live coverage is exercised by the maintainer during
release-candidate validation against staging keys.

---

## Adding a new Live test

1. Place the file under `tests/Live/` and name it `*LiveTest.php` so the
   suite picks it up.
2. Extend `Orchestra\Testbench\TestCase` (so the package service provider
   resolves and `config()` works) — or `PHPUnit\Framework\TestCase` for
   driver tests that take their own constructor arguments.
3. Guard the body with `markTestSkipped` on the master opt-in:
   ```php
   if (getenv('PII_REDACTOR_LIVE') !== '1') {
       $this->markTestSkipped('Live tests are opt-in. Set PII_REDACTOR_LIVE=1 to enable.');
   }
   ```
4. Add a second `markTestSkipped` for each driver-specific env var the test
   needs. Skip with a message that names the env var so a developer running
   the suite for the first time knows which knob to flip.
5. Keep the assertions **minimal**. One happy-path assertion per driver is
   enough. Detailed coverage stays in the offline `Unit` suite via
   `Http::fake()` — every additional Live assertion costs real money and
   slows down RC validation.

---

## Cost discipline

Live tests are a release-candidate gate, not a development feedback loop.
Every Live test added to this suite must:

- Self-skip cleanly when credentials are absent — the suite must remain
  green without any creds at all.
- Make at most a handful of upstream calls per test method.
- Use a sentence short enough to fit comfortably under any provider's
  free-tier quota (e.g. the canonical `"Mario Rossi lives in Milan and
  works at Padosoft."`).

If a regression you want to catch only manifests at scale, the right home is
a separate benchmark / load-test harness — not the Live PHPUnit suite.
