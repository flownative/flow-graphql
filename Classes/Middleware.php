<?php
declare(strict_types=1);
namespace Flownative\GraphQL;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use GuzzleHttp\Psr7\Response;
use Neos\Cache\Exception as CacheException;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations\CompileStatic;
use Neos\Flow\Annotations\Inject;
use Neos\Flow\Annotations\InjectConfiguration;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
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
        foreach ($this->getEndpointImplementations($this->objectManager) as $endpointClassName) {
            if ($requestPath === $endpointClassName::getPath()) {
                break;
            }
            unset($endpointClassName);
        }

        if (!isset($endpointClassName) || !in_array($request->getMethod(), ['POST', 'OPTIONS'])) {
            return $handler->handle($request);
        }

        if ($request->getMethod() === 'OPTIONS') {
            return new Response(200, ['Allow' => 'GET, POST, OPTIONS', 'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS']);
        }

        $endpoint = $this->objectManager->get($endpointClassName);
        assert($endpoint instanceof EndpointInterface);

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

        try {
            $responseBody = \json_encode($endpoint->executeQuery($schema, $input)->toArray(self::parseDebugOptions($this->settings['debug'])),JSON_THROW_ON_ERROR);
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

    /**
     * @param ObjectManagerInterface $objectManager
     * @return array
     * @CompileStatic
     */
    private function getEndpointImplementations(ObjectManagerInterface $objectManager): array
    {
        $reflectionService = $objectManager->get(ReflectionService::class);
        return $reflectionService->getAllImplementationClassNamesForInterface(EndpointInterface::class);
    }
}
