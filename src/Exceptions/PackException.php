<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Exceptions;

/**
 * Thrown when a country / region pack listed in
 * `config('pii-redactor.packs')` cannot be resolved into a usable
 * `PackContract` instance.
 *
 * Surfaces three failure modes:
 *  - the configured FQCN does not exist;
 *  - the resolved class does not implement `PackContract`;
 *  - the pack returned an entry from `detectors()` that does not
 *    implement `Detector`.
 *
 * Failing fast at SP boot is preferred over silent skip: a host that
 * misconfigured a pack would otherwise ship with the jurisdictional
 * coverage it expected to enable silently disabled, and the resulting
 * GDPR / leakage exposure would only surface in production logs.
 *
 * Extends PiiRedactorException so callers can catch the package's
 * umbrella exception type without distinguishing between pack and
 * detector failures.
 */
final class PackException extends PiiRedactorException {}
