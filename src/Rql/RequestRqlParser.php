<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Rql;

use CoolMS\Rql\RqlExpressionParser;
use CoolMS\Rql\RqlParser;
use CoolMS\Rql\RqlQuery;
use Symfony\Component\HttpFoundation\Request;

/**
 * Bridges an HTTP Request to whichever RQL front-end parser the query string
 * calls for, then hands the caller the single shared {@see RqlQuery} AST.
 *
 * Reads QUERY_STRING from the server bag rather than Request::getQueryString(),
 * because the latter normalises through parse_str -- repeated scalar keys
 * (e.g. filter=...&filter=...) lose all but the last value, silently dropping
 * multi-clause AND filters that the grid emits for range inputs.
 *
 * Two grammars share one AST:
 *   - classic DSL  : `filter=field op value` / `filter[]=...` / sort/page/limit
 *   - Persvr RQL   : `eq(field,3)&lt(price,10)&sort(-name)&limit(20)`
 * Selection is back-compat-safe: any query carrying a recognised DSL `key=`
 * param stays on {@see RqlParser}; only a query that is PURELY function-call
 * terms routes to {@see RqlExpressionParser}.
 */
final readonly class RequestRqlParser
{
    public function __construct(
        private RqlParser $parser,
        private RqlExpressionParser $expressionParser,
    ) {
    }

    public function parseFromRequest(?Request $request): RqlQuery
    {
        if (null === $request) {
            return $this->parser->parse('');
        }

        return $this->parse((string) $request->server->get('QUERY_STRING', ''));
    }

    public function parse(string $queryString): RqlQuery
    {
        return $this->isExpressionSyntax($queryString)
            ? $this->expressionParser->parse($queryString)
            : $this->parser->parse($queryString);
    }

    /**
     * True only when the query is the Persvr function-call grammar: it carries
     * NO classic DSL `key=` param (filter/filter[]/sort/page/limit) AND has at
     * least one `name(...)` term. This preserves every existing `filter=...`
     * request on the original parser and routes only the new syntax onward.
     */
    private function isExpressionSyntax(string $queryString): bool
    {
        if ('' === trim($queryString)) {
            return false;
        }
        // Any recognised DSL param present (incl. filter[] / URL-encoded %5B%5D).
        if (1 === preg_match('/(?:^|&)(?:filter(?:\[\]|%5B%5D)?|sort|page|limit)=/', $queryString)) {
            return false;
        }

        // At least one top-level function-call term → Persvr grammar.
        return 1 === preg_match('/(?:^|&)\s*[a-zA-Z_][a-zA-Z0-9_]*\(/', $queryString);
    }
}
