<?php

require_once(__DIR__ . '/../vendor/autoload.php');

class App
{
    public $params;

    function __construct()
    {
        $this->params = require(__DIR__ . '/../config/config.php');

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

    public function popImapProxy()
    {
        if (empty($_SERVER['HTTP_AUTH_USER']) ||
            empty($_SERVER['HTTP_AUTH_PROTOCOL']) ||
            !in_array($_SERVER['HTTP_AUTH_PROTOCOL'], array('imap', 'pop3'))
        ) {
            header('Auth-Status: Invalid login or password');
            return;
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
        } else {
            throw new Exception("Unknown auth protocol: " . $_SERVER['HTTP_AUTH_PROTOCOL']);
        }
        header("Auth-Status: OK");
        header("Auth-Server: " . $mailHost);
        header("Auth-Port: " . $mailPort);
    }

    public function smtpProxy()
    {
        $rcptTo = explode(':', $_SERVER['HTTP_AUTH_SMTP_TO']);
        $recipient = trim(end($rcptTo));
        list($login, $domen) = explode('@', $recipient);
        $redis = new Redis();
        $redis->connect('127.0.0.1');
        $mailHost = $this->params['oldMailServerIp'];
        if ($redis->exists($login)) {
            $mailHost = $this->params['newMailServerIp'];
        }
        header("Auth-Status: OK");
        header("Auth-Server: " . $mailHost);
        header("Auth-Port: 25");
    }

    public function isUserOnNewBackend()
    {
        $redis = new Redis();
        $redis->connect('127.0.0.1');
        echo json_encode($redis->exists($_GET['username']));
    }

    public function setUserOnNewBackend()
    {
        $redis = new Redis();
        $connected = $redis->connect('127.0.0.1');
        if (!$connected) {
            echo json_encode(false);
        }

        if ($_GET['set'] == 1) {
            echo json_encode($redis->set($_GET['username'], 1));
        } elseif ($_GET['set'] == 0) {
            echo json_encode((boolean)$redis->del($_GET['username']));
        }
    }
}

$app = new App();

if (!empty($_GET['username']) && isset($_GET['set'])) {
    $app->setUserOnNewBackend();
} elseif (!empty($_GET['username'])) {
    $app->isUserOnNewBackend();
} elseif (!empty($_SERVER['HTTP_AUTH_SMTP_TO'])) {
    $app->smtpProxy();
} else {
    $app->popImapProxy();
}
