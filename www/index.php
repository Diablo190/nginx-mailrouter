<?php

require_once(__DIR__ . '/../vendor/autoload.php');

class App
{
    public $params;

    function __construct()
    {
        $this->params = require(__DIR__ . '../config/config.php');

        $this->installErrorHandler();
    }

    protected function installErrorHandler()
    {
        $client = new Raven_Client($this->params['sentryDSN']);

        $error_handler = new Raven_ErrorHandler($client);
        $error_handler->registerExceptionHandler();
        $error_handler->registerErrorHandler();
        $error_handler->registerShutdownFunction();
    }

    public function run()
    {
        if (empty($_SERVER['HTTP_AUTH_USER']) ||
            empty($_SERVER['HTTP_AUTH_PROTOCOL']) ||
            !in_array($_SERVER['HTTP_AUTH_PROTOCOL'], array('imap', 'pop3'))
        ) {
            header('Auth-Status: Invalid login or password');
        }

        $redis = new Redis();
        $redis->connect('127.0.0.1');
        $mailHost = $this->params['oldMailServerIp'];
        if ($redis->exists($_SERVER['HTTP_AUTH_USER'])) {
            $mailHost = $this->params['newMailServerIp'];
        }
        if ($_SERVER['HTTP_AUTH_PROTOCOL'] == 'imap') {
            $mailPort = 143;
        } elseif ($_SERVER['HTTP_AUTH_PROTOCOL'] == 'pop3') {
            $mailPort = 110;
        }
        header("Auth-Status: OK");
        header("Auth-Server: " . $mailHost);
        header("Auth-Port: " . $mailPort);
    }
}

$app = new App();
$app->run();
