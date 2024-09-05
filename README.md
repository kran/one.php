```php
<?php

define('APP', dirname(__DIR__).'/app');
require(APP . "/one.php");

$app = new \one\One([
    'config' => function() {
        return include(APP."/config.php");
    },
    'respJson' => function($json) {
        return function($data) use ($json) {
            header("Content-Type: application/json");
            echo $json($data);
        };
    },
    'render' => function() {
        return function ($template, ...$data) {
            return $this->view(APP. "/view/" . $template . '.php', ...$data);
        };
    },
    'dao' => function() {
        $pdo = new PDO("sqlite:".APP."/one.sqlite");
        $dal = new \one\Dao($pdo, APP. "/sql");
        return $dal;
    },
    '#now' => function() {
        return time();
    }
]);


$app->group("/admin", function () {
    $this->route("/", function (\one\Dao $dao) {
        echo $this->render("admin/index");
    });
    
    $this->route("/model", function ($dao) {
        $models = $dao->sql('select * from model')->list(NodeModel::class);
        echo $this->render("admin/model", ["models" => $models]);
    });
    
    $this->route('/create', function (\one\Dao $dao) {
        $stmt = $dao->file('insertNode', [
            'pid' => 0,
            'modelId' => 2,
            'lang' => 'en',
            'data' => [
                'a' => 1,
                'b' => 2,
            ]
        ])->execute();
        
        $affected = $stmt->rowCount();
        $lastId = $dao->lastId();
        
        $this->respJson(['row' => $affected, 'lastId' => $lastId]);
    });
});

$app->run($_GET['r'] ?? "/");

```
