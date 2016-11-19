<?php
namespace Pecee\SimpleRouter;

use Pecee\Exception\RouterException;
use Pecee\Http\Middleware\IMiddleware;
use Pecee\Http\Request;

abstract class RouterEntry
{
	const REQUEST_TYPE_GET = 'get';
	const REQUEST_TYPE_POST = 'post';
	const REQUEST_TYPE_PUT = 'put';
	const REQUEST_TYPE_PATCH = 'patch';
	const REQUEST_TYPE_OPTIONS = 'options';
	const REQUEST_TYPE_DELETE = 'delete';

	public static $requestTypes = [
		self::REQUEST_TYPE_GET,
		self::REQUEST_TYPE_POST,
		self::REQUEST_TYPE_PUT,
		self::REQUEST_TYPE_PATCH,
		self::REQUEST_TYPE_OPTIONS,
		self::REQUEST_TYPE_DELETE,
	];

	protected $parent;
	protected $callback;

	protected $namespace;
	protected $defaultNamespace;
	protected $regex;
	protected $requestMethods = array();
	protected $where = array();
	protected $parameters = array();
	protected $middlewares = array();

	protected function loadClass($name)
	{
		if (!class_exists($name)) {
			throw new RouterException(sprintf('Class %s does not exist', $name));
		}

		return new $name();
	}

	protected function parseParameters($route, $url, $parameterRegex = '[\w]+')
	{
		$parameterNames = array();
		$regex = '';
		$lastCharacter = '';
		$isParameter = false;
		$parameter = '';

		$routeLength = strlen($route);
		for ($i = 0; $i < $routeLength; $i++) {

			$character = $route[$i];

			if ($character === '{') {
				// Remove "/" and "\" from regex
				if (substr($regex, strlen($regex) - 1) === '/') {
					$regex = substr($regex, 0, strlen($regex) - 2);
				}

				$isParameter = true;
			} elseif ($isParameter && $character === '}') {
				$required = true;

				// Check for optional parameter and use custom parameter regex if it exists
				if (is_array($this->where) === true && isset($this->where[$parameter])) {
					$parameterRegex = $this->where[$parameter];
				}

				if ($lastCharacter === '?') {
					$parameter = substr($parameter, 0, strlen($parameter) - 1);
					$regex .= '(?:\/?(?P<' . $parameter . '>' . $parameterRegex . ')[^\/]?)?';
					$required = false;
				} else {
					$regex .= '\/?(?P<' . $parameter . '>' . $parameterRegex . ')[^\/]?';
				}

				$parameterNames[] = [
					'name' => $parameter,
					'required' => $required
				];

				$parameter = '';
				$isParameter = false;
			} elseif ($isParameter) {
				$parameter .= $character;
			} elseif ($character === '/') {
				$regex .= '\\' . $character;
			} else {
				$regex .= str_replace('.', '\\.', $character);
			}

			$lastCharacter = $character;
		}

		$parameterValues = array();

		if (preg_match('/^' . $regex . '\/?$/is', $url, $parameterValues)) {

			$parameters = array();

			foreach ($parameterNames as $name) {
				$parameterValue = isset($parameterValues[$name['name']]) ? $parameterValues[$name['name']] : null;

				if ($name['required'] && $parameterValue === null) {
					throw new RouterException('Missing required parameter ' . $name['name'], 404);
				}

				if ($name['required'] === false && $parameterValue === null) {
					continue;
				}

				$parameters[$name['name']] = $parameterValue;
			}

			return $parameters;
		}

		return null;
	}

	public function loadMiddleware(Request $request, RouterEntry &$route)
	{
		if (count($this->getMiddlewares()) > 0) {
			foreach ($this->getMiddlewares() as $middleware) {

				$middleware = $this->loadClass($middleware);
				if (!($middleware instanceof IMiddleware)) {
					throw new RouterException($middleware . ' must be instance of Middleware');
				}

				/* @var $class IMiddleware */
				$middleware->handle($request, $route);

			}
		}
	}

	public function renderRoute(Request $request)
	{
		if ($this->getCallback() !== null && is_callable($this->getCallback())) {

			// When the callback is a function
			call_user_func_array($this->getCallback(), $this->getParameters());

		} else {

			// When the callback is a method
			$controller = explode('@', $this->getCallback());
			$className = $this->getNamespace() . '\\' . $controller[0];

			$class = $this->loadClass($className);
			$method = $controller[1];

			if (!method_exists($class, $method)) {
				throw new RouterException(sprintf('Method %s does not exist in class %s', $method, $className), 404);
			}

			$parameters = array_filter($this->getParameters(), function ($var) {
				return ($var !== null);
			});

			call_user_func_array(array($class, $method), $parameters);

			return $class;
		}

		return null;
	}

	/**
	 * Returns callback name/identifier for the current route based on the callback.
	 * Useful if you need to get a unique identifier for the loaded route, for instance
	 * when using translations etc.
	 *
	 * @return string
	 */
	public function getIdentifier()
	{
		if (strpos($this->callback, '@') !== false) {
			return $this->callback;
		}
		return 'function_' . md5($this->callback);
	}

	/**
	 * Set allowed request methods
	 *
	 * @param array $methods
	 * @return static $this
	 */
	public function setRequestMethods(array $methods)
	{
		$this->requestMethods = $methods;
		return $this;
	}

	/**
	 * Get allowed request methods
	 * @return array
	 */
	public function getRequestMethods()
	{
		return $this->requestMethods;
	}

	/**
	 * @return RouterEntry
	 */
	public function getParent()
	{
		return $this->parent;
	}

	/**
	 * Set parent route
	 *
	 * @param RouterEntry $parent
	 * @return static $this
	 */
	public function setParent(RouterEntry $parent)
	{
		$this->parent = $parent;
		return $this;
	}

	/**
	 * @param string $callback
	 * @return static
	 */
	public function setCallback($callback)
	{
		$this->callback = $callback;
		return $this;
	}

	/**
	 * @return mixed
	 */
	public function getCallback()
	{
		return $this->callback;
	}

	public function getMethod()
	{
		if (strpos($this->callback, '@') !== false) {
			$tmp = explode('@', $this->callback);
			return $tmp[1];
		}
		return null;
	}

	public function getClass()
	{
		if (strpos($this->callback, '@') !== false) {
			$tmp = explode('@', $this->callback);
			return $tmp[0];
		}
		return null;
	}

	public function setMethod($method)
	{
		$this->callback = sprintf('%s@%s', $this->getClass(), $method);
		return $this;
	}

	public function setClass($class)
	{
		$this->callback = sprintf('%s@%s', $class, $this->getMethod());
		return $this;
	}

	/**
	 * @param string $middleware
	 * @return static
	 */
	public function setMiddleware($middleware)
	{
		$this->middlewares[] = $middleware;
		return $this;
	}

	public function setMiddlewares(array $middlewares)
	{
		$this->middlewares = $middlewares;
		return $this;
	}

	/**
	 * @param string $namespace
	 * @return static $this
	 */
	public function setNamespace($namespace)
	{
		$this->namespace = $namespace;
		return $this;
	}

	/**
	 * @param string $namespace
	 * @return static $this
	 */
	public function setDefaultNamespace($namespace) {
		$this->defaultNamespace = $namespace;
		return $this;
	}

	public function getDefaultNamespace() {
		return $this->defaultNamespace;
	}

	/**
	 * @return string|array
	 */
	public function getMiddlewares()
	{
		return $this->middlewares;
	}

	/**
	 * @return string
	 */
	public function getNamespace()
	{
		return ($this->namespace === null) ? $this->defaultNamespace : $this->namespace;
	}

	/**
	 * @return array
	 */
	public function getParameters()
	{
		return $this->parameters;
	}

	/**
	 * @param mixed $parameters
	 * @return static
	 */
	public function setParameters($parameters)
	{
		$this->parameters = $parameters;
		return $this;
	}

	/**
	 * Add regular expression parameter match
	 *
	 * @param array $options
	 * @return static
	 */
	public function where(array $options)
	{
		$this->where = $options;
		return $this;
	}

	/**
	 * Add regular expression match for url
	 *
	 * @param string $regex
	 * @return static
	 */
	public function match($regex)
	{
		$this->regex = $regex;
		return $this;
	}

	/**
	 * Export route settings to array so they can be merged with another route.
	 *
	 * @return array
	 */
	public function toArray()
	{
		$values = array();

		if ($this->namespace !== null) {
			$values['namespace'] = $this->namespace;
		}

		if (count($this->middlewares) > 0) {
			$values['middleware'] = $this->middlewares;
		}

		if (count($this->where) > 0) {
			$values['where'] = $this->where;
		}

		if (count($this->requestMethods) > 0) {
			$values['method'] = $this->requestMethods;
		}

		if (count($this->parameters) > 0) {
			$values['parameters'] = $this->parameters;
		}

		return $values;
	}

	/**
	 * Merge with information from another route.
	 *
	 * @param array $values
	 * @return static $this
	 */
	public function merge(array $values)
	{
		if (isset($values['namespace'])) {
			$this->setNamespace($values['namespace']);
		}

		// Push middleware if multiple
		if (isset($values['middleware'])) {
			$this->middlewares = array_merge((array)$values['middleware'], $this->middlewares);
		}

		if (isset($values['method'])) {
			$this->setRequestMethods((array)$values['method']);
		}

		if (isset($values['where'])) {
			$this->where($values['where']);
		}

		if (isset($values['parameters'])) {
			$this->setParameters($values['parameters']);
		}

		return $this;
	}

	abstract function matchRoute(Request $request);

}