<?php

require_once(__DIR__ . '/../vendor/autoload.php');

class App
{
    public $params;

    /**
     * @var Raven_Client
     */
    public $ravenClient;

    function __construct()
    {
        $this->params = require(__DIR__ . '/../config/config.php');

        $this->installErrorHandler();
    }

    protected function installErrorHandler()
    {
        $this->ravenClient = new Raven_Client($this->params['sentryDSN']);

        $error_handler = new Raven_ErrorHandler($this->ravenClient);
        $error_handler->registerExceptionHandler();
        $error_handler->registerErrorHandler();
        $error_handler->registerShutdownFunction();
    }

    /**
     * @return bool
     */
    protected function mysqlConnect()
    {
        $res = mysql_connect($this->params['mysqlHost'], $this->params['mysqlLogin'], $this->params['mysqlPassword']);
        if (!$res) {
            $this->ravenClient->captureMessage(mysql_error(), array(), Raven_Client::ERROR);
            return false;
        }
        $res = mysql_select_db($this->params['mysqlDB']);
        if (!$res) {
            $this->ravenClient->captureMessage(mysql_error(), array(), Raven_Client::ERROR);
            return false;
        }

        return true;
    }

    /**
     * @param $username
     * @return bool
     */
    protected function mysqlMarkUserToNewMailbackend($username)
    {
        $username = mysql_real_escape_string($username);
        $res = mysql_query("INSERT INTO mail_router (username) VALUES ('$username')");
        if (!$res) {
            $this->ravenClient->captureMessage(mysql_error(), array(), Raven_Client::ERROR);
            return false;
        }

        return true;
    }

    /**
     * @param $username
     * @return bool
     */
    protected function mysqlIsUserOnNewMailbackend($username)
    {
        $username = mysql_real_escape_string($username);
        $res = mysql_query("SELECT username FROM mail_router WHERE username = '$username'");
        if (!$res) {
            $this->ravenClient->captureMessage(mysql_error(), array(), Raven_Client::ERROR);
            return false;
        }

        return mysql_num_rows($res) > 0;
    }

    /**
     * @param $username
     * @return bool
     */
    protected function mysqlMarkUserToOldMailbackend($username)
    {
        $username = mysql_real_escape_string($username);
        $res = mysql_query("DELETE FROM mail_router where username = '$username'");
        if (!$res) {
            $this->ravenClient->captureMessage(mysql_error(), array(), Raven_Client::ERROR);
            return false;
        }

        return true;
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

        $mailHost = $this->params['oldMailServerIp'];
        if (strpos($_SERVER['HTTP_AUTH_USER'], '@') == false)
            $key = $_SERVER['HTTP_AUTH_USER'] . '@' . $this->params['mailDomain'];
        else
            $key = $_SERVER['HTTP_AUTH_USER'];

        if ($this->mysqlConnect() && $this->mysqlIsUserOnNewMailbackend($key)) {
            $ips = $this->params['newMailServerIps'];
            $i = rand(0, count($ips)-1);
            $mailHost = $ips[$i];
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

    public function isUserOnNewBackend()
    {
        if (!$this->mysqlConnect()) {
            echo json_encode(false);
            return;
        }
        $key = $_GET['username'] . '@' . $this->params['mailDomain'];
        echo json_encode($this->mysqlIsUserOnNewMailbackend($key));
    }

    public function setUserOnNewBackend()
    {
        if (!$this->mysqlConnect()) {
            echo json_encode(false);
            return;
        }

        $key = $_GET['username'] . '@' . $this->params['mailDomain'];
        if ($_GET['set'] == 1) {
            echo json_encode($this->mysqlMarkUserToNewMailbackend($key));
        } elseif ($_GET['set'] == 0) {
            echo json_encode($this->mysqlMarkUserToOldMailbackend($key));
        }
    }

    public function migrateUserOnNewBackend()
    {
        require_once(__DIR__.'/Migrate.php');
        $m = new Migrate($this->params['newWebmailSecKey']);
        try {
            if (isset($_GET['only'])) {
                if ($_GET['only'] == 'rules') {
                    $m->migrateRules($_GET['username']);
                } elseif ($_GET['only'] == 'rpop') {
                    $m->migrateRpop($_GET['username']);
                } elseif ($_GET['only'] == 'options') {
                    $m->migrateSettings($_GET['username']);
                } else {
                    $this->ravenClient->captureMessage('bad only flag');
                    echo json_encode(false);
                    return;
                }
            } else {
                $m->migrateRules($_GET['username']);
                $m->migrateRpop($_GET['username']);
                $m->migrateSettings($_GET['username']);
            }
        } catch (\m8rge\CurlException $e) {
            $this->ravenClient->captureException($e);
            echo json_encode(false);
            return;
        }
        echo json_encode(true);
    }
}

$app = new App();

if (!empty($_GET['username']) && isset($_GET['migrate'])) {
    $app->migrateUserOnNewBackend();
} else if (!empty($_GET['username']) && isset($_GET['set'])) {
    $app->setUserOnNewBackend();
} elseif (!empty($_GET['username'])) {
    $app->isUserOnNewBackend();
} else {
    $app->popImapProxy();
}
