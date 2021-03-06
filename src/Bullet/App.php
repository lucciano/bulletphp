<?php
namespace Bullet;

class App extends \Pimple
{
    protected $_request;
    protected $_response;

    protected $_paths = array();
    protected $_requestMethod;
    protected $_requestPath;
    protected $_curentPath;
    protected $_callbacks = array(
      'path' => array(),
      'param' => array(),
      'param_type' => array(),
      'method' => array(),
      'format' => array(),
      'exception' => array(),
      'custom' => array()
    );
    protected $_helpers = array();

    /**
     * New App instance
     *
     * @param array $values Array of config settings and objects to pass into Pimple container
     */
    public function __construct(array $values = array())
    {
        $this->registerParamType('int', function($value) {
            return filter_var($value, FILTER_VALIDATE_INT);
        });
        $this->registerParamType('float', function($value) {
            return filter_var($value, FILTER_VALIDATE_FLOAT);
        });
        // True = "1", "true", "on", "yes"
        // False = "0", "false", "off", "no"
        $this->registerParamType('boolean', function($value) {
            $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return (!empty($filtered) && $filtered !== null);
        });
        $this->registerParamType('slug', function($value) {
            return (preg_match("/[a-zA-Z0-9-_]/", $value) > 0);
        });
        $this->registerParamType('email', function($value) {
            return filter_var($value, FILTER_VALIDATE_EMAIL);
        });

        // Pimple constructor
        parent::__construct($values);

        // Template configuration settings if given
        if(isset($this['template.cfg'])) {
            View\Template::config($this['template.cfg']);
        }
    }

    public function path($path, \Closure $callback)
    {
        foreach((array) $path as $p) {
            $p = trim($p, '/');
            $this->_callbacks['path'][$p] = $this->_prepClosure($callback);
        }
        return $this;
    }

    public function param($param, \Closure $callback)
    {
        $this->_callbacks['param'][$param] = $this->_prepClosure($callback);
        return $this;
    }

    public function registerParamType($type, $callback)
    {
        if(!is_callable($callback)) {
            throw new \InvalidArgumentException("Second argument must be a valid callback. Given argument was not callable.");
        }
        $this->_callbacks['param_type'][$type] = $callback;
        return $this;
    }

    /**
     * Prep closure callback by binding context in PHP >= 5.4
     */
    protected function _prepClosure(\Closure $closure)
    {
        // Bind local context for PHP >= 5.4
        if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
            $closure->bindTo($this);
        }
        return $closure;
    }

    /**
     * Run app with given REQUEST_METHOD and REQUEST_URI
     *
     * @param string|object $method HTTP request method string or \Bullet\Request object
     * @param string optional $uri URI/path to run
     * @return \Bullet\Response
     */
    public function run($method, $uri = null)
    {
        $response = false;

        // If Request instance was passed in as the first parameter
        if($method instanceof \Bullet\Request) {
            $request = $method;
            $this->_request = $request;
        // Create new Request object from passed method and URI
        } else {
            $this->_request = new \Bullet\Request($method, $uri);
        }
        $this->_requestMethod = strtoupper($this->_request->method());
        $this->_requestPath = $this->_request->url();

        // Detect extension and assign it as the requested format (default is 'html')
        $dotPos = strpos($this->_requestPath, '.');
        if($dotPos !== false) {
            $ext = substr($this->_requestPath, $dotPos+1);
            $this->_request->format($ext);
            // Remove extension from path for path execution
            $this->_requestPath = substr($this->_requestPath, 0, -(strlen($this->_request->format())+1));
        }

        // Normalize request path
        $this->_requestPath = trim($this->_requestPath, '/');

        // Run before filter
        $this->filter('before');

        // Explode by path without leading or trailing slashes
        $paths = explode('/', $this->_requestPath);
        foreach($paths as $pos => $path) {
            $this->_currentPath = implode('/', array_slice($paths, 0, $pos+1));

            // Run and get result
            try {
                $response = $this->_runPath($this->_requestMethod, $path);
            } catch(\Exception $e) {
                // Always trigger base 'Exception', plus actual exception class
                $events = array_unique(array('Exception', get_class($e)));

                // Default status is 500 and content is Exception object
                $this->response()->status(500)->content($e);

                // Run filters and assign response
                $this->filter($events, array($e));
                $response = $this->response();
            }
        }

        // Ensure response is always a Bullet\Response
        if($response === false) {
            // Boolean false result generates a 404
            $response = $this->response(404);
        } elseif(is_int($response)) {
            // Assume int response is desired HTTP status code
            $response = $this->response($response);
        } else {
            // Convert response to Bullet\Response object if not one already
            if(!($response instanceof \Bullet\Response)) {
                $response = $this->response(200, $response);
            }
        }

        // JSON headers and response if content is an array
        if(is_array($response->content())) {
            $response->header('Content-Type', 'application/json');
            $response->content(json_encode($response->content()));
        }

        // Set current outgoing response
        $this->response($response);

        // Trigger events based on HTTP request format and HTTP response code
        $this->filter(array($this->_request->format(), $response->status(), 'after'));

        return $response;
    }

    /**
     * Determine if the currently executing path is the full requested one
     */
    public function isRequestPath()
    {
        return $this->_currentPath === $this->_requestPath;
    }

    /**
     * Send HTTP response with status code and content
     */
    public function response($statusCode = null, $content = null)
    {
        $res = null;

        // Get current response (passed nothing)
        if($statusCode === null) {
            $res = $this->_response;

        // Set response
        } elseif($statusCode instanceof \Bullet\Response) {
            $res = $this->_response = $statusCode;
        }

        // Create new response if none is going to be returned
        if($res === null) {
            $res = new \Bullet\Response($content, $statusCode);

            // If content not set, use default HTTP
            if($content === null) {
                $res->content($res->statusText($statusCode));
            }
        }

        // If this is the first response sent, store it
        if($this->_response === null) {
            $this->_response = $res;
        }

        return $res;
    }

    /**
     * Execute callbacks that match particular path segment
     */
    protected function _runPath($method, $path, \Closure $callback = null)
    {
        // Use $callback param if set (always overrides)
        if($callback !== null) {
            $res = call_user_func($callback, $this->request());
            return $res;
        }

        // Default response is boolean false (produces 404 Not Found)
        $res = false;

        // Run 'path' callbacks
        if(isset($this->_callbacks['path'][$path])) {
            $cb = $this->_callbacks['path'][$path];
            $res = call_user_func($cb, $this->request());
        }

        // Run 'param' callbacks
        if(count($this->_callbacks['param']) > 0) {
            foreach($this->_callbacks['param'] as $filter => $cb) {
                // Use matching registered filter type callback if given a non-callable string
                if(is_string($filter) && !is_callable($filter) && isset($this->_callbacks['param_type'][$filter])) {
                    $filter = $this->_callbacks['param_type'][$filter];
                }
                $param = call_user_func($filter, $path);

                // Skip to next callback in same path if boolean false returned
                if($param === false) {
                    continue;
                } elseif(!is_bool($param)) {
                    // Pass callback test function return value if not boolean
                    $path = $param;
                }
                $res = call_user_func($cb, $this->request(), $path);
                break;
            }
        }

        // Run 'method' callbacks if the path is the full requested one
        if($this->isRequestPath() && count($this->_callbacks['method']) > 0) {
            // If there are ANY method callbacks, use if matches method, return 405 if not
            // If NO method callbacks are present, path return value will be used, or 404
            if(isset($this->_callbacks['method'][$method])) {
                $cb = $this->_callbacks['method'][$method];
                $res = call_user_func($cb, $this->request());
            } else {
                $res = $this->response(405);
            }
        } else {
            // Empty out collected method callbacks
            $this->_callbacks['method'] = array();
        }

        // Run 'format' callbacks if the path is the full one AND the requested format matches a callback
        $format = $this->_request->format();
        if($this->isRequestPath() && count($this->_callbacks['format']) > 0) {
            // If there are ANY format callbacks, use if matches format, return 406 if not
            // If NO method callbacks are present, path return value will be used, or 404
            if(isset($this->_callbacks['format'][$format])) {
                $cb = $this->_callbacks['format'][$format];
                $res = call_user_func($cb, $this->request());
            } else {
                $res = $this->response(406);
            }
        } else {
            // Empty out collected method callbacks
            $this->_callbacks['format'] = array();
        }

        return $res;
    }

    /**
     * Get current request object
     */
    public function request()
    {
        return $this->_request;
    }

    /**
     * Getter for current path
     */
    public function currentPath()
    {
        return $this->_currentPath;
    }

    /**
     * Handle GET method
     */
    public function get(\Closure $callback)
    {
        return $this->method('GET', $callback);
    }

    /**
     * Handle POST method
     */
    public function post(\Closure $callback)
    {
        return $this->method('POST', $callback);
    }

    /**
     * Handle PUT method
     */
    public function put(\Closure $callback)
    {
        return $this->method('PUT', $callback);
    }

    /**
     * Handle DELETE method
     */
    public function delete(\Closure $callback)
    {
        return $this->method('DELETE', $callback);
    }

    /**
     * Handle PATCH method
     */
    public function patch(\Closure $callback)
    {
        return $this->method('PATCH', $callback);
    }

    /**
     * Handle HTTP method
     *
     * @param string $method HTTP method to handle for
     * @param \Closure $callback Closure to execute to handle specified HTTP method
     */
    public function method($method, \Closure $callback)
    {
        $this->_callbacks['method'][strtoupper($method)] = $this->_prepClosure($callback);
        return $this;
    }

    /**
     * Handle HTTP content type as output format
     *
     * @param string $format HTTP content type format to handle for
     * @param \Closure $callback Closure to execute to handle specified format
     */
    public function format($format, \Closure $callback)
    {
        $this->_callbacks['format'][strtolower($format)] = $this->_prepClosure($callback);
        return $this;
    }

    /**
     * Build URL path for
     */
    public function url($path = null)
    {
        $request = $this->request();

        // Subdirectory, if any
        $subdir = trim(mb_substr($request->uri(), 0, mb_strrpos($request->uri(), $request->url())), '/');

        // Assemble full url
        $url = $request->scheme() . '://' . $request->host() . '/' . $subdir;

        // Allow for './' current path shortcut (append given path to current one)
        if(strpos($path, './') === 0) {
            $path = substr($path, 2);
            $currentPath = $this->currentPath();
            $pathLen = strlen($path);
            $startsWithPath = strpos($path, $currentPath) === 0;
            $endsWithPath = substr_compare($currentPath, $path, -$pathLen, $pathLen) === 0;

            // Don't double-stack path if it's the same as the current path
            if($path != $currentPath && !$startsWithPath && !$endsWithPath) {
                $path = $currentPath . '/' . trim($path, '/');
            // Don't append another segment to the path that matches the end of the current path already
            } elseif($startsWithPath) {
                // Do nothing
            } elseif($endsWithPath) {
                $path = $currentPath;
            }
        }

        if($path === null) {
            $path = $this->currentPath();
        }

        // url + path
        $url = rtrim($url, '/') . '/' . ltrim($path, '/');

        return $url;
    }

    /**
     * Return instance of Bullet\View\Template
     *
     * @param string $name Template name
     * @param array $params Array of params to set
     */
    public function template($name, array $params = array())
    {
        $tpl = new View\Template($name);
        $tpl->set($params);
        return $tpl;
    }

    /**
     * Load and return or register helper class
     *
     * @param string $name helper name to register
     * @param string $class Class name of helper to load
     */
    public function helper($name, $class = null)
    {
        if($class !== null) {
            $this->_helpers[$name] = $class;
            return;
        }

        // Ensure helper exists
        if(!isset($this->_helpers[$name])) {
            throw new \InvalidArgumentException("Requested helper '" . $name ."' not registered.");
        }

        // Instantiate helper if not done already
        if(!is_object($this->_helpers[$name])) {
            $this->_helpers[$name] = new $this->_helpers[$name];
        }

        return $this->_helpers[$name];
    }

    /**
     * Add event handler for named event
     *
     * @param mixed $event Name of the event to be handled
     * @param callback $callback Callback or closure that will be executed when event is triggered
     * @throws InvalidArgumentException
     */
    public function on($event, $callback)
    {
        if(!is_callable($callback)) {
            throw new \InvalidArgumentException("Second argument is expected to be a valid callback or closure. Got: " . gettype($callback));	
        }

        // Allow for an array of events to be passed in
        foreach((array) $event as $eventName) {
            $eventName = $this->eventName($eventName);
            $this->_callbacks['events'][$eventName][] = $callback;
        }
    }

    /**
     * Remove event handlers for given event name
     *
     * @param mixed $event Name of the event to be handled
     * @return boolean Boolean true on successful event handler removal, false on failure or non-existent event
     */
    public function off($event)
    {
        // Allow for an array of events to be passed in
        foreach((array) $event as $eventName) {
            $eventName = $this->eventName($eventName);
            if(isset($this->_callbacks['events'][$eventName])) {
                unset($this->_callbacks['events'][$eventName]);
                return true;
            }
        }
        return false;
    }

    /**
     * Trigger event by running all filters for it
     *
     * @param string|array $event Name of the event or array of events to be triggered
     * @param array $args Extra arguments to pass to filters listening for event
     * @return boolean Boolean true on successful event trigger, false on failure or non-existent event
     */
    public function filter($event, array $args = array())
    {
        $request = $this->request();
        $response = $this->response();

        // Allow for an array of events to be passed in
        foreach((array) $event as $eventName) {
            $eventName = $this->eventName($eventName);
            if(isset($this->_callbacks['events'][$eventName])) {
                foreach($this->_callbacks['events'][$eventName] as $handler) {
                    call_user_func_array($handler, array_merge(array($request, $response), $args));
                }
            }
        }
    }

    /**
     * Normalize event name
     *
     * @param mixed $event Name of the event to be handled
     * @return string Normalized name of the event
     */
    public function eventName($eventName)
    {
        // Event is class name if class is passed
        if(is_object($eventName)) {
            $eventName = get_class($eventName);
        }
        if(!is_scalar($eventName)) {
            throw new \InvalidArgumentException("Event name is expected to be a scalar value (integer, float, string, or boolean). Got: " . gettype($eventName) . " (" . var_export($eventName, true) . ")");	
        }
        return (string) $eventName;
    }

    /**
     * Handle exception using exception handling callbacks, if any
     */
    public function handleException(\Exception $e)
    {
        foreach($this->_callbacks['exception'] as $handler) {
            $res = call_user_func($handler, $e);
            if($res !== null) {
                return $res;
            }
        }

        // Re-throw exception if there are no registered exception handlers
        throw $e;
    }

    /**
     * Implementing for Rackem\Rack (PHP implementation of Rack)
     */
    public function call($env)
    {
        $response = $this->run($env['REQUEST_METHOD'], $env['PATH_INFO']);
        return array($response->status(), $response->headers(), $response->content());
    }

    /**
     * Print out an array or object contents in preformatted text
     * Useful for debugging and quickly determining contents of variables
     */
    public function dump()
    {
        $objects = func_get_args();
        $content = "\n<pre>\n";
        foreach($objects as $object) {
            $content .= print_r($object, true);
        }
        return $content . "\n</pre>\n";
    }

    /**
     * Add a custom user method via closure or PHP callback
     *
     * @param string $method Method name to add
     * @param callback $callback Callback or closure that will be executed when missing method call matching $method is made
     * @throws InvalidArgumentException
     */
    public function addMethod($method, $callback)
    {
        if(!is_callable($callback)) {
            throw new \InvalidArgumentException("Second argument is expected to be a valid callback or closure.");	
        }
        if(method_exists($this, $method)) {
            throw new \InvalidArgumentException("Method '" . $method . "' already exists on " . __CLASS__);	
        }
        $this->_callbacks['custom'][$method] = $callback;
    }

    /**
     * Run user-added callback
     *
     * @param string $method Method name called
     * @param array $args Array of arguments used in missing method call
     * @throws BadMethodCallException
     */
    public function __call($method, $args)
    {
        if(isset($this->_callbacks['custom'][$method]) && is_callable($this->_callbacks['custom'][$method])) {
            $callback = $this->_callbacks['custom'][$method];
            return call_user_func_array($callback, $args);
        } else {
            throw new \BadMethodCallException("Method '" . __CLASS__ . "::" . $method . "' not found");	
        }
    }

    /**
     * Prevent PHP from trying to serialize cached object instances
     */
    public function __sleep()
    {
        return array();
    }
}
