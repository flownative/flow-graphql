<?php
declare(strict_types=1);
namespace Flownative\GraphQL;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Type\Schema;

interface EndpointInterface
{
    /**
     * Returns the path (ie. a part of the GraphQL API URL) which should
     * be handled by this endpoint.
     *
     * The path must be unique across all endpoints of one system.
     *
     * @return string Endpoint path, for example "/api/graphql"
     */
    public static function getPath(): string;

    /**
     * Returns the URI leading to the GraphQL schema for this endpoint.
     *
     * Currently only Flow's "resource://" URIs are supported.
     *
     * @return string Schema URI, for example "resource://My.Package/Private/GraphQL/schema.graphql"
     */
    public function getSchemaUri(): string;

    /**
     * Returns an optional type config decorator.
     *
     * Simplified example:
     *
     *   return static function ($typeConfig) {
     *       $typeConfig['resolveType'] = static fn($a) => 'CustomerAccount';
     *       return $typeConfig;
     *   };
     *
     * @return callable|null
     * @see https://webonyx.github.io/graphql-php/type-system/type-language/#defining-resolvers
     */
    public function getTypeConfigDecorator(): ?callable;

    /**
     * @param Schema $schema
     * @param array $input
     * @return ExecutionResult
     */
    public function executeQuery(Schema $schema, array $input): ExecutionResult;
}
