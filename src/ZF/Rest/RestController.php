<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2013 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\Rest;

use Zend\Http\Header\Allow;
use Zend\Http\Response;
use Zend\Mvc\Controller\AbstractRestfulController;
use Zend\Mvc\MvcEvent;
use Zend\Paginator\Paginator;
use ZF\ApiProblem\ApiProblem;
use ZF\ApiProblem\ApiProblemResponse;
use ZF\ApiProblem\Exception\DomainException;
use ZF\ContentNegotiation\ViewModel as ContentNegotiationViewModel;
use ZF\Hal\Collection as HalCollection;
use ZF\Hal\Resource as HalResource;
use ZF\Hal\View\HalJsonModel;

/**
 * Controller for handling resources.
 *
 * Extends the base AbstractRestfulController in order to provide very specific
 * semantics for building a RESTful JSON service. All operations return either
 *
 * - a HAL-compliant response with appropriate hypermedia links
 * - a Problem API-compliant response for reporting an error condition
 *
 * You may specify what specific HTTP method types you wish to respond to, and
 * OPTIONS will then report those; attempting any HTTP method falling outside
 * that list will result in a 405 (Method Not Allowed) response.
 *
 * I recommend using resource-specific factories when using this controller,
 * to allow injecting the specific resource you wish to use (and its listeners),
 * which will also allow you to have multiple instances of the controller when
 * desired.
 *
 * @see http://tools.ietf.org/html/draft-kelly-json-hal-03
 * @see http://tools.ietf.org/html/draft-nottingham-http-problem-02
 */
class RestController extends AbstractRestfulController
{
    /**
     * HTTP methods we allow for the resource (collection); used by options()
     *
     * HEAD and OPTIONS are always available.
     *
     * @var array
     */
    protected $collectionHttpMethods = array(
        'GET',
        'POST',
    );

    /**
     * Name of the collections entry in a Collection
     *
     * @var string
     */
    protected $collectionName = 'items';

    /**
     * Content types that will trigger marshalling data from the request body.
     *
     * @var array
     */
    protected $contentTypes = array(
        self::CONTENT_TYPE_JSON => array(
            'application/json',
            'application/hal+json',
        ),
    );

    /**
     * Number of resources to return per page.  If $pageSizeParameter is
     * specified, then it will override this when provided in a request.
     *
     * @var int
     */
    protected $pageSize = 30;

    /**
     * A query parameter to use to specify the number of records to return in
     * each collection page.  If not provided, $pageSize will be used as a
     * default value.
     *
     * Leave null to disable this functionality and always use $pageSize.
     *
     * @var string
     */
    protected $pageSizeParam;

    /**
     * @var ResourceInterface
     */
    protected $resource;

    /**
     * HTTP methods we allow for individual resources; used by options()
     *
     * HEAD and OPTIONS are always available.
     *
     * @var array
     */
    protected $resourceHttpMethods = array(
        'DELETE',
        'GET',
        'PATCH',
        'PUT',
    );

    /**
     * Route name that resolves to this resource; used to generate links.
     *
     * @var string
     */
    protected $route;

    /**
     * Constructor
     *
     * Allows you to set the event identifier, which can be useful to allow multiple
     * instances of this controller to react to different sets of shared events.
     *
     * @param  null|string $eventIdentifier
     */
    public function __construct($eventIdentifier = null)
    {
        if (null !== $eventIdentifier) {
            $this->eventIdentifier = $eventIdentifier;
        }
    }

    /**
     * Set the allowed HTTP methods for the resource (collection)
     *
     * @param  array $methods
     */
    public function setCollectionHttpMethods(array $methods)
    {
        $this->collectionHttpMethods = $methods;
    }

    /**
     * Set the name to which to assign a collection in a Collection
     *
     * @param  string $name
     */
    public function setCollectionName($name)
    {
        $this->collectionName = (string) $name;
    }

    /**
     * Set the allowed content types for the resource (collection)
     *
     * @param  array $contentTypes
     */
    public function setContentTypes(array $contentTypes)
    {
        $this->contentTypes = $contentTypes;
    }

    /**
     * Set the default page size for paginated responses
     *
     * @param  int
     */
    public function setPageSize($count)
    {
        $this->pageSize = (int) $count;
    }

    /**
     * Return the page size
     *
     * @return int
     */
    public function getPageSize()
    {
        return $this->pageSize;
    }

    /**
     * Set the page size parameter for paginated responses.
     *
     * @param string
     */
    public function setPageSizeParam($param)
    {
        $this->pageSizeParam = (string) $param;
    }

    /**
     * The true description of getIdentifierName is
     * a route identifier name.  This function corrects
     * this mistake for this controller.
     *
     * @return string
     */
    public function getRouteIdentifierName() {
        return $this->getIdentifierName();
    }

    /**
     * Inject the resource with which this controller will communicate.
     *
     * @param  ResourceInterface $resource
     */
    public function setResource(ResourceInterface $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Returns the resource
     *
     * @throws DomainException If no resource has been set
     *
     * @return ResourceInterface
     */
    public function getResource()
    {
        if ($this->resource === null) {
            throw new DomainException('No resource has been set.');
        }

        return $this->resource;
    }

    /**
     * Set the allowed HTTP OPTIONS for a resource
     *
     * @param  array $options
     */
    public function setResourceHttpMethods(array $methods)
    {
        $this->resourceHttpMethods = $methods;
    }

    /**
     * Inject the route name for this resource.
     *
     * @param  string $route
     */
    public function setRoute($route)
    {
        $this->route = $route;
    }

    /**
     * Handle the dispatch event
     *
     * Does several "pre-flight" checks:
     * - Raises an exception if no resource is composed.
     * - Raises an exception if no route is composed.
     * - Returns a 405 response if the current HTTP request method is not in
     *   $options
     *
     * When the dispatch is complete, it will check to see if an array was
     * returned; if so, it will cast it to a view model using the
     * AcceptableViewModelSelector plugin, and the $acceptCriteria property.
     *
     * @param  MvcEvent $e
     * @return mixed
     * @throws DomainException
     */
    public function onDispatch(MvcEvent $e)
    {
        if (!$this->getResource()) {
            throw new DomainException(sprintf(
                '%s requires that a %s\ResourceInterface object is composed; none provided',
                __CLASS__, __NAMESPACE__
            ));
        }

        if (!$this->route) {
            throw new DomainException(sprintf(
                '%s requires that a route name for the resource is composed; none provided',
                __CLASS__
            ));
        }

        // Check for an API-Problem in the event
        $return = $e->getParam('api-problem', false);

        // If no API-Problem, dispatch the parent event
        if (!$return) {
            $return = parent::onDispatch($e);
        }

        if (!$return instanceof ApiProblem
            && !$return instanceof HalResource
            && !$return instanceof HalCollection
        ) {
            return $return;
        }

        if ($return instanceof ApiProblem) {
            return new ApiProblemResponse($return);
        }

        // Set the fallback content negotiation to use HalJson.
        $e->setParam('ZFContentNegotiationFallback', 'HalJson');

        // Use content negotiation for creating the view model
        $viewModel = new ContentNegotiationViewModel(array('payload' => $return));
        $viewModel->setTerminal(true);
        $e->setResult($viewModel);
        return $viewModel;
    }

    /**
     * Create a new resource
     *
     * @param  array $data
     * @return Response|ApiProblem|HalResource
     */
    public function create($data)
    {
        if (!$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpMethods);
        }

        $events = $this->getEventManager();
        $events->trigger('create.pre', $this, array('data' => $data));

        try {
            $resource = $this->getResource()->create($data);
        } catch (\Exception $e) {
            return new ApiProblem($this->getHttpStatusCodeFromException($e), $e);
        }

        if ($resource instanceof ApiProblem
            || $resource instanceof ApiProblemResponse
        ) {
            return $resource;
        }

        $plugin   = $this->plugin('Hal');
        $resource = $plugin->createResource($resource, $this->route, $this->getRouteIdentifierName());

        if ($resource instanceof ApiProblem) {
            return $resource;
        }

        $self = $resource->getLinks()->get('self');
        $self = $plugin->fromLink($self);

        $response = $this->getResponse();
        $response->setStatusCode(201);
        $response->getHeaders()->addHeaderLine('Location', $self);

        $events->trigger('create.post', $this, array('data' => $data, 'resource' => $resource));

        return $resource;
    }

    /**
     * Delete an existing resource
     *
     * @param  int|string $id
     * @return Response|ApiProblem
     */
    public function delete($id)
    {
        if ($id && !$this->isMethodAllowedForResource()) {
            return $this->createMethodNotAllowedResponse($this->resourceHttpMethods);
        }
        if (!$id && !$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpMethods);
        }

        $events = $this->getEventManager();
        $events->trigger('delete.pre', $this, array('id' => $id));

        try {
            $result = $this->getResource()->delete($id);
        } catch (\Exception $e) {
            return new ApiProblem($this->getHttpStatusCodeFromException($e), $e);
        }

        $result = $result ?: new ApiProblem(422, 'Unable to delete resource.');

        if ($result instanceof ApiProblem
            || $result instanceof ApiProblemResponse
        ) {
            return $result;
        }

        $response = $this->getResponse();
        $response->setStatusCode(204);

        $events->trigger('delete.post', $this, array('id' => $id));

        return $response;
    }

    public function deleteList()
    {
        if (!$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpMethods);
        }

        $events = $this->getEventManager();
        $events->trigger('deleteList.pre', $this, array());

        try {
            $result = $this->getResource()->deleteList();
        } catch (\Exception $e) {
            return new ApiProblem($this->getHttpStatusCodeFromException($e), $e);
        }

        $result = $result ?: new ApiProblem(422, 'Unable to delete collection.');

        if ($result instanceof ApiProblem
            || $result instanceof ApiProblemResponse
        ) {
            return $result;
        }

        $response = $this->getResponse();
        $response->setStatusCode(204);

        $events->trigger('deleteList.post', $this, array());

        return $response;
    }

    /**
     * Return single resource
     *
     * @param  int|string $id
     * @return Response|ApiProblem|HalResource
     */
    public function get($id)
    {
        if (!$this->isMethodAllowedForResource()) {
            return $this->createMethodNotAllowedResponse($this->resourceHttpMethods);
        }

        $events = $this->getEventManager();
        $events->trigger('get.pre', $this, array('id' => $id));

        try {
            $resource = $this->getResource()->fetch($id);
        } catch (\Exception $e) {
            return new ApiProblem($this->getHttpStatusCodeFromException($e), $e);
        }

        $resource = $resource ?: new ApiProblem(404, 'Resource not found.');

        if ($resource instanceof ApiProblem
            || $resource instanceof ApiProblemResponse
        ) {
            return $resource;
        }

        $plugin   = $this->plugin('Hal');
        $resource = $plugin->createResource($resource, $this->route, $this->getRouteIdentifierName());

        if ($resource instanceof ApiProblem) {
            return $resource;
        }

        $events->trigger('get.post', $this, array('id' => $id, 'resource' => $resource));
        return $resource;
    }

    /**
     * Return collection of resources
     *
     * @return Response|HalCollection
     */
    public function getList()
    {
        if (!$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpMethods);
        }

        $events = $this->getEventManager();
        $events->trigger('getList.pre', $this, array());

        try {
            $collection = $this->getResource()->fetchAll();
        } catch (\Exception $e) {
            return new ApiProblem($this->getHttpStatusCodeFromException($e), $e);
        }

        if ($collection instanceof ApiProblem
            || $collection instanceof ApiProblemResponse
        ) {
            return $collection;
        }

        $pageSize = $this->pageSizeParam
            ? $this->getRequest()->getQuery($this->pageSizeParam, $this->pageSize)
            : $this->pageSize;

        $this->setPageSize($pageSize);

        $plugin     = $this->plugin('Hal');
        $collection = $plugin->createCollection($collection, $this->route);
        $collection->setCollectionRoute($this->route);
        $collection->setRouteIdentifierName($this->getRouteIdentifierName());
        $collection->setResourceRoute($this->route);
        $collection->setPage($this->getRequest()->getQuery('page', 1));
        $collection->setCollectionName($this->collectionName);
        $collection->setPageSize($this->getPageSize());

        $events->trigger('getList.post', $this, array('collection' => $collection));
        return $collection;
    }

    /**
     * Retrieve HEAD metadata for the resource and/or collection
     *
     * @param  null|mixed $id
     * @return Response|ApiProblem|HalResource|HalCollection
     */
    public function head($id = null)
    {
        if ($id) {
            return $this->get($id);
        }
        return $this->getList();
    }

    /**
     * Respond to OPTIONS request
     *
     * Uses $options to set the Allow header line and return an empty response.
     *
     * @return Response
     */
    public function options()
    {
        if (null === $id = $this->params()->fromRoute('id')) {
            $id = $this->params()->fromQuery('id');
        }

        if ($id) {
            $options = $this->resourceHttpMethods;
        } else {
            $options = $this->collectionHttpMethods;
        }

        $events = $this->getEventManager();
        $events->trigger('options.pre', $this, array('options' => $options));

        $response = $this->getResponse();
        $response->setStatusCode(204);
        $headers  = $response->getHeaders();
        $headers->addHeader($this->createAllowHeaderWithAllowedMethods($options));

        $events->trigger('options.post', $this, array('options' => $options));

        return $response;
    }

    /**
     * Respond to the PATCH method (partial update of existing resource)
     *
     * @param  int|string $id
     * @param  array $data
     * @return Response|ApiProblem|HalResource
     */
    public function patch($id, $data)
    {
        if (!$this->isMethodAllowedForResource()) {
            return $this->createMethodNotAllowedResponse($this->resourceHttpMethods);
        }

        $events = $this->getEventManager();
        $events->trigger('patch.pre', $this, array('id' => $id, 'data' => $data));

        try {
            $resource = $this->getResource()->patch($id, $data);
        } catch (\Exception $e) {
            return new ApiProblem($this->getHttpStatusCodeFromException($e), $e);
        }

        if ($resource instanceof ApiProblem
            || $resource instanceof ApiProblemResponse
        ) {
            return $resource;
        }

        $plugin   = $this->plugin('Hal');
        $resource = $plugin->createResource($resource, $this->route, $this->getRouteIdentifierName());

        if ($resource instanceof ApiProblem) {
            return $resource;
        }

        $events->trigger('patch.post', $this, array('id' => $id, 'data' => $data, 'resource' => $resource));
        return $resource;
    }

    /**
     * Update an existing resource
     *
     * @param  int|string $id
     * @param  array $data
     * @return Response|ApiProblem|HalResource
     */
    public function update($id, $data)
    {
        if ($id && !$this->isMethodAllowedForResource()) {
            return $this->createMethodNotAllowedResponse($this->resourceHttpMethods);
        }
        if (!$id && !$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpMethods);
        }

        $events = $this->getEventManager();
        $events->trigger('update.pre', $this, array('id' => $id, 'data' => $data));

        try {
            $resource = $this->getResource()->update($id, $data);
        } catch (\Exception $e) {
            return new ApiProblem($this->getHttpStatusCodeFromException($e), $e);
        }

        if ($resource instanceof ApiProblem
            || $resource instanceof ApiProblemResponse
        ) {
            return $resource;
        }

        $plugin   = $this->plugin('Hal');
        $resource = $plugin->createResource($resource, $this->route, $this->getRouteIdentifierName());

        $events->trigger('update.post', $this, array('id' => $id, 'data' => $data, 'resource' => $resource));
        return $resource;
    }

    /**
     * Respond to the PATCH method (partial update of existing resource) on
     * a collection, i.e. create and/or update multiple resources in a collection.
     *
     * @param array $data
     * @return array
     */
    public function patchList($data)
    {
        if (!$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpMethods);
        }

        $events = $this->getEventManager();
        $events->trigger('patchList.pre', $this, array('data' => $data));

        try {
            $collection = $this->getResource()->patchList($data);
        } catch (\Exception $e) {
            return new ApiProblem($this->getHttpStatusCodeFromException($e), $e);
        }

        if ($collection instanceof ApiProblem
            || $collection instanceof ApiProblemResponse
        ) {
            return $collection;
        }

        $plugin = $this->plugin('Hal');
        $collection = $plugin->createCollection($collection, $this->route);
        $collection->setCollectionRoute($this->route);
        $collection->setRouteIdentifierName($this->getRouteIdentifierName());
        $collection->setResourceRoute($this->route);
        $collection->setPage($this->getRequest()->getQuery('page', 1));
        $collection->setPageSize($this->getPageSize());
        $collection->setCollectionName($this->collectionName);

        $events->trigger('patchList.post', $this, array('data' => $data, 'collection' => $collection));
        return $collection;
    }

    /**
     * Update an existing collection of resources
     *
     * @param array $data
     * @return array
     */
    public function replaceList($data)
    {
        if (!$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpMethods);
        }

        $events = $this->getEventManager();
        $events->trigger('replaceList.pre', $this, array('data' => $data));

        try {
            $collection = $this->getResource()->replaceList($data);
        } catch (\Exception $e) {
            return new ApiProblem($this->getHttpStatusCodeFromException($e), $e);
        }

        if ($collection instanceof ApiProblem
            || $collection instanceof ApiProblemResponse
        ) {
            return $collection;
        }

        $plugin = $this->plugin('Hal');
        $collection = $plugin->createCollection($collection, $this->route);
        $collection->setCollectionRoute($this->route);
        $collection->setRouteIdentifierName($this->getRouteIdentifierName());
        $collection->setResourceRoute($this->route);
        $collection->setPage($this->getRequest()->getQuery('page', 1));
        $collection->setPageSize($this->getPageSize());
        $collection->setCollectionName($this->collectionName);

        $events->trigger('replaceList.post', $this, array('data' => $data, 'collection' => $collection));
        return $collection;
    }

    /**
     * Retrieve the identifier, if any
     *
     * Attempts to see if an identifier was passed in the URI,
     * returning it if found. Otherwise, returns a boolean false.
     *
     * @param  \Zend\Mvc\Router\RouteMatch $routeMatch
     * @param  \Zend\Http\Request $request
     * @return false|mixed
     */
    protected function getIdentifier($routeMatch, $request)
    {
        $identifier = $this->getIdentifierName();
        $id = $routeMatch->getParam($identifier, false);
        if ($id) {
            return $id;
        }

        return false;
    }

    /**
     * Is the current HTTP method allowed for a resource?
     *
     * @return bool
     */
    protected function isMethodAllowedForResource()
    {
        array_walk($this->resourceHttpMethods, function (&$method) {
            $method = strtoupper($method);
        });
        $options = array_merge($this->resourceHttpMethods, array('OPTIONS', 'HEAD'));
        $request = $this->getRequest();
        $method  = strtoupper($request->getMethod());
        if (!in_array($method, $options)) {
            return false;
        }
        return true;
    }

    /**
     * Is the current HTTP method allowed for the resource (collection)?
     *
     * @return bool
     */
    protected function isMethodAllowedForCollection()
    {
        array_walk($this->collectionHttpMethods, function (&$method) {
            $method = strtoupper($method);
        });
        $methods = array_merge($this->collectionHttpMethods, array('OPTIONS', 'HEAD'));
        $request = $this->getRequest();
        $method  = strtoupper($request->getMethod());
        if (!in_array($method, $methods)) {
            return false;
        }
        return true;
    }

    /**
     * Creates a "405 Method Not Allowed" response detailing the available methods
     *
     * @param  array $methods
     * @return Response
     */
    protected function createMethodNotAllowedResponse(array $methods)
    {
        $response = $this->getResponse();
        $response->setStatusCode(405);
        $headers = $response->getHeaders();
        $headers->addHeader($this->createAllowHeaderWithAllowedMethods($methods));
        return $response;
    }

    /**
     * Creates an ALLOW header with the provided HTTP methods
     *
     * @param  array $methods
     * @return Allow
     */
    protected function createAllowHeaderWithAllowedMethods(array $methods)
    {
        // Need to create an Allow header. It has several defaults, and the only
        // way to start with a clean slate is to retrieve all methods, disallow
        // them all, and then set the ones we want to allow.
        $allow      = new Allow();
        $allMethods = $allow->getAllMethods();
        $allow->disallowMethods(array_keys($allMethods));
        $allow->allowMethods($methods);
        return $allow;
    }

    /**
     * Ensure we have a valid HTTP status code for an ApiProblem
     *
     * @param \Exception $e
     * @return int
     */
    protected function getHttpStatusCodeFromException(\Exception $e)
    {
        $code = $e->getCode();
        if (!is_int($code)
            || $code < 100
            || $code >= 600
        ) {
            return 500;
        }
        return $code;
    }
}
