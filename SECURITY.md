# Security policy

`padosoft/laravel-pii-redactor` is an Apache-2.0 PHP library that handles
personally identifiable information at the boundary between user input
and downstream LLMs / log sinks / forensic stores. Treat security
findings against this package with the same seriousness as you would
against an authentication library.

## Supported versions

| Version | Status                  | Security fixes                                                        |
|---------|-------------------------|------------------------------------------------------------------------|
| 1.0.x   | Active                  | YES                                                                    |
| 0.3.x   | Maintenance only        | YES (v0.3.x patch)                                                     |
| 0.2.x   | End-of-life on 1.0 GA   | NO new fixes — upgrade path is v0.2 → v1.0 (drop-in)                   |
| 0.1.x   | End-of-life             | NO                                                                     |

`v0.2.x` exits security maintenance on the v1.0 GA. Production hosts
should upgrade — there are zero breaking changes between v0.2 and v1.0.

## Reporting a vulnerability

**Do NOT open a public GitHub issue for security findings.**

Email `security@padosoft.com` with:

- A description of the vulnerability and its blast radius (information
  disclosure / privilege escalation / denial of service / supply chain).
- A minimal reproducer (PHP code snippet, fixture text, environment
  details). The smaller the reproducer, the faster the triage.
- Your preferred disclosure timeline. We default to **90 days** from
  acknowledgement to public advisory; longer timelines are possible
  when the fix requires schema migration or coordinated patching.

You'll receive an acknowledgement within **2 business days** and a
triage decision (accepted / rejected / clarification-needed) within
**5 business days**.

## Disclosure timeline

1. **T+0**: report received, ack sent within 2 business days.
2. **T+5d**: triage decision sent.
3. **T+30d–60d**: fix implemented, internal review (Copilot review +
   architecture test + new regression test pinning the vulnerability).
4. **T+30d–60d**: fix released as a patch version (v1.0.x or v0.3.x
   depending on what is supported).
5. **T+60d–90d**: public security advisory published on GitHub Security
   Advisories + CHANGELOG entry under "Security".

If the vulnerability is being actively exploited, we accelerate to
**T+7d** for the patch + advisory.

## Scope

In-scope:

- Detector bypass (a real fiscal code / PII pattern that the package
  fails to detect).
- Detector false positive (the package redacts non-PII content,
  corrupting the user's data).
- Strategy bypass (the redacted output still contains the original
  PII bytes — e.g. mask token leaking input).
- TokenStore disclosure (an unauthenticated reader can derive the
  original PII from the persisted token map).
- NER driver leak (the driver sends raw user input to a third-party
  service the host did not authorize).
- Standalone-agnostic invariant violation (the package starts
  importing host-app code, breaking GDPR data-flow boundaries).

Out of scope:

- Bugs in third-party services we depend on (HuggingFace API, spaCy
  servers) — report those upstream.
- Misconfiguration vulnerabilities in consumer applications (e.g.
  storing the salt in a public repo).
- Performance-only DoS that requires unrealistic input sizes (multi-GB
  payloads).

## Hall of fame

We credit contributors who report security findings in the public
advisory + the project README. Opt out by stating so in your initial
report.
