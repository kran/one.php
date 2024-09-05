<?php
namespace one;

class One {
    protected $groupStack = [];
    protected $routes = [];
    protected $deps = [];
    protected $events = [];
    protected $coreFuns = [
        'router', 'view', 'json', 'redirect', 'load', 'query', 'mustQuery',
        'form', 'mustForm', 'session', 'cookie', 'rawPost', 'onError', 'log'
    ];
    
    public function __construct(array $deps = []){
        if(session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        $this->register("one", fn() => $this);
        foreach ($this->coreFuns as $fn) {
            $this->register($fn, fn() => \Closure::fromCallable("\one\\{$fn}")->bindTo($this));
        }
        foreach ($deps as $k => $v) {
            $this->register($k, $v);
        }
        
        $this->exception($this->getDep('onError'));
    }
    
    public function event(string $name, callable $func) {
        $this->events[$name][] = $func;
    }
    
    public function emit(string $name, ...$args) {
        $funs = $this->events[$name] ?? [];
        foreach ($funs as $func) {
            call_user_func_array($func, $args);
        }
    }
    
    public function register(string $name, \Closure $factory) {
        $factory = $factory->bindTo($this);
        if($name[0] == '#') {
            $this->deps[strtolower(substr($name, 1))] = $factory;
        } else {
            $this->deps[strtolower($name)] = function() use ($name, $factory) {
                static $instance = null;
                if($instance != null) return $instance;
                $instance = $this->call($factory);
                return $instance;
            };
        }
    }
    
    public function call(callable $func) {
        $reflect = new \ReflectionFunction($func);
        $args = [];
        foreach ($reflect->getParameters() as $param) {
            $args[] = $this->getDep($param->name);
        }
        return call_user_func_array($func, $args);
    }
    
    protected function getDep(string $name) {
        $name = strtolower($name);
        if(!isset($this->deps[$name]))
            throw new NoDepException("missed dependency: {$name}");
        return $this->call($this->deps[$name]);
    }
    
    protected function getDepOptional(string $name) {
        try {
            return $this->getDep($name);
        } catch (NoDepException $ex) {
            return null;
        }
    }
    
    protected function trimPath(string $uri) : string {
        return trim($uri, "/\n\t\v ");
    }
    
    protected function buildPath($uri) : string {
        if(count($this->groupStack) == 0)
            return '/'.$this->trimPath($uri);
        return '/'.$this->trimPath(implode('/', $this->groupStack) . '/' .$this->trimPath($uri));
    }
    
    protected function exception(callable $handler) {
        set_exception_handler($handler);
        set_error_handler(function ($num, $str, $file, $line, $context = null) use ($handler){
            if(error_reporting() & $num)
                $handler(new \ErrorException($str, 0, $num, $file, $line));
        });
    }
    
    public function group(string $prefix, \Closure $func) {
        array_push($this->groupStack, $this->trimPath($prefix));
        $this->call($func->bindTo($this));
        array_pop($this->groupStack);
    }
    
    public function route(string $uri, \Closure $func){
        $this->routes[$this->buildPath($uri)] = $func;
    }
    
    
    public function run(string $uri){
        $uri = $this->buildPath($uri);
        $route = call_user_func($this->getDep('router'), $this->routes, $uri);
        if($route === null)
            throw new \RuntimeException("route not found: {$uri}");
        
        $this->emit('route.before', $route, $uri);
        $this->call($route->bindTo($this));
        $this->emit('route.after', $route, $uri);
    }
    
    public function __get($name) {
        return $this->getDep($name);
    }
    
    public function __call($name, $args) {
        {
            $dep = $this->getDepOptional($name);
            if(is_callable($dep))
                return call_user_func_array($dep, $args);
        }
        
        if(strStartsWith($name, 'is')) {
            $method = substr($name, 2);
            return strcasecmp($method, $_SERVER['REQUEST_METHOD'] ?? ' ') == 0;
        }
        
        throw new \BadMethodCallException("no method: " . $name);
    }
}

//-- data access layer

class Dao {
    protected $sql;
    protected $params = [];
    protected $binders = [];
    protected $sqlDir;
    protected $escaper;
    protected \PDO $pdo;
    
    public function __construct(\PDO $pdo, string $sqlDir) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::MYSQL_ATTR_FOUND_ROWS, true);
        $this->sqlDir = $sqlDir;
        
        $this->setEscaper('\one\mysqlStyleEscaper');
        $this->registerBinder('json', function ($data){
            return $this->bind(json($data));
        });
    }
    
    public function setEscaper(callable $escaper) {
        $this->escaper = $escaper;
    }
    
    public function escape($k) : string {
        return call_user_func($this->escaper, $k);
    }
    
    public function pdo() : \PDO {
        return $this->pdo;
    }
    
    public function sql(string $sql) : Dao {
        assert($this->sql === null, '$dal->sql is not null');
        $this->sql = $sql;
        return $this;
    }
    
    public function file($sqlFile, array ...$data) : Dao {
        assert($this->sql === null, '$dal->sql is not null');
        $sqlFile = $this->sqlDir.DIRECTORY_SEPARATOR.$sqlFile.'.sql';
        mustInDir($this->sqlDir, $sqlFile);
        
        $render = function () {
            extract(func_get_arg(1));
            ob_start();
            require(func_get_arg(0));
            return ob_get_clean();
        };
        $args = array_merge(...$data);
        $this->sql = $render($sqlFile, $args);
        return $this;
    }
    
    public function bind($val, $type = \PDO::PARAM_STR) : string {
        $val = is_array($val) ? $val : [$val];
        $marks = [];
        foreach ($val as $param) {
            $marks[] = '?';
            $this->params[] = [$param, $type];
        }
        return implode(',', $marks);
    }
    
    public function registerBinder($name, \Closure $func) {
        $this->binders[strtolower($name)] = $func->bindTo($this);
    }
    
    public function __call($name, $args) {
        if(strStartsWith($name, 'bind')){
            $binder = strtolower(substr($name, 4));
            if(isset($this->binders[$binder]))
                return $this->binders[$binder](...$args);
        }
        
        throw new \BadMethodCallException('no method: '.$name);
    }
    
    public function __invoke($val, $type = \PDO::PARAM_STR): string {
        return $this->bind($val, $type);
    }
    
    public function reset() : Dao {
        $this->params = [];
        $this->sql = null;
        return $this;
    }
    
    public function __clone() {
        $this->reset();
    }
    
    protected function prepare($opts = []) : \PDOStatement {
        log("SQL: {$this->sql}");
        $stmt = $this->pdo->prepare($this->sql, $opts);
        for($i = 0; $i < count($this->params); $i++) {
            $stmt->bindParam($i+1, $this->params[$i][0], $this->params[$i][1]);
        }
        return $stmt;
    }
    
    public function execute($opts = null) : \PDOStatement {
        $stmt = $this->prepare();
        $stmt->execute($opts);
        $this->reset();
        return $stmt;
    }
    
    public function lastId($name = null) {
        return $this->pdo->lastInsertId($name);
    }
    
    public function list($class = \stdClass::class, callable $mapper = null) : array {
        $list = [];
        $stmt = $this->execute();
        while($item = $stmt->fetchObject($class)) {
            $list[] = $mapper != null ? $mapper($item) : $item;
        }
        $stmt->closeCursor();
        return $list;
    }
    
    public function one($class = \stdClass::class, callable $mapper = null) {
        $list = $this->list($class, $mapper);
        assert(count($list) <= 1, "returned more than one row");
        return $list[0] ?? null;
    }
    
    public function column($index = 0) : string {
        $stmt = $this->execute();
        $val = $stmt->fetchColumn($index);
        $stmt->closeCursor();
        return $val;
    }
    
    public function map(string $key, $class = \stdClass::class, string $valueKey = null) : array {
        $map = [];
        $stmt = $this->execute();
        while($item = $stmt->fetchObject($class)) {
            $map[$item->{$key}] = $valueKey === null ? $item : $item->{$valueKey};
        }
        $stmt->closeCursor();
        return $map;
    }
    
    public function stream(callable $handler, $class = \stdClass::class) {
        $stmt = $this->execute();
        while($item = $stmt->fetchObject($class)) {
            $handler($item);
        }
        $stmt->closeCursor();
    }
}


class Model implements \JsonSerializable {
    
    public function load($data, ...$extra) : Model {
        load($this, $data, ...$extra);
        return $this;
    }
    
    public function __set($name, $val) {
        try {
            $camel = snakeToCamel($name);
            $ref = new \ReflectionProperty($this, $camel);
            if($ref->isPublic() && !$ref->isStatic()) {
                $this->$camel = $val;
            }
        } catch (\ReflectionException $e) {
            return;
        }
    }
    
    public function toMap() : array {
        $ref = new \ReflectionClass($this);
        $props = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
        $map = [];
        foreach($props as $prop) {
            if($prop->isStatic()) continue;
            $map[$prop->getName()] = $prop->getValue($this);
        }
        return $map;
    }
    
    public function jsonSerialize() {
        return $this->toMap();
    }
}


//-- exceptions
class NoDepException extends \RuntimeException { }

//-- functions

function strStartsWith($str, $prefix) {
    return strpos($str, $prefix) === 0;
}

function snakeToCamel(string $k) : string {
    return preg_replace_callback('/_([a-z])/', fn($m) => strtoupper($m[1]), $k);
}

function mysqlStyleEscaper(string $k) : string {
    if(preg_match("/('|--)/", $k))
        throw new \InvalidArgumentException("SQL标识符中包含敏感内容");
    return implode('.', array_map(fn($i) => "`{$i}`", explode('.', $k)));
}

function mustInDir(string $dir, string $file) : bool {
    $dir = realpath($dir);
    $file = realpath($file);
    
    if(! strStartsWith($file, $dir))
        throw new \InvalidArgumentException("insecure file access: {$file}");
    return true;
}

function onError(\Throwable $ex){
    $class = get_class($ex);
    echo '<pre style="font-size: 13px;">';
    echo "<b>{$class}</b>: {$ex->getMessage()} in ";
    echo "{$ex->getFile()}:{$ex->getLine()}\n";
    
    echo "<b>Stack Trace:</b>\n";
    echo $ex->getTraceAsString();
    
    exit($ex->getCode());
}

function query($key, $default = null) {
    return $_GET[$key] ?? $default;
}

function mustQuery($key) {
    $val = query($key);
    assert($val !== null && $val !== "", "\$_GET key required: {$key}");
    return $val;
}

function form($key, $default = null) {
    return $_POST[$key] ?? $default;
}

function mustForm($key) {
    $val = form($key);
    assert($val !== null && $val !== "", "\$_POST key required: {$key}");
    return $val;
}

function rawPost() {
    return file_get_contents("php://input");
}

function cookie() {
    $vars = func_get_args();
    switch (count($vars)) {
        case 0:
            return $_COOKIE;
        case 1:
            return $_COOKIE[$vars[0]] ?? null;
        default:
            return call_user_func_array("setcookie", $vars);
    }
}

function session() {
    $vars = func_get_args();
    switch (count($vars)) {
        case 0:
            return $_SESSION;
        case 1:
            return $_SESSION[$vars[0]] ?? null;
        default:
            $_SESSION[$vars[0]] = $_SESSION[$vars[1]];
    }
    
    return null;
}

function load($instance, array $data, array ...$extra) : array {
    $data = array_merge($data, ...$extra);
    $reflect = new \ReflectionClass($instance);
    $props = $reflect->getProperties(\ReflectionProperty::IS_PUBLIC);
    foreach ($props as $prop) {
        if($prop->isStatic()) continue;
        if(isset($data[$prop->name])) {
            $instance->{$prop->name} = $data[$prop->name];
        }
    }
    
    return $instance;
}

function redirect(string $href, $die = true) {
    header('Location: '.$href);
    if($die) exit(0);
}

function view(string $viewFile, array ...$data) : string {
    $viewer = function() {
        ob_start();
        extract(func_get_arg(1));
        require(func_get_arg(0));
        return ob_get_clean();
    };
    
    return $viewer($viewFile, array_merge(...$data));
}

function router(array $routes, string $path) {
    return $routes[$path] ?? null;
}

function json($data, $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) {
    return json_encode($data, $flags);
}

function import($class, $dir) {
    $file = $dir.'/'.str_replace('\\', '/', $class).'.php';
    if(is_file($file) && mustInDir($dir, $file))
        return include($file);
    return null;
}

function log($msg) {
    error_log($msg, 4);
}

spl_autoload_register(function ($class){
    import($class, __DIR__);
    if(!class_exists($class, false))
        import($class, __DIR__ . "/libs");
});
