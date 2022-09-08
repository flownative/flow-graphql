[![MIT license](http://img.shields.io/badge/license-MIT-brightgreen.svg)](http://opensource.org/licenses/MIT)
[![Packagist](https://img.shields.io/packagist/v/flownative/graphql.svg)](https://packagist.org/packages/flownative/graphql)
![CI](https://github.com/flownative/flow-graphql/workflows/CI/badge.svg?branch=main)

# GraphQL Library for Neos Flow

This [Flow](https://flow.neos.io) package provides a minimal wrapper
around the [webonyx/graphql-php](https://github.com/webonyx/graphql-php)
package. Rather than providing a big range of features or automagic code
analysis, this library only solves the bare necessary tasks and gets not
into the way between your implementation and the original GraphQL
library.

## Example

Here's a Hello-World-example which also contains support for automatic 
resolution of further queries and mutations provided in the Api class: 

`Classes/GraphQL/Api.php`:
```php
<?php
declare(strict_types=1);
namespace Flownative\Example\GraphQL;

final class Api
{
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
use GraphQL\Type\Schema;use Neos\Eel\FlowQuery\OperationResolver;

final class Endpoint implements EndpointInterface
{
    public function __construct(readonly private Api $api)
    {
    }

    public static function getPath(): string
    {
        return '/api/graphql';
    }

    public function getSchemaUri(): string
    {
        return 'resource://Flownative.Example/Private/GraphQL/schema.graphql';
    }

    public function __invoke($objectValue, $args, $_, ResolveInfo $resolveInfo): mixed
    {
        $fieldName = $resolveInfo->fieldName;
        if ($objectValue === null && method_exists($this->api, $fieldName)) {
            $result = $this->api->$fieldName($objectValue, $args);
        } elseif (is_array($objectValue)) {
            $result = $objectValue[$fieldName] ?? null;
        } elseif ($objectValue !== null && method_exists($objectValue, $fieldName)) {
            $result = $objectValue->$fieldName();
        } elseif ($objectValue !== null && method_exists($objectValue, 'get' . ucfirst($fieldName))) {
            $methodName = 'get' . ucfirst($fieldName);
            $result = $objectValue->$methodName($objectValue);
        } elseif ($objectValue !== null && method_exists($objectValue, $fieldName . 'Query')) {
            $methodName = $fieldName . 'Query';
            $query = $objectValue->$methodName();
            if (!$query instanceof Query) {
                throw new RuntimeException(sprintf('Failed to resolve field "%s": %s->%s() returned %s, but expected %s', $fieldName, get_class($objectValue), $methodName, get_debug_type($query), Query::class), 1648713012);
            }
            if (!method_exists($this->api, $query->queryName)) {
                throw new RuntimeException(sprintf('Failed to resolve field "%s": %s->%s() returned %s, but %s->%s() does not exist', $fieldName, get_class($objectValue), $methodName, $query->queryName, get_class($this->api), $query->queryName), 1648713106);
            }
            $result = $this->api->{$query->queryName}(null, $query->arguments);
        } elseif ($objectValue !== null && property_exists($objectValue, $fieldName)) {
            $result = $objectValue->{$fieldName};
        } else {
            throw new RuntimeException(sprintf('Failed to resolve field "%s" on subject %s', $fieldName, get_debug_type($objectValue)), 1613477425);
        }

        if ($result instanceof DateTimeInterface) {
            $result = $result->format(DATE_ISO8601);
        }

        return $result;
    }

    public function getTypeConfigDecorator(): ?callable
    {
        return static function ($typeConfig) {
            $typeConfig['resolveType'] = static function ($object) {
                if (method_exists($object, 'isOfType')) {
                    return $object->isOfType();
                }
                $classname = is_object($object) ? get_class($object) : '';
                if ($position = strrpos($classname, '\\')) {
                    return substr($classname, $position + 1);
                }
                return $position;
            };
            return $typeConfig;
        };
    }
}

```

## Credits and Support

This library was developed by Robert Lemke / Flownative. Feel free to
suggest new features, report bugs or provide bug fixes in our GitHub
project.
