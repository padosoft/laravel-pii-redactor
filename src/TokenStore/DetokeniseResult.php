<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\TokenStore;

final class DetokeniseResult
{
    /**
     * @param  list<string>  $unresolvedTokens
     */
    public function __construct(
        public readonly string $output,
        public readonly int $tokenCount,
        public readonly int $resolvedCount,
        public readonly array $unresolvedTokens,
    ) {}

    /**
     * @return array{
     *     output: string,
     *     token_count: int,
     *     resolved_count: int,
     *     unresolved_tokens: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'output' => $this->output,
            'token_count' => $this->tokenCount,
            'resolved_count' => $this->resolvedCount,
            'unresolved_tokens' => $this->unresolvedTokens,
        ];
    }
}
