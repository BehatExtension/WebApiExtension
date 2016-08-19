<?php

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../../vendor/autoload.php';

$app = new Silex\Application();

$app->match(
    'echo',
    function (Request $req) {
        $ret = [
            'warning' => 'Do not expose this service in production : it is intrinsically unsafe',
        ];

        $ret['method'] = $req->getMethod();

        // Forms should be read from request, other data straight from input.
        $requestData = $req->request->all();
        $ret = array_merge(
            $ret,
            $requestData
        );

        $content = $req->getContent(false);
        if (!empty($content)) {
            $data = json_decode($content, true);
            if (!is_array($data)) {
                $ret['content'] = $content;
            } else {
                $ret = array_merge(
                    $ret,
                    $data
                );
            }
        }

        $ret['headers'] = $req->headers->all();
        $ret['query'] = $req->query->all();

        return new JsonResponse($ret);
    }
);

$app->run();
