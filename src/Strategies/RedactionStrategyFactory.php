<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Strategies;

use Illuminate\Contracts\Config\Repository;
use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Padosoft\PiiRedactor\TokenStore\TokenStore;

final class RedactionStrategyFactory
{
    public function __construct(
        private readonly Repository $config,
        private readonly TokenStore $tokenStore,
    ) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return ['mask', 'hash', 'tokenise', 'drop'];
    }

    public function make(?string $name = null): RedactionStrategy
    {
        $strategy = $name ?? (string) $this->config->get('pii-redactor.strategy', 'mask');

        return match ($strategy) {
            'mask' => new MaskStrategy((string) $this->config->get('pii-redactor.mask_token', '[REDACTED]')),
            'hash' => new HashStrategy(
                salt: $this->requireSalt($this->config->get('pii-redactor.salt')),
                hexLength: (int) $this->config->get('pii-redactor.hash_hex_length', 16),
            ),
            'tokenise' => new TokeniseStrategy(
                salt: $this->requireSalt($this->config->get('pii-redactor.salt')),
                idHexLength: (int) $this->config->get('pii-redactor.token_hex_length', 16),
                store: $this->tokenStore,
            ),
            'drop' => new DropStrategy,
            default => throw new StrategyException(sprintf(
                'Unknown PII redaction strategy [%s]. Valid: mask, hash, tokenise, drop.',
                $strategy,
            )),
        };
    }

    private function requireSalt(mixed $raw): string
    {
        $salt = is_string($raw) ? $raw : '';
        if ($salt === '') {
            throw new StrategyException(
                'PII_REDACTOR_SALT must be a non-empty string when using hash or tokenise strategies.',
            );
        }

        return $salt;
    }
}
