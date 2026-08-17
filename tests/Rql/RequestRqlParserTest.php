<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Rql;

use CoolMS\CoreBundle\Rql\RequestRqlParser;
use CoolMS\Rql\FilterNode;
use CoolMS\Rql\FilterOp;
use CoolMS\Rql\RqlExpressionParser;
use CoolMS\Rql\RqlParser;
use CoolMS\Rql\SortDirection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The request bridge selects between the two RQL grammars. Any query
 * carrying a classic `key=` DSL param stays on {@see RqlParser}; a query that is
 * purely function-call terms routes to {@see RqlExpressionParser}.
 */
final class RequestRqlParserTest extends TestCase
{
    private RequestRqlParser $bridge;

    #[Test]
    public function classicFilterParamUsesTheDslParser(): void
    {
        $q = $this->bridge->parse('filter=foo eq 3');
        self::assertCount(1, $q->filters);
        $f = $q->filters[0];
        self::assertInstanceOf(FilterNode::class, $f);
        self::assertSame('foo', $f->field);
        self::assertSame(FilterOp::Eq, $f->op);
        self::assertSame(3, $f->value);
    }

    #[Test]
    public function phpArrayFilterParamUsesTheDslParser(): void
    {
        $q = $this->bridge->parse('filter[]=foo eq 3&filter[]=price lt 10');
        self::assertCount(2, $q->filters);
        self::assertContainsOnlyInstancesOf(FilterNode::class, $q->filters);
    }

    #[Test]
    public function bareFunctionCallTermsUseTheExpressionParser(): void
    {
        $q = $this->bridge->parse('eq(foo,3)&lt(price,10)');
        self::assertCount(2, $q->filters);

        $foo = $q->filters[0];
        assert($foo instanceof FilterNode);
        self::assertSame('foo', $foo->field);
        self::assertSame(3, $foo->value);

        $price = $q->filters[1];
        assert($price instanceof FilterNode);
        self::assertSame('price', $price->field);
        self::assertSame(FilterOp::Lt, $price->op);
        self::assertSame(10, $price->value);
    }

    #[Test]
    public function sortOnlyDslQueryStaysOnDslParser(): void
    {
        // No filter, but `sort=`/`limit=` are DSL params -> classic parser.
        $q = $this->bridge->parse('sort=-name&limit=20');
        self::assertCount(1, $q->sort);
        self::assertSame('name', $q->sort[0]->field);
        self::assertSame(SortDirection::Desc, $q->sort[0]->direction);
        self::assertSame(20, $q->limit);
    }

    #[Test]
    public function sortFunctionQueryUsesExpressionParser(): void
    {
        $q = $this->bridge->parse('sort(-name)&limit(20)');
        self::assertCount(1, $q->sort);
        self::assertSame('name', $q->sort[0]->field);
        self::assertSame(SortDirection::Desc, $q->sort[0]->direction);
        self::assertSame(20, $q->limit);
    }

    #[Test]
    public function emptyQueryIsANoOp(): void
    {
        $q = $this->bridge->parse('');
        self::assertSame([], $q->filters);
        self::assertSame([], $q->sort);
    }

    #[Test]
    public function nullRequestIsANoOp(): void
    {
        $q = $this->bridge->parseFromRequest(null);
        self::assertSame([], $q->filters);
    }

    #[Test]
    public function readsRawQueryStringFromRequestServerBag(): void
    {
        // Two repeated `filter=` keys would collapse under parse_str; the bridge
        // must read the raw QUERY_STRING so both AND clauses survive.
        $request = new Request(server: ['QUERY_STRING' => 'filter=a eq 1&filter=b eq 2']);
        $q = $this->bridge->parseFromRequest($request);
        self::assertCount(2, $q->filters);
    }

    protected function setUp(): void
    {
        $this->bridge = new RequestRqlParser(new RqlParser(), new RqlExpressionParser());
    }
}
