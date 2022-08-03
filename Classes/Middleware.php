<?php
declare(strict_types=1);
namespace Flownative\GraphQL;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\Error;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\Parser;
use GraphQL\Server\Helper;
use GraphQL\Server\ServerConfig;
use GraphQL\Server\StandardServer;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\BuildSchema;
use GuzzleHttp\Psr7\Response;
use Neos\Cache\Exception as CacheException;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations\CompileStatic;
use Neos\Flow\Annotations\Inject;
use Neos\Flow\Annotations\InjectConfiguration;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Http\Factories\StreamFactory;
use Neos\Utility\Files;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;


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
     * @Inject
     * @var StreamFactory
     */
    protected $streamFactory;

    /**
     * @Inject(lazy=false)
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Inject
     * @var ThrowableStorageInterface
     */
    protected $throwableStorage;

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestPath = $request->getUri()->getPath();
        foreach (self::getEndpointImplementations($this->objectManager) as $endpointClassName) {
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

        $this->securityContext->setRequest(ActionRequest::fromHttpRequest($request));

        $endpoint = $this->objectManager->get($endpointClassName);
        assert($endpoint instanceof EndpointInterface);

        try {
            $schema = $this->getSchema($endpoint);
        } catch (SyntaxError|CacheException $e) {
            return new Response(500, ['Content-Type' => 'application/json'], sprintf('{error:"Failed processing schema from %s: %s"}', $endpoint->getSchemaUri(), $e->getMessage()));
        }

        $config = ServerConfig::create()
            ->setSchema($schema)
            ->setFieldResolver($endpoint)
            ->setDebugFlag(self::parseDebugOptions($this->settings['debug']));

        $server = new StandardServer($config);

        try {
            $request = $request->withParsedBody(json_decode($request->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            return new Response(400, ['Content-Type' => 'application/json'], '{error:"Failed decoding request body"}');
        }

        $bodyStream = $this->streamFactory->createStream();
        $helper = new Helper();

        $executionResult = $server->executePsrRequest($request);
        $executionResult->setErrorsHandler(
            function (array $errors, callable $formatter) {
                $error = reset($errors);
                if ($error instanceof Error) {
                    $previous = $error->getPrevious();
                    if ($previous instanceof \Throwable) {
                        $this->logger->error($this->throwableStorage->logThrowable($previous), LogEnvironment::fromMethodName(__METHOD__));
                    }
                }
                return array_map($formatter, $errors);
            }
        );
        $response = $helper->toPsrResponse($executionResult, new Response(), $bodyStream);

        $bodyStream->rewind();
        $graphqlResponseArray = json_decode($bodyStream->getContents(), true);
        if (isset($graphqlResponseArray['errors'])) {
            foreach ($graphqlResponseArray['errors'] as $error) {
                $locations = '';
                if (isset($error['locations'])) {
                    foreach ($error['locations'] as $location) {
                        $locations = "line {$location['line']} column {$location['column']}, ";
                    }
                    $locations = trim($locations, ', ');
                }
                $this->logger->notice(sprintf('GraphQL response contained errors: %s%s %s', $error['message'], $locations ? " ($locations)" : '', $error['debugMessage'] ?? ''));
            }
        }

        $bodyStream->rewind();
        return $response;
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
        if ($this->settings['enableSchemaCache'] === true) {
            $cacheKey = sha1($schemaPathAndFilename);
            if ($this->schemaCache->has($cacheKey)) {
                $documentNodeArray = $this->schemaCache->get($cacheKey);
                if ($documentNodeArray) {
                    $documentNode = AST::fromArray($documentNodeArray);
                }
            } else {
                $documentNode = Parser::parse(Files::getFileContents($schemaPathAndFilename));
                $this->schemaCache->set($cacheKey, $documentNode->toArray(true));
            }
        }

        if (!isset($documentNode)) {
            $documentNode = Parser::parse(Files::getFileContents($schemaPathAndFilename));
        }

        return BuildSchema::build($documentNode, $endpoint->getTypeConfigDecorator());
    }

    /**
     * @param ObjectManagerInterface $objectManager
     * @return array
     * @CompileStatic
     */
    protected static function getEndpointImplementations(ObjectManagerInterface $objectManager): array
    {
        $reflectionService = $objectManager->get(ReflectionService::class);
        return $reflectionService->getAllImplementationClassNamesForInterface(EndpointInterface::class);
    }
}
