<?php
declare(strict_types=1);
namespace Flownative\GraphQL;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\SyntaxError;
use GraphQL\GraphQL;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use GuzzleHttp\Psr7\Response;
use Neos\Cache\Exception as CacheException;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations\Inject;
use Neos\Flow\Annotations\InjectConfiguration;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Utility\Files;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * An HTTP middleware which handles requests directed to GraphQL endpoints
 */
final class Middleware implements MiddlewareInterface
{
    /**
     * @InjectConfiguration
     * @var array
     */
    protected $settings;

    /**
     * @Inject
     * @var VariableFrontend
     */
    protected $schemaCache;

    /**
     * @Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestPath = $request->getUri()->getPath();
        foreach ($this->settings['endpoints'] as $endpointObjectName) {
            assert(in_array(EndpointInterface::class, class_implements($endpointObjectName), true));
            if ($requestPath === $endpointObjectName::getPath()) {
                break;
            }
            unset($endpointObjectName);
        }

        if (!isset($endpointObjectName) || !in_array($request->getMethod(), ['POST', 'OPTIONS'])) {
            return $handler->handle($request);
        }

        $endpoint = $this->objectManager->get($endpointObjectName);

        if ($request->getMethod() === 'OPTIONS') {
            return new Response(200, ['Allow' => 'GET, POST, OPTIONS', 'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS']);
        }

        try {
            $schema = $this->getSchema($endpoint);
        } catch (SyntaxError | CacheException $e) {
            return new Response(500, ['Content-Type' => 'application/json'], '{error:"Failed retrieving schema"}');
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            return new Response(400, ['Content-Type' => 'application/json'], '{error:"Failed decoding request body"}');
        }

        $result = GraphQL::executeQuery($schema, $input['query'], $endpoint->getRootValue(), null, $input['variables'] ?? null)
            ->toArray(self::parseDebugOptions($this->settings['debug']));

        try {
            $responseBody = \json_encode($result, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            return new Response(500, ['Content-Type' => 'application/json'], '{error:"Failed encoding response body"}');
        }

        return new Response(200, ['Content-Type' => 'application/json'], $responseBody);
    }

    /**
     * @param array $options
     * @return int
     */
    private static function parseDebugOptions(array $options): int
    {
        $flag = DebugFlag::NONE;
        if ($options['includeDebugMessage'] === true) {
            $flag |= DebugFlag::INCLUDE_DEBUG_MESSAGE;
        }
        if ($options['includeTrace'] === true) {
            $flag |= DebugFlag::INCLUDE_TRACE;
        }
        return $flag;
    }

    /**
     * @param EndpointInterface $endpoint
     * @return Schema
     * @throws CacheException
     * @throws SyntaxError
     */
    private function getSchema(EndpointInterface $endpoint): Schema
    {
        $schemaPathAndFilename = $endpoint->getSchemaUri();
        $cacheKey = sha1($schemaPathAndFilename);
        if ($this->schemaCache->has($cacheKey)) {
            $documentNode = $this->schemaCache->get($cacheKey);
        } else {
            $content = Files::getFileContents($schemaPathAndFilename);
            $documentNode = Parser::parse($content);
            $this->schemaCache->set($cacheKey, $documentNode);
        }

        return BuildSchema::build($documentNode, $endpoint->getTypeConfigDecorator());
    }
}
