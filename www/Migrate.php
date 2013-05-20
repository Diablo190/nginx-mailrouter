<?php

use m8rge\CurlHelper;

class Migrate
{
    protected $oldScript = 'http://mailadmin.66.ru';
    protected $newScript = 'http://79.172.49.146';
    protected $oldWebmailScript = 'http://79.172.49.157/service.php';
    protected $newWebmailScript = 'http://79.172.49.103/service.php';

    public function migrateRules($username)
    {
        $oldRules = $this->getOldRules($username);
        foreach ($oldRules['data'] as $oldRule) {
            $ruleName = $oldRule[1];
            $rules = array();
            foreach ($oldRule[2] as $condition) {
                $rule = array(
                    $condition[0] => array(
                        'operation' => $condition[1],
                        'value' => $condition[2],
                    ),
                );
                $rules[] = $rule;
            }
            $actions = array();
            foreach ($oldRule[3] as $oldActions) {
                $action = array(
                    $oldActions[0] => $oldActions[1]
                );
                $actions[] = $action;
            }
            if (empty($rules) || empty($actions))
            $this->writeNewRule($username, $ruleName, $rules, $actions);
        }
    }

    public function migrateRpop($username)
    {
        $oldRpops = $this->getOldRpop($username);
        foreach ($oldRpops['data'] as $oldRpop) {
            $this->writeNewRpop($username, $oldRpop[2], $oldRpop[3], $oldRpop[4], empty($oldRpop[6]));
        }
    }

    public function migrateSettings($username)
    {
        $oldSettings = $this->getOldSettings($username);
        $this->writeNewSettings($username, $oldSettings);
    }

    protected function getOldSettings($username)
    {
        return CurlHelper::postUrl(
            $this->oldWebmailScript,
            array(
                'controller' => 'userOptions',
                'action' => 'get',
                'login' => $username,
                'secKey' => 'tr$FDer#$GSSD&%32s',
            )
        );
    }

    protected function writeNewSettings($username, $settings)
    {
        CurlHelper::postUrl(
            $this->newWebmailScript,
            array(
                'controller' => 'userOptions',
                'action' => 'get',
                'login' => $username,
                'secKey' => 'tr$FDer#$GSSD&%32s',
                'options' => json_encode($settings),
            )
        );
    }

    protected function getOldRules($username)
    {
        $data = CurlHelper::postUrl(
            $this->oldScript . '/msm-cli.cgi',
            array(
                'package' => 'user',
                'action' => 'getrules',
                'user' => $username,
            )
        );
        return unserialize($data);
    }

    protected function getOldRpop($username)
    {
        $data = CurlHelper::postUrl(
            $this->oldScript . '/msm-cli.cgi',
            array(
                'package' => 'user',
                'action' => 'showrpop',
                'user' => $username,
            )
        );
        return unserialize($data);
    }

    protected function writeNewRule($username, $ruleName, $rules, $actions)
    {
        CurlHelper::postUrl(
            $this->newScript . '/createRule',
            array(
                'userName' => $username,
                'ruleName' => $ruleName,
                'rules' => json_encode($rules),
                'actions' => json_encode($actions),
            )
        );
    }

    protected function writeNewRpop($username, $host, $email, $password, $delete = false)
    {
        CurlHelper::postUrl(
            $this->newScript . '/addGetMailRule',
            array(
                'userName' => $username,
                'host' => $host,
                'email' => $email,
                'password' => $password,
                'delete' => (int)$delete,
            )
        );
    }
}