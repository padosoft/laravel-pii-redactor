<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\TokenStore;

final class TokenResolutionService
{
    private const TOKEN_PATTERN = '/\[tok:[A-Za-z0-9_]+:[0-9a-f]+\]/';

    public function __construct(private readonly TokenStore $store)
    {
        //
    }

    public function resolveToken(string $token): ?string
    {
        if (preg_match('/^'.substr(self::TOKEN_PATTERN, 1, -1).'$/', $token) !== 1) {
            return null;
        }

        return $this->store->get($token);
    }

    public function detokeniseString(string $text): DetokeniseResult
    {
        if ($text === '' || preg_match_all(self::TOKEN_PATTERN, $text, $matches) === false) {
            return new DetokeniseResult($text, 0, 0, []);
        }

        $tokens = array_values(array_unique($matches[0]));
        if ($tokens === []) {
            return new DetokeniseResult($text, 0, 0, []);
        }

        $map = [];
        $unresolved = [];
        foreach ($tokens as $token) {
            $original = $this->store->get($token);
            if ($original === null) {
                $unresolved[] = $token;

                continue;
            }

            $map[$token] = $original;
        }

        return new DetokeniseResult(
            output: $map === [] ? $text : strtr($text, $map),
            tokenCount: count($tokens),
            resolvedCount: count($map),
            unresolvedTokens: $unresolved,
        );
    }
}
