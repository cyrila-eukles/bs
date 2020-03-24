<?php
/**
 * Created by PhpStorm.
 * User: steve
 * Date: 17/10/16
 * Time: 16:45
 */

namespace Eukles\Entity;

use Eukles\Action\ActionInterface;
use Eukles\Container\ContainerInterface;
use Eukles\Container\ContainerTrait;
use Eukles\Util\PksFinder;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Exception\PropelException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Http\Response;

class EntityFactory implements EntityFactoryInterface
{

    use ContainerTrait;

    /**
     * EntityFactoryInterface constructor.
     *
     * @param ContainerInterface $c
     */
    public function __construct(ContainerInterface $c)
    {
        $this->container = $c;
    }

    /**
     * Create a new instance of activeRecord and add it to Request attributes
     *
     * @param EntityFactoryConfig    $config
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     * @param callable               $next
     *
     * @return ResponseInterface
     */
    public function create(
        EntityFactoryConfig $config,
        ServerRequestInterface $request,
        ResponseInterface $response,
        callable $next
    ): ResponseInterface {
        $entityRequest = $config->createEntityRequest($request, $this->container);

        # make a new empty record
        $obj = $entityRequest->instantiateActiveRecord();

        # Execute beforeCreate hook, which can alter record
        $entityRequest->beforeCreate($obj, $request);

        # Then, alter object with allowed properties
        if ($config->isHydrateEntityFromRequest()) {
            $requestParams = $request->getQueryParams();
            $postParams    = $request->getParsedBody();
            if ($postParams) {
                $requestParams = array_merge($requestParams, (array)$postParams);
            }

            /** @noinspection PhpUndefinedMethodInspection */
            $obj->fromArray($entityRequest->getAllowedDataFromRequest($requestParams, $request->getMethod()));
        }

        # Execute afterCreate hook, which can alter record
        $entityRequest->afterCreate($obj, $request);

        $request = $request->withAttribute($config->getParameterToInjectInto(), $obj);
        /** @var Response $response */
        return $next($request, $response);
    }

    /**
     * Fetch an existing instance of activeRecord and add it to Request attributes
     *
     * @param EntityFactoryConfig    $config
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     * @param callable               $next
     *
     * @return ResponseInterface
     */
    public function fetch(
        EntityFactoryConfig $config,
        ServerRequestInterface $request,
        ResponseInterface $response,
        callable $next
    ): ResponseInterface {
        $entityRequest = $config->createEntityRequest($request, $this->container);

        # First, we try to determine PK in request path (most common case)
        if (isset($request->getAttribute('routeInfo')[2][$config->getRequestParameterName()])) {
            $entityRequest->setPrimaryKey($request->getAttribute('routeInfo')[2][$config->getRequestParameterName()]);
        }

        # Next, we create the query (ModelCriteria), based on Action class (which can alter the query)
        $query = $this->getQueryFromActiveRecordRequest($entityRequest);

        # Execute beforeFetch hook, which can enforce primary key
        $query = $entityRequest->beforeFetch($query, $request);

        # Now get the primary key in its final form
        $pk = $entityRequest->getPrimaryKey();
        if (null === $pk) {
            $handler = $entityRequest->getContainer()->getEntityRequestErrorHandler();

            return $handler->primaryKeyNotFound($entityRequest, $request,
                $response);
        }

        # Then, fetch object
        $obj = $query->findPk($pk);

        if ($obj === null) {
            $handler = $entityRequest->getContainer()->getEntityRequestErrorHandler();

            return $handler->entityNotFound($entityRequest, $request, $response);
        }

        # Get request params
        if ($config->isHydrateEntityFromRequest()) {
            $params     = $request->getQueryParams();
            $postParams = $request->getParsedBody();
            if ($postParams) {
                $params = array_merge($params, (array)$postParams);
            }

            # Then, alter object with allowed properties
            $obj->fromArray($entityRequest->getAllowedDataFromRequest($params, $request->getMethod()));
        }

        # Then, execute afterFetch hook, which can alter the object
        $entityRequest->afterFetch($obj, $request);

        $request = $request->withAttribute($config->getParameterToInjectInto(), $obj);

        return $next($request, $response);
    }

    /**
     * Fetch an existing collection of activeRecords and add it to Request attributes
     *
     * @param EntityFactoryConfig $config
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param callable $next
     * @return ResponseInterface
     * @throws PropelException
     */
    public function fetchCollection(
        EntityFactoryConfig $config,
        ServerRequestInterface $request,
        ResponseInterface $response,
        callable $next
    ): ResponseInterface {

        $entityRequest = $config->createEntityRequest($request, $this->container);

        $params = $request->getQueryParams();
        $pkName = $config->getRequestParameterName();
        $pks = [];

        if (array_key_exists($pkName, $params)) {
            $pks = $params[$pkName];
        }
        if(is_string($pks)){
            $pks = json_decode($pks, true);
        }
       if(!$pks && in_array($request->getMethod(), ['POST', 'PATCH', 'PUT', 'DELETE'])){
            # POST/PATCH : Try to find PKs in body
            if (is_array($request->getParsedBody())) {
                $finder = new PksFinder([$pkName]);
                $pks    = $finder->find($request->getParsedBody());
            }
        }

        $entityRequest->setPrimaryKey($pks);

        # Next, we create the query (ModelCriteria), based on Action class (which can alter the query)
        $query = $this->getQueryFromActiveRecordRequest($entityRequest);

        # Execute beforeFetch hook, which can enforce primary key
        $query = $entityRequest->beforeFetch($query, $request);

        # Now get the primary key in its final form
        $pk = $entityRequest->getPrimaryKey();
        if (null === $pk) {
            $handler = $entityRequest->getContainer()->getEntityRequestErrorHandler();

            return $handler->primaryKeyNotFound($entityRequest, $request,
                $response);
        }

        # Then, fetch object
        /** @var ObjectCollection $col */
        $col = $query->findPks($pks);

        # Get request params
        if ($config->isHydrateEntityFromRequest()) {
            //TODO
            throw new \RuntimeException('Collection hydration is not supported yet.');
            //            $params     = $request->getQueryParams();
            //            $postParams = $request->getParsedBody();
            //            if ($postParams) {
            //                $params = array_merge($params, (array)$postParams);
            //            }
            //
            //            # Then, alter object with allowed properties
            //            $col->fromArray($entityRequest->getAllowedDataFromRequest($params, $request->getMethod()));
        }

        # Then, execute afterFetch hook, which can alter the object
        //        $entityRequest->afterFetchCollection($col, $request);

        $request = $request->withAttribute($config->getParameterToInjectInto(), $col);

        return $next($request, $response);
    }

    /**
     * Create the query (ModelCriteria), based on Action class (which can alter the query)
     *
     * @param EntityRequestInterface $activeRecordRequest
     *
     * @return ModelCriteria
     */
    private function getQueryFromActiveRecordRequest(
        EntityRequestInterface $activeRecordRequest
    ) {
        $actionClass = $activeRecordRequest->getActionClassName();
        /** @var ActionInterface $action */
        $action = $actionClass::create($activeRecordRequest->getContainer());

        return $action->createQuery();
    }
}
