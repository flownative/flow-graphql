<?php
declare(strict_types=1);
namespace Flownative\GraphQL;

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
     * Returns the value provided as the first argument to resolver functions
     * on the top level type (e.g. the query object type).
     *
     * Example:
     *
     *   public function getRootValue()
     *   {
     *       $queryResolver = $this->queryResolver;
     *       return [
     *          'node' => static function ($rootValue, array $args, $context) use ($queryResolver) {
     *              return $queryResolver->node($rootValue, $args);
     *          },
     *          'customerAccounts' => static function ($rootValue, array $args, $context) use ($queryResolver) {
     *             return $queryResolver->customerAccounts($rootValue, $args);
     *            },
     *       ];
     *   }
     * @return mixed
     */
    public function getRootValue();
}
