<?php

use m8rge\CurlHelper;

class Migrate
{
    protected $oldScript = 'http://mailadmin.66.ru';
    protected $newScript = 'http://79.172.49.70';
    protected $oldWebmailScript = 'http://79.172.49.157/service.php';
    protected $newWebmailScript = 'http://79.172.49.103/service.php';

    public function migrateRules($username)
    {
        $oldRules = $this->getOldRules($username);
        foreach ($oldRules['data'] as $oldRule) {
            $ruleName = $oldRule[1];
            if (preg_match('/rule\d+_\d/', $ruleName)) { // unnecessary copy
                continue;
            }
            $rules = array();
            foreach ($oldRule[2] as $condition) {
                $rules[$condition[0]] = array(
                    'operation' => $condition[1],
                    'value' => $condition[2],
                );
            }
            $actions = array();
            foreach ($oldRule[3] as $oldActions) {
                $actions[ $oldActions[0] ] = $oldActions[1];
            }
            $actions = $this->postProcessActions($actions);
            $rules = $this->postProcessRules($rules);

            if (!empty($rules) && !empty($actions))
                $this->writeNewRule($username, $ruleName, $rules, $actions);
        }
    }

    public function postProcessActions($actions)
    {
        if (count($actions) > 1 && array_key_exists('Discard', $actions)) {
            unset($actions['Discard']);
        }

        return $actions;
    }

    public function postProcessRules($rules)
    {
        if (!empty($rules['Header Field']) && $rules['Header Field']['value'] == 'X-Spam-Flag: YES') {
            $rules['X-Spam-Flag'] = array(
                'operation' => 'is',
                'value' => 'YES',
            );
            unset($rules['Header Field']);
        }

        return $rules;
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
        $oldSettings = json_decode($oldSettings, true);
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
                'secKey' => 'tr$FDer#$GSSD%32s',
            )
        );
    }

    protected function writeNewSettings($username, $settings)
    {
        $answer = CurlHelper::postUrl(
            $this->newWebmailScript,
            array(
                'controller' => 'userOptions',
                'action' => 'set',
                'login' => $username,
                'secKey' => 'tr$FDer#$GSSD%32s',
                'options' => json_encode($settings),
            )
        );
        $answer = json_decode($answer, true);

        if (!is_bool($answer) || $answer == false)
            throw new Exception('error while saving '.$username.' settings ('.json_encode($settings).')');
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
        try {
            $answer = CurlHelper::postUrl(
                $this->newScript . '/createRule',
                array(
                    'userName' => $username,
                    'ruleName' => $ruleName,
                    'rules' => json_encode($rules),
                    'actions' => json_encode($actions),
                )
            );
        } catch (\m8rge\CurlException $e) {
            $answer = $e->getData();
        }
        $answer = json_decode($answer, true);
        if ($answer['status'] == 'error')
            throw new Exception($answer['message']);

        $answer = CurlHelper::postUrl(
            $this->newWebmailScript,
            array(
                'controller' => 'userOptions',
                'action' => 'filtersSet',
                'login' => $username,
                'secKey' => 'tr$FDer#$GSSD%32s',
                'ruleName' => $ruleName,
                'rules' => json_encode($rules),
                'actions' => json_encode($actions),
            )
        );
        $answer = json_decode($answer, true);

        if (!is_bool($answer) || $answer == false)
            throw new Exception('error while saving '.$username.' rules ('.json_encode($rules).') and actions('.json_encode($actions).')');
    }

    protected function writeNewRpop($username, $host, $email, $password, $delete = false)
    {
        try {
            $answer = CurlHelper::postUrl(
                $this->newScript . '/addGetMailRule',
                array(
                    'userName' => $username,
                    'host' => $host,
                    'email' => $email,
                    'password' => $password,
                    'delete' => (int)$delete,
                )
            );
        } catch (\m8rge\CurlException $e) {
            $answer = $e->getData();
        }
        $answer = json_decode($answer, true);

        if ($answer['status'] == 'error')
            throw new Exception($answer['message']);
    }
}