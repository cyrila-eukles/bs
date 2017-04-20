<?php
namespace Eukles\Slim\Handlers\Strategies;

use Eukles\Action;
use Eukles\Container\ContainerInterface;
use Eukles\Service\Pagination\PaginationInterface;
use Eukles\Service\QueryModifier\QueryModifierInterface;
use Eukles\Service\ResponseBuilder\ResponseBuilderException;
use Eukles\Service\ResponseFormatter\ResponseFormatterException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Http\Request;
use Slim\Interfaces\InvocationStrategyInterface;

/**
 * Class RequestResponseHandler
 *
 * @package Eukles\Slim\Handlers\Strategies
 */
class ActionStrategy implements InvocationStrategyInterface
{
    
    protected $container;
    
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }
    
    /**
     * Invoke a route callable with request, response and all route parameters
     * as individual arguments.
     *
     * @param array|callable                 $callable
     * @param ServerRequestInterface|Request $request
     * @param ResponseInterface              $response
     * @param array                          $routeArguments
     *
     * @return mixed
     */
    public function __invoke(
        callable $callable,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $routeArguments
    ) {
        
        return $this->callHandler($callable, $request, $response, $routeArguments);
    }
    
    /**
     * Call a method in an Action class
     *
     * May be overriden to add some logic before or after call
     *
     * @param callable               $callable
     * @param ServerRequestInterface $request
     * @param array                  $routeArguments
     *
     * @return mixed
     */
    public function callAction(callable $callable, ServerRequestInterface $request, array $routeArguments)
    {
        return call_user_func_array($callable, $this->buildParams($callable, $request, $routeArguments));
    }
    
    /**
     * Build list of parameters needed by Action::method
     *
     * @param callable|array                 $callable
     * @param ServerRequestInterface|Request $request
     * @param                                $routeArguments
     *
     * @return array
     */
    private function buildParams(
        callable $callable,
        ServerRequestInterface $request,
        $routeArguments
    ) {
        
        if (is_array($callable) === false) {
            return [];
        }
        
        $r            = new \ReflectionClass($callable[0]);
        $m            = $r->getMethod($callable[1]);
        $paramsMethod = $m->getParameters();
        
        if (empty($paramsMethod)) {
            return [];
        }
        
        $requestParams = $request->getParams();
        $buildParams   = [];
        
        /** @var \ReflectionParameter[] $params */
        foreach ($paramsMethod as $param) {
            $name  = $param->getName();
            $class = $param->getClass();
            if (null !== $class) {
                if (($p = $request->getAttribute($name)) !== null) {
                    $buildParams[] = $p;
                } elseif ($class->implementsInterface(QueryModifierInterface::class)) {
                    $buildParams[] = $this->container->getRequestQueryModifier();
                } elseif ($class->implementsInterface(PaginationInterface::class)) {
                    $buildParams[] = $this->container->getRequestPagination();
                } elseif ($class->implementsInterface(UploadedFileInterface::class)) {
                    $files = $request->getUploadedFiles();
                    $files = array_values($files);
                    /** @var UploadedFileInterface $attachment */
                    $buildParams[] = $files[0];
                } else {
                    throw new \InvalidArgumentException(
                        "Missing or null required parameter '{$name}' in " . $r->getName() . "::" . $m->getName()
                    );
                }
            } else {
                
                if (isset($routeArguments[$name])) {
                    $buildParams[] = $routeArguments[$name];
                } elseif (isset($requestParams[$name])) {
                    $buildParams[] = $requestParams[$name];
                } elseif (($p = $request->getAttribute($name)) !== null) {
                    $buildParams[] = $p;
                } elseif ($param->isDefaultValueAvailable()) {
                    $buildParams[] = $param->getDefaultValue();
                } else {
                    throw new \InvalidArgumentException(
                        "Missing or null required parameter '{$name}' in " . $r->getName() . "::" . $m->getName()
                    );
                }
            }
        }
        
        return $buildParams;
    }
    
    /**
     * Build a string response
     *
     * @param mixed                               $result
     * @param \Psr\Http\Message\ResponseInterface $response
     *
     * @return ResponseInterface
     * @throws \Exception
     */
    private function buildResponse($result, ResponseInterface $response)
    {
        
        $responseBuilder   = $this->container->getResponseBuilder();
        $responseFormatter = $this->container->getResponseFormatter();
        
        if (!is_callable($responseBuilder)) {
            throw new ResponseBuilderException('ResponseBuilder must be callable or implements ResponseBuilderInterface');
        }
        if (!is_callable($responseFormatter)) {
            throw new ResponseFormatterException('ResponseFormatter must be callable or implements ResponseFormatterInterface');
        }
        
        $result = $responseBuilder($result);
        
        return $responseFormatter($response, $result);
    }
    
    /**
     * Call action with built params
     *
     * @param callable                       $callable
     * @param ServerRequestInterface|Request $request
     * @param ResponseInterface              $response
     * @param array                          $routeArguments
     *
     * @return mixed
     * @throws ResponseBuilderException
     * @throws ResponseFormatterException
     */
    private function callHandler(
        callable $callable,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $routeArguments
    ) {
        # Action is a closure
        if ($callable instanceof \Closure) {
            array_unshift($routeArguments, $request, $response);
            $result = call_user_func_array($callable, $routeArguments);
        } else {
            # Action is a method of an Action class
            if (is_array($callable) && $callable[0] instanceof Action\ActionInterface) {
                $callable[0]->setRequest($request);
                $callable[0]->setResponse($response);
                // TODO pagination From Request
            }
            
            # Call Action method
            $result   = $this->callAction($callable, $request, $routeArguments);
            $response = $callable[0]->getResponse();
        }
        if (($result instanceof ResponseInterface)) {
            $response = $result;
        } else {
            $response = $this->buildResponse($result, $response);
        }
        
        return $response;
    }
}