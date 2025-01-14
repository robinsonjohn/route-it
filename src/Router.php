<?php

namespace Bayfront\RouteIt;

use Bayfront\ArrayHelpers\Arr;
use Bayfront\HttpRequest\Request;
use Bayfront\HttpResponse\InvalidStatusCodeException;
use Bayfront\HttpResponse\Response;

class Router
{

    protected Response $response;

    protected array $options;

    /**
     * Router constructor
     *
     * @param array $options
     */

    public function __construct(array $options)
    {

        $this->response = new Response();

        $default_options = [
            'automapping_enabled' => false,
            'automapping_namespace' => '',
            'automapping_route_prefix' => '',
            'class_namespace' => '',
            'files_root_path' => '',
            'force_lowercase_url' => false
        ];

        // Overwrite default option values when defined

        $this->options = Arr::only(array_merge($default_options, $options), [
            'automapping_enabled',
            'automapping_namespace',
            'automapping_route_prefix',
            'class_namespace',
            'files_root_path',
            'force_lowercase_url']);

        // Sanitize paths

        $this->options['automapping_route_prefix'] = $this->_sanitizePath($this->options['automapping_route_prefix']);

        $this->options['files_root_path'] = $this->_sanitizePath($this->options['files_root_path'], false);

    }

    const METHOD_ANY = 'ANY';

    protected string $host = '';

    protected string $route_prefix = '';

    protected array $routes = [];

    protected array $redirects = [];

    protected array $fallbacks = [];

    /**
     * Ensures consistent path syntax
     *
     * Returned paths will always start with and never end with a forward slash
     *
     * @param string $path
     * @param bool $lowercase
     *
     * @return string
     */

    protected function _sanitizePath(string $path, bool $lowercase = true): string
    {

        if ($path === '/' || $path === '') {
            return '';
        }

        if (true === $lowercase) {
            return '/' . strtolower(trim($path, '/'));
        }

        return '/' . trim($path, '/');

    }

    /**
     * Returns a valid request method, including "ANY".
     *
     * @param string $method
     *
     * @return string
     */

    protected function _validateMethod(string $method): string
    {

        if (strtoupper($method) == self::METHOD_ANY) {
            return self::METHOD_ANY;
        }

        return Request::validateMethod($method);

    }

    /**
     * Sets the hostname for defined routes
     *
     * @param string $host
     *
     * @return self
     */

    public function setHost(string $host): self
    {
        $this->host = strtolower(trim($host, '/'));

        return $this;
    }

    /**
     * Retrieves the hostname for defined routes
     *
     * @return string
     */

    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Sets the route prefix for defined routes
     *
     * @param string $prefix
     *
     * @return self
     */

    public function setRoutePrefix(string $prefix): self
    {

        if ($prefix === '/') {

            $this->route_prefix = '';

        } else {

            $this->route_prefix = $this->_sanitizePath($prefix);

        }

        return $this;

    }

    /**
     * Retrieves the route prefix for defined routes
     *
     * @return string
     */

    public function getRoutePrefix(): string
    {
        return $this->route_prefix;
    }

    /**
     * Adds a fallback destination for given request method(s) when no route can be found.
     *
     * The response will be sent with a 404 HTTP status code.
     *
     * @param array|string $methods (Request method(s) for which this fallback is valid, or "ANY")
     * @param mixed $destination
     * @param array $params (Parameters to pass to the destination)
     *
     * @return self
     */

    public function addFallback(array|string $methods, mixed $destination, array $params = []): self
    {

        foreach ((array)$methods as $method) {

            $this->fallbacks[$this->_validateMethod($method)] = [
                'destination' => $destination,
                'params' => $params
            ];

        }

        return $this;

    }

    /**
     * Returns array of defined fallbacks
     *
     * @return array
     */

    public function getFallbacks(): array
    {

        ksort($this->fallbacks);

        return $this->fallbacks;

    }

    /**
     * Adds a redirect. Wildcards can be used in the path.
     *
     * @param array|string $methods (Request method(s) for which this redirect is valid, or "ANY")
     * @param string $path (Request path)
     * @param string $destination (Can be an internal path or fully qualified URL)
     * @param int $status (HTTP status code used with the redirect)
     *
     * @return self
     */

    public function addRedirect(array|string $methods, string $path, string $destination, int $status = 302): self
    {

        foreach ((array)$methods as $method) {

            if (!filter_var($destination, FILTER_VALIDATE_URL)) { // If an internal redirect

                $destination = Request::getRequest(Request::PART_PROTOCOL) . $this->getHost() . $this->getRoutePrefix() . $this->_sanitizePath($destination);

            }

            $this->redirects[$this->_validateMethod($method)][$this->getHost()][$this->getRoutePrefix() . $this->_sanitizePath($path)] = [
                'destination' => $destination,
                'status' => $status
            ];

        }

        return $this;

    }

    /**
     * Returns array of defined redirects
     *
     * @return array
     */

    public function getRedirects(): array
    {

        ksort($this->redirects);

        return $this->redirects;

    }

    /**
     * Adds a defined route. Wildcards can be used in the path.
     *
     * Destinations can be a callable function, a named route,  a file, or a `$class->method()`.
     * Each route can have its own predefined parameter(s), and parameters can also be defined dynamically by a
     * "wildcard".
     *
     * NOTE: Names should not be assigned to routes which include wildcards in the path. Named routes are only
     * intended to define specific URLs. Named route names must be unique, as names which already exist are
     * overwritten.
     *
     * @param array|string $methods (Request method(s) for which this route is valid, or "ANY")
     * @param string $path (Request path)
     * @param mixed $destination
     * @param array $params (Parameters to pass to the destination)
     * @param string|null $name (An optional name to assign to this route)
     *
     * @return self
     */

    public function addRoute(array|string $methods, string $path, mixed $destination, array $params = [], ?string $name = NULL): self
    {

        foreach ((array)$methods as $method) {

            $route = [
                'destination' => $destination,
                'params' => $params
            ];

            if (NULL !== $name) {
                $route['name'] = $name;
            }

            $this->routes[$this->_validateMethod($method)][$this->getHost()][$this->getRoutePrefix() . $this->_sanitizePath($path)] = $route;

        }

        return $this;

    }

    /**
     * Adds route for ANY request method
     *
     * @param string $path
     * @param mixed $destination
     * @param array $params
     * @param string|null $name
     *
     * @return self
     */

    public function any(string $path, mixed $destination, array $params = [], ?string $name = NULL): self
    {
        return $this->addRoute(self::METHOD_ANY, $path, $destination, $params, $name);
    }

    /**
     * Adds route for the CONNECT request method
     *
     * @param string $path
     * @param mixed $destination
     * @param array $params
     * @param string|null $name
     *
     * @return self
     */

    public function connect(string $path, mixed $destination, array $params = [], ?string $name = NULL): self
    {
        return $this->addRoute(Request::METHOD_CONNECT, $path, $destination, $params, $name);
    }

    /**
     * Adds route for the DELETE request method
     *
     * @param string $path
     * @param mixed $destination
     * @param array $params
     * @param string|null $name
     *
     * @return self
     */

    public function delete(string $path, mixed $destination, array $params = [], ?string $name = NULL): self
    {
        return $this->addRoute(Request::METHOD_DELETE, $path, $destination, $params, $name);
    }

    /**
     * Adds route for the GET request method
     *
     * @param string $path
     * @param mixed $destination
     * @param array $params
     * @param string|null $name
     *
     * @return self
     */

    public function get(string $path, mixed $destination, array $params = [], ?string $name = NULL): self
    {
        return $this->addRoute(Request::METHOD_GET, $path, $destination, $params, $name);
    }

    /**
     * Adds route for the HEAD request method
     *
     * @param string $path
     * @param mixed $destination
     * @param array $params
     * @param string|null $name
     *
     * @return self
     */

    public function head(string $path, mixed $destination, array $params = [], ?string $name = NULL): self
    {
        return $this->addRoute(Request::METHOD_HEAD, $path, $destination, $params, $name);
    }

    /**
     * Adds route for the OPTIONS request method
     *
     * @param string $path
     * @param mixed $destination
     * @param array $params
     * @param string|null $name
     *
     * @return self
     */

    public function options(string $path, mixed $destination, array $params = [], ?string $name = NULL): self
    {
        return $this->addRoute(Request::METHOD_OPTIONS, $path, $destination, $params, $name);
    }

    /**
     * Adds route for the PATCH request method
     *
     * @param string $path
     * @param mixed $destination
     * @param array $params
     * @param string|null $name
     *
     * @return self
     */

    public function patch(string $path, mixed $destination, array $params = [], ?string $name = NULL): self
    {
        return $this->addRoute(Request::METHOD_PATCH, $path, $destination, $params, $name);
    }

    /**
     * Adds route for the POST request method
     *
     * @param string $path
     * @param mixed $destination
     * @param array $params
     * @param string|null $name
     *
     * @return self
     */

    public function post(string $path, mixed $destination, array $params = [], ?string $name = NULL): self
    {
        return $this->addRoute(Request::METHOD_POST, $path, $destination, $params, $name);
    }

    /**
     * Adds route for the PUT request method
     *
     * @param string $path
     * @param mixed $destination
     * @param array $params
     * @param string|null $name
     *
     * @return self
     */

    public function put(string $path, mixed $destination, array $params = [], ?string $name = NULL): self
    {
        return $this->addRoute(Request::METHOD_PUT, $path, $destination, $params, $name);
    }

    /**
     * Adds route for the TRACE request method
     *
     * @param string $path
     * @param mixed $destination
     * @param array $params
     * @param string|null $name
     *
     * @return self
     */

    public function trace(string $path, mixed $destination, array $params = [], ?string $name = NULL): self
    {
        return $this->addRoute(Request::METHOD_TRACE, $path, $destination, $params, $name);
    }

    /**
     * Returns array of defined routes
     *
     * @return array
     */

    public function getRoutes(): array
    {

        ksort($this->routes);

        return $this->routes;

    }

    /**
     * Adds a specific path as a named route
     *
     * This is helpful when wanting to reference a URL that is not defined as a route.
     *
     * Named routes are added onto the $this->routes array using the key "named_routes"
     * Because this key does not match any valid request method, these values will always
     * be ignored when getting normal routes.
     *
     * @param string $path (Request path)
     * @param string $name (Name to assign to this route)
     *
     * @return self
     */

    function addNamedRoute(string $path, string $name): self
    {

        $this->routes['named_routes'][$this->getHost()][$this->getRoutePrefix() . $this->_sanitizePath($path)]['name'] = $name;

        return $this;

    }

    /**
     * Returns array of all route data for routes with a name
     *
     * @return array
     */

    protected function _getAllNamedRoutes(): array
    {

        $names = [];

        foreach ($this->getRoutes() as $methods) {

            foreach ($methods as $host => $paths) {

                foreach ($paths as $path => $info) {

                    if (isset($info['name'])) { // If route has a name

                        $info['url'] = Request::getRequest(Request::PART_PROTOCOL) . $host . $path; // Determine the URL

                        $names[$info['name']] = Arr::except($info, 'name'); // Make "name" the key, and remove from array

                    }

                }

            }

        }

        ksort($names);

        return $names;

    }

    /**
     * Named routes with wildcards replaced.
     *
     * @var array
     */
    private static array $named_routes = [];

    /**
     * Returns array of named routes.
     *
     * Automatically replaces wildcards with resolved parameters.
     *
     * @param array $params (Additional parameters used to replace wildcards in the named route)
     * @return array
     */

    public function getNamedRoutes(array $params = []): array
    {

        /*
         * Return named routes based on the parameters passed.
         */

        $params_hash = md5(json_encode($params));

        if (isset(self::$named_routes[$params_hash])) {
            return self::$named_routes[$params_hash];
        }

        $return = [];

        $params = array_merge($this->getResolvedParameters(), $params);

        foreach ($this->_getAllNamedRoutes() as $name => $route) {

            /*
             * Replace any wildcards existing in the named route
             * with matching resolved parameter, if existing.
             */

            preg_match_all("/{[^}]*}/", $route['url'], $wildcards);

            if (!empty($wildcards[0])) { // Wildcards exist on named route

                foreach ($wildcards[0] as $wildcard) {

                    $exp = explode(':', $wildcard, 2);

                    if (isset($exp[1])) {

                        $param = rtrim($exp[1], '}');

                        if (isset($params[$param])) {
                            $route['url'] = str_replace($wildcard, $params[$param], $route['url']);
                        }

                    }

                }

            }

            $return[$name] = $route['url'];

        }

        self::$named_routes[$params_hash] = $return;

        return self::$named_routes[$params_hash];

    }

    /**
     * Returns URL of a named route.
     *
     * Automatically replaces wildcards with resolved parameters.
     *
     * @param string $name
     * @param string $default (Default value to return if named route does not exist)
     * @param array $params (Additional parameters used to replace wildcards in the named route)
     * @return string
     */

    public function getNamedRoute(string $name, string $default = '', array $params = []): string
    {
        return Arr::get($this->getNamedRoutes($params), $name, $default);
    }

    /**
     * Resolves the incoming HTTP request by searching for a matching redirect, route, automapped location, or fallback.
     * Destination-specific parameters will overwrite global parameters of the same key.
     *
     * The returned array consists of the following keys:
     *
     * - type
     * - destination
     * - status (HTTP status code)
     * - params (Array)
     *
     * The destination will vary based on type:
     *
     * - redirect: URL
     * - route: Defined route
     * - automap: Class:method
     * - fallback: Defined callback
     *
     * A DispatchException will be thrown if the request is unable to be resolved.
     *
     * @param $params (Global parameters to pass to all destinations)
     * @return array
     * @throws DispatchException
     */

    public function resolve(array $params = []): array
    {

        $this_request = Request::getRequest();

        // -------------------- Force lowercase --------------------

        if ($this->options['force_lowercase_url'] && $this_request['url'] !== strtolower($this_request['url'])) {

            $redirect_url = strtolower($this_request['url']);

            if ($this_request['query_string'] != '') {
                $redirect_url = $redirect_url . '?' . $this_request['query_string'];
            }

            return [
                'type' => 'redirect',
                'destination' => $redirect_url,
                'status' => 301,
                'params' => []
            ];

        }

        // -------------------- Check redirects --------------------

        $redirect = $this->_getMatchingRoute($this->getRedirects(), $this_request);

        /*
         * If a valid redirect was found, and not redirecting to self
         */

        if (!empty($redirect) && $this_request['protocol'] . $this_request['host'] . $this_request['path'] != $redirect['destination']) {

            $query = '';

            if ($this_request['query_string'] != '') {
                $query = '?' . $this_request['query_string'];
            }

            return [
                'type' => 'redirect',
                'destination' => $redirect['destination'] . $query,
                'status' => $redirect['status'],
                'params' => []
            ];

        }

        // -------------------- Check routes --------------------

        $route = $this->_getMatchingRoute($this->getRoutes(), $this_request);

        if (!empty($route)) { // A valid route was found

            $this->resolved_params = array_merge($params, $route['params']);

            return [
                'type' => 'route',
                'destination' => $route['destination'],
                'status' => 200,
                'params' => array_merge($params, $route['params'])
            ];

        }

        // -------------------- Check automap --------------------

        if (true === $this->options['automapping_enabled']) {

            $automap = $this->_getAutomapDestination($this_request);

            if (!empty($automap)) { // A valid automap destination was found

                /*
                 * $automap array keys:
                 * class
                 * method
                 * id (optional)
                 */

                if (isset($automap['id'])) {
                    $params['id'] = $automap['id'];
                }

                $this->resolved_params = $params;

                return [
                    'type' => 'automap',
                    'destination' => $automap['class'] . ':' . $automap['method'],
                    'status' => 200,
                    'params' => $params
                ];

            }

        }

        // -------------------- Fallback --------------------

        $fallbacks = Arr::only($this->getFallbacks(), [ // Keep only keys for valid request methods
            self::METHOD_ANY,
            Request::getRequest(Request::PART_METHOD)
        ]);

        if (empty($fallbacks)) {
            throw new DispatchException('Unable to dispatch: invalid destination');
        }

        $return = Arr::only(reset($fallbacks), [
            'destination',
            'params'
        ]);

        $return['type'] = 'fallback';
        $return['status'] = 404;
        $return['params'] = array_merge($return['params'], $params);

        $this->resolved_params = $return['params'];

        return $return;

    }

    /**
     * Resolves and dispatches the incoming HTTP request.
     *
     * Destination-specific parameters will overwrite global parameters of the same key.
     *
     * @param array $params (Global parameters to pass to all destinations)
     *
     * @return mixed
     *
     * @throws DispatchException
     */

    public function dispatch(array $params = []): mixed
    {

        $resolve = $this->resolve($params);

        if (Arr::get($resolve, 'type') == 'redirect') { // Parameters are irrelevant
            $this->redirect(Arr::get($resolve, 'destination', ''), Arr::get($resolve, 'status', 302));
            return true;
        }

        if (Arr::get($resolve, 'type') == 'route' || Arr::get($resolve, 'type') == 'automap') { // Status is irrelevant

            return $this->dispatchTo(Arr::get($resolve, 'destination', ''), Arr::get($resolve, 'params', []));

        }

        if (Arr::get($resolve, 'type') == 'fallback') {

            http_response_code((int)Arr::get($resolve, 'status', 404));

            return $this->dispatchTo(Arr::get($resolve, 'destination', ''), Arr::get($resolve, 'params', []));

        }

        return false;

    }

    /**
     * Dispatches to a specific destination
     *
     * Destinations can be a callable function, a named route, a file, or a $class->method()
     *
     * @param mixed $destination
     * @param array $params (Parameters to pass to the destination)
     *
     * @return mixed
     *
     * @throws DispatchException
     */

    public function dispatchTo(mixed $destination, array $params = []): mixed
    {

        // If callable

        if (is_callable($destination)) {

            $this->resolved_params = $params;

            return call_user_func($destination, $params);

        }

        // If to a named route

        $named_routes = $this->_getAllNamedRoutes();

        if (isset($named_routes[$destination])) {

            $this->resolved_params = $named_routes[$destination]['params'];

            return $this->dispatchTo($named_routes[$destination]['destination'], $named_routes[$destination]['params']);

        }

        // If to a file

        if (str_starts_with($destination, '@')) {

            $file = $this->options['files_root_path'] . '/' . ltrim($destination, '@');

            if (is_file($file)) {

                $this->resolved_params = $params;

                include($file);

                return true;

            }

            throw new DispatchException('Unable to dispatch: file does not exist (' . $file . ')');

        }

        // If to a Class:method

        $loc = explode(':', $destination, 2);

        if (isset($loc[1])) { // Dispatch to Class:method

            if ($this->options['class_namespace'] == '' || str_starts_with($loc[0], $this->options['class_namespace'])) {

                $class_name = $loc[0];

            } else {

                $class_name = $this->options['class_namespace'] . '\\' . $loc[0];

            }

            $method = $loc[1];

            if (class_exists($class_name) && method_exists($class_name, $method)) {

                $class = new $class_name();

                $this->resolved_params = $params;

                return $class->$method($params);

            }

            /*
             * Do not throw exception here as the semicolon may otherwise be a valid part of the destination,
             * and since there are no more possible destinations, an exception is about to be thrown anyway.
             */

        }

        throw new DispatchException('Unable to dispatch: invalid destination');

    }

    /**
     * Dispatches to fallback for current request method, or throws exception.
     *
     * Fallback-specific parameters defined using the "addFallback" method will overwrite these parameters of the same
     * key.
     *
     * @param array $params (Parameters to pass to the destination)
     *
     * @return mixed
     *
     * @throws DispatchException
     *
     */

    public function dispatchToFallback(array $params = []): mixed
    {

        $fallbacks = Arr::only($this->getFallbacks(), [ // Keep only keys for valid request methods
            self::METHOD_ANY,
            Request::getRequest(Request::PART_METHOD)
        ]);

        if (empty($fallbacks)) { // No valid fallbacks exist with this request method

            throw new DispatchException('Unable to dispatch');

        }

        http_response_code(404);

        $fallback = reset($fallbacks);

        $fallback['params'] = array_merge($params, $fallback['params']);

        $this->resolved_params = $fallback['params'];

        return $this->dispatchTo($fallback['destination'], $fallback['params']); // Dispatch the first matching fallback

    }

    /**
     * Redirects to a given URL using a given status code.
     *
     * @param string $url (Fully qualified URL)
     * @param int $status (HTTP status code to return)
     *
     * @return void
     *
     * @throws DispatchException
     */

    public function redirect(string $url, int $status = 302): void
    {

        if (Request::getUrl('true') === $url) { // If redirecting to self

            throw new DispatchException('Unable to redirect: cannot redirect to self (' . $url . ')');

        }

        try {

            $this->response->redirect($url, $status);

        } catch (InvalidStatusCodeException $e) {

            throw new DispatchException('Unable to redirect: invalid status code (' . $status . ')', 0, $e);

        }

    }

    /**
     * Searches for a valid automap destination for this request
     *
     * @param array $this_request
     *
     * @return array
     */

    protected function _getAutomapDestination(array $this_request): array
    {

        if (str_starts_with($this_request['path'], $this->options['automapping_route_prefix'])) {

            $segments = explode('/', trim(str_replace($this->options['automapping_route_prefix'], '', $this_request['path']), '/'));

            /*
             * For the time being, automapping will only work for URLs with no more than
             * three segments, ie: class/method/id
             */

            if (!isset($segments[3])) { // No more than 3 segments

                /*
                 * As tested, this performs a case-insensitive search for the class,
                 * however in certain environments, there is a chance the class would not be
                 * found should this perform a case-sensitive search.
                 */

                if (isset($segments[0]) && $segments[0] != '') {

                    $class = $segments[0];

                } else {

                    $class = 'Home';

                }

                if ($this->options['automapping_namespace'] == '') {

                    $class_name = $class;

                } else {

                    $class_name = $this->options['automapping_namespace'] . '\\' . $class;

                }

                if (class_exists($class_name)) {

                    $method = $segments[1] ?? 'index';

                    if (method_exists($class_name, $method)) {

                        $return = [
                            'class' => $class_name,
                            'method' => $method,
                        ];

                        if (isset($segments[2])) {

                            $return['id'] = $segments[2];

                        }

                        return $return;

                    }

                }

            }

        }

        return []; // No matching destination

    }

    /**
     * Searches any route array with the following structure:
     *
     * [
     *     'REQUEST_METHOD' => [
     *         'HOST' => [
     *             'PATH' => [] <-- Returns this array
     *         ]
     *     ]
     * ]
     *
     * Both $this->getRedirects() and $this->getRoutes() arrays are
     * set up like this.
     *
     * @param $routes array
     * @param $this_request array
     *
     * @return array
     */

    protected function _getMatchingRoute(array $routes, array $this_request): array
    {

        $routes = Arr::only($routes, [ // Keep only keys for valid request methods
            self::METHOD_ANY,
            Arr::get($this_request, 'method', '')
        ]);

        if (empty($routes)) { // No valid destinations exist with this request method
            return [];
        }

        $request_host = Arr::get($this_request, 'host', '');

        if (is_string($request_host)) {
            $request_host = strtolower($request_host);
        } else {
            $request_host = '';
        }

        $request_path = $this->_sanitizePath(Arr::get($this_request, 'path', ''));

        if ($request_host == '') {
            return [];
        }

        foreach ($routes as $hosts_arr) { // For each array of routes for a given request method

            $hosts_arr = Arr::only($hosts_arr, [ // Keep only keys for valid host
                $request_host
            ]);

            if (empty($hosts_arr)) { // No valid destinations exist for this request's host - continue to next iteration
                continue;
            }

            // Check for exact match

            if (isset($hosts_arr[$request_host][$request_path])) {

                return $hosts_arr[$request_host][$request_path];

            }

            // Check for wildcard match

            $matching_route = $this->_getWildcardMatch($hosts_arr[$request_host], $request_path);

            if (!empty($matching_route)) {

                return $matching_route;

            }

        }

        return [];

    }

    /**
     * Compares a defined route with the current request path
     * looking for wildcard matches, and adding them as parameters.
     *
     * NOTE: Parameters can only be passed to a route, not a redirect
     *
     * Valid wildcard syntax:
     *
     *      * = Any non-whitespace character in this segment of the request
     *      alpha = Any alphabetic characters in this segment of the request
     *      num = Any numeric characters in this segment of the request
     *      alphanum = Any alphanumeric characters in this segment of the request
     *      ** = Everything else that may exist on the request path (catch-all)
     *      ? = Optionally existing in this segment of the request (can only be used at the last segment)
     *
     * @param $route_arr array
     * @param $request_path string
     *
     * @return array
     */

    protected function _getWildcardMatch(array $route_arr, string $request_path): array
    {

        /*
         * When an invalid route is determined, continue to next iteration
         * without defining a $wildcard_params key (next route on the route array)
         */

        foreach ($route_arr as $route => $route_settings) { // Level 1 foreach: for each route on array

            $route_segments = explode('/', trim($route, '/')); // Segments of defined route
            $request_segments = explode('/', trim($request_path, '/')); // Segments of current request

            /*
             * If number of segments do not match, and route does not end with
             * a "catch-all" or "optional" wildcard
             */

            if (count($route_segments) !== count($request_segments) &&
                !str_contains(end($route_segments), '{**:') &&
                !str_contains(end($route_segments), '{?:')
            ) { // Match impossibility

                continue; // This route does not match - continue to next iteration

            }

            // Keep searching... route may be valid

            // Reset internal pointer back to start of array

            reset($route_segments);

            // Start iterating through route segments ensuring the request is valid

            $total_segments = count($route_segments);
            $valid_segments = 0;
            $wildcard_params = []; // Matching wildcard values to return

            foreach ($route_segments as $k => $route_segment) { // Level 2 foreach: for each route segment

                preg_match_all("/{[^}]*}/", $route_segment, $wildcards);

                if (!isset($wildcards[0]) || !isset($wildcards[0][0])) { // If a wildcard does not exist at this segment

                    if (!isset($request_segments[$k])) { // If there are not the same amount of segments on the request and this route

                        continue 2; // Invalid route- continue to next route
                    }

                    if ($route_segment == $request_segments[$k]) { // If this route segment matches the same segment of the request

                        /*
                         * This could theoretically cause problems if every segment contains no wildcard
                         * and each segment matches, as there will be an empty array returned.
                         * However, this shouldn't ever happen, because $this->_getMatchingRoute()
                         * as already checked for an exact match before this method is called.
                         */

                        $valid_segments++; // Valid segment, continue to the next segment

                    } else {

                        continue 2; // This route does not match - continue to next route

                    }

                } else { // Wildcard exists at this segment

                    $wildcard = $wildcards[0][0];

                    // $wildcard = Entire wildcard string for this segment

                    $exp_wildcard = explode(':', $wildcard, 2);

                    if (!isset($exp_wildcard[1])) { // Invalid wildcard syntax

                        continue 2; // This route does not match - continue to next route

                    }

                    $wc_type = ltrim($exp_wildcard[0], '{');

                    $wc_name = rtrim($exp_wildcard[1], '}');

                    if (isset($request_segments[$k])) { // If request segment exists

                        $segment = $request_segments[$k]; // Define $segment

                    } else if ($wc_type == '?') { // Optional segment doesn't exist, but that's okay

                        /*
                         * An array must be returned if route is valid, so we will
                         * create the array key, and assign it a null value.
                         *
                         * If any future problems arise with an optional wildcard at the end of a request,
                         * the $route_settings array can be returned here.
                         */

                        $wildcard_params[$wc_name] = NULL;

                        $valid_segments++;

                        continue; // Valid segment, continue to next segment

                    } else { // Request segment doesn't exist, and wildcard is not optional

                        continue 2; // This route does not match - continue to next route

                    }

                    // $route_segment = Wildcard definition
                    // $segment = Request segment in URL

                    /*
                     * Switch statement did not work here due to the way the iteration needs to break if
                     * a wildcard type does not validate
                     */

                    if ($wc_type == 'alpha') {

                        if (ctype_alpha($segment)) {

                            $wildcard_params[$wc_name] = $segment;

                            $valid_segments++; // Valid segment, continue to next segment

                        } else {

                            continue 2; // This route does not match - continue to next route

                        }

                    } else if ($wc_type == 'num') {

                        if (ctype_digit($segment)) {

                            $wildcard_params[$wc_name] = $segment;

                            $valid_segments++; // Valid segment, continue to next segment

                        } else {

                            continue 2; // This route does not match - continue to next route

                        }

                    } else if ($wc_type == 'alphanum') {

                        if (ctype_alnum($segment)) {

                            $wildcard_params[$wc_name] = $segment;

                            $valid_segments++; // Valid segment, continue to next segment

                        } else {

                            continue 2; // This route does not match - continue to next route

                        }

                    } else if ($wc_type == '*') {

                        if (preg_match('/^\S+$/m', $segment)) { // Non-whitespace

                            $wildcard_params[$wc_name] = $segment;

                            $valid_segments++; // Valid segment, continue to next segment

                        } else {

                            continue 2; // This route does not match - continue to next route

                        }

                    } else if ($wc_type == '**') {

                        /*
                         * Return the rest of the request path as a string
                         *
                         * First, slice the $request_segments array at the current key
                         * then implode back to a string.
                         */

                        $wildcard_params[$wc_name] = implode('/', array_slice($request_segments, $k));

                        $valid_segments++; // Valid segment, continue to next segment

                    } else if ($wc_type == '?') {

                        if (!isset($request_segments[$k + 1])) { // If no additional request segments exist (at the end)

                            $wildcard_params[$wc_name] = $segment;

                            $valid_segments++; // Valid segment, continue to next segment

                        } else {

                            continue 2; // This route does not match - continue to next route

                        }

                    } else { // Invalid type

                        continue 2; // This route does not match - continue to next route

                    }

                } // End if wildcard exists

            } // End foreach route segment (level 2)

            if ($valid_segments == $total_segments && !empty($wildcard_params)) { // Valid route

                /*
                 * Nonexistent optional wildcard will have value of NULL.
                 * This needs to be removed in order not to overwrite default parameter values.
                 */

                foreach ($wildcard_params as $k => $v) {

                    if (NULL === $v) {
                        unset($wildcard_params[$k]);
                    }

                }

                if (isset($route_settings['params'])) { // Only exists on routes, not redirects

                    $route_settings['params'] = array_merge($route_settings['params'], $wildcard_params); // Wildcards overwrite predefined parameters

                } else {

                    $route_settings['params'] = $wildcard_params;

                }

                return $route_settings; // Matching route with wildcard parameters

            }

        } // End for each route on the array (level 1)

        return []; // Return empty array if no matches

    }

    private array $resolved_params = [];

    /**
     * Get array of all parameters present for the current route once resolved/dispatched.
     *
     * @return array
     */
    public function getResolvedParameters(): array
    {
        return $this->resolved_params;
    }

}