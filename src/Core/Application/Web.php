<?php
/**
 * Created by PhpStorm.
 * User: IceQi
 * Date: 2016/3/15
 * Time: 14:59
 */

namespace Kerisy\Core\Application;

use Kerisy\Core\Dispatcher;
use Kerisy\Core\Route;
use Kerisy\Di\Container;
use Kerisy\Log\Logger;
use Kerisy\Http\Request;
use Kerisy\Http\Response;
use Kerisy\Database\Database;
use Kerisy\Core\Application;

class Web extends Application
{


    protected function registerRoutes()
    {
        $this->dispatcher = new Dispatcher();
        $this->dispatcher->getRouter()->setConfig($this->config('routes')->all());
    }


    public function makeRequest($config = [])
    {
        $request = $this->get('request');

        foreach ($config as $name => $value) {
            $request->$name = $value;
        }

        return $request;
    }


    /**
     * @param Request $request
     * @return mixed
     * @throws \Exception
     */
    public function handleRequest($request)
    {
        /** @var Response $response */
        $response = $this->get('response');

        try {
            $this->exec($request, $response);
        } catch (\Exception $e) {
            $response->data = $e;
            $this->get('errorHandler')->handleException($e);
        }

        try {
            $response->callMiddleware();
        } catch (\Exception $e) {
            $response->data = $e;
        }

        $this->formatException($response->data, $response);

        $response->prepare();
        $this->refreshComponents();

        return $response;
    }



    protected function formatException($e, $response)
    {
        if (!$response->data instanceof \Exception) {
            return;
        }

        if ($e instanceof HttpException) {
            $response->status($e->statusCode);
            $response->data = $this->exceptionToArray($e);
        } else {
            if ($this->environment === 'test') {
                throw $e;
            }

            $response->status(500);
            $response->data = $this->exceptionToArray($e);
        }
    }

    protected function exec(Request $request, Response $response)
    {
        $route = $this->dispatch($request);

        $request->setRoute($route);

        $action = $this->createAction($route);

        // 中止继续访问
        if ($request->abort == true) {
            return;
        }

        $request->callMiddleware();

        $response->setPrefix($route->getPrefix());

        $data = $this->runAction($action, $request, $response);

        if (!$data instanceof Response && $data !== null) {
            $response->with($data);
        }
    }

    protected function refreshComponents()
    {
        foreach ($this->refreshing as $id => $_) {
            $this->unbind($id);
            $this->bind($id, $this->components[$id]);
        }
    }


    protected function exceptionToArray(\Exception $exception)
    {
        $array = [
            'name' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'http_status' => $exception->getCode(),
            'msg' => '异常请求'
        ];

        if ($exception instanceof HttpException) {
            $array['status'] = $exception->statusCode;
        }

        if ($this->debug) {
            $array['file'] = $exception->getFile();
            $array['line'] = $exception->getLine();
            $array['trace'] = explode("\n", $exception->getTraceAsString());
        }

        if (($prev = $exception->getPrevious()) !== null) {
            $array['previous'] = $this->exceptionToArray($prev);
        }

        return $array;
    }

    protected function dispatch($request)
    {
        if (!$route = $this->dispatcher->dispatch($request)) {
            throw new HttpException(404);
        }

        return $route;
    }

    protected function createAction(Route $route)
    {
        $class = "App\\" . ucfirst($route->getModule()) . "\\Controller\\" . ucfirst($route->getPrefix()) . "\\" . ucfirst($route->getController()) . "Controller";

        $method = $route->getAction();

        $controller = $this->get($class);

        $controller->callMiddleware();

        $action = [$controller, $method];

        return $action;
    }

    protected function runAction($action, $request, $response)
    {
        $data = call_user_func_array($action, [$request, $response]);

        return $data;
    }

}