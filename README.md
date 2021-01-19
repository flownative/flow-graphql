[![MIT license](http://img.shields.io/badge/license-MIT-brightgreen.svg)](http://opensource.org/licenses/MIT)
[![Packagist](https://img.shields.io/packagist/v/flownative/graphql.svg)](https://packagist.org/packages/flownative/graphql)
![CI](https://github.com/flownative/flow-graphql/workflows/CI/badge.svg?branch=master)

# GraphQL Library for Neos Flow

This [Flow](https://flow.neos.io) package provides a minimal wrapper
around the [webonyx/graphql-php](https://github.com/webonyx/graphql-php)
package. Rather than providing a big range of features or automagic code
analysis, this library only solves the bare necessary tasks and gets not
into the way between your implementation and the original GraphQL
library.

## Feature Overview

tbd.

## Example

Here's a minimal Hello-World-example:

`Classes/GraphQL/QueryResolver.php`:
```php
<?php
declare(strict_types=1);
namespace Flownative\Example\GraphQL;

final class QueryResolver
{
    /**
     * @param $_
     * @param array $arguments
     * @return array
     */
    public function ping($_, array $arguments): array
    {
        return [
            'pong' => time()
        ];
    }
}
```

`Resources/Private/GraphQL/schema.graphql`:
```graphql
type Query {
    ping: String
}
```

`Classes/GraphQL/Endpoint.php`:
```php
<?php
declare(strict_types=1);
namespace Flownative\Example\GraphQL;

use Flownative\GraphQL\EndpointInterface;
use GraphQL\Executor\ExecutionResult;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;

final class Endpoint implements EndpointInterface
{
    /**
     * @var QueryResolver
     */
    protected $queryResolver;

    /**
     * @param QueryResolver $queryResolver
     */
    public function __construct(QueryResolver $queryResolver)
    {
        $this->queryResolver = $queryResolver;
    }

    /**
     * @return string
     */
    public static function getPath(): string
    {
        return '/api/graphql';
    }

    /**
     * @return string
     */
    public function getSchemaUri(): string
    {
        return 'resource://Flownative.Example/Private/GraphQL/schema.graphql';
    }

    /**
     * @param Schema $schema
     * @param array $input
     * @return ExecutionResult
     */
    public function executeQuery(Schema $schema, array $input): ExecutionResult
    {
        return GraphQL::executeQuery(
            $schema,
            $input['query'],
            $this->getRootValue(),
            null,
            $input['variables'] ?? null,
        );
    }

    /**
     * @return mixed
     */
    private function getRootValue(): array
    {
        $queryResolver = $this->queryResolver;
        return [
            'ping' => static function ($rootValue, array $args, $context) use ($queryResolver) {
                return $queryResolver->ping($rootValue, $args);
            }
        ];
    }

    /**
     * @return callable|null
     */
    public function getTypeConfigDecorator(): ?callable
    {
        return null;
    }
}

```

## Credits and Support

This library was developed by Robert Lemke / Flownative. Feel free to
suggest new features, report bugs or provide bug fixes in our Github
project.
