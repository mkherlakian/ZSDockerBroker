<?php

namespace ZSDockerBroker;


class Variables {
    
    /**
     * @var string
     */
    protected $apiKeyName;

    /**
     * @var string
     */
    protected $apiKeySecret;
    
    /**
     * @var string
     */
    protected $cluster;

    /**
     * @var string
     */
    protected $dbhost;

    /**
     * @var string
     */
    protected $dbuser;
    
    /**
     * @var string
     */
    protected $dbpassword;
    
    /**
     * @var string
     */
    protected $dbname;

    /**
     * @var string
     */
    protected $clusterGroup;

    public function __construct($apiKeyName, $apiKeySecret, $clusterGroup, $dbhost, $dbuser, $dbpassword, $dbname) {
        $this->apiKeyName = $apiKeyName;
        $this->apiKeySecret = $apiKeySecret;
        $this->dbhost = $dbhost;
        $this->dbuser = $dbuser;
        $this->dbpassword = $dbpassword;
        $this->dbname = $dbname;
        $this->clusterGroup = $clusterGroup;
    }

    /**
     * @var bool throw exception if var is not in ENV vars
     */
    public static function factoryFromEnv() {
        $vars = array('ZSAPINAME', 'ZSAPISECRET', 'DBHOST', 'DBNAME', 'DBUSER', 'DBPASS', 'ZSCLUSTERGROUP');
        $notFound = array();
        foreach($vars as $var) {
            if(!getenv($var))
                $notFound[] = $var;
        }

        if(count($notFound) > 0) {
            throw new \Exception("Environment variables ".implode(', ', $notFound)." must be set.");
        }

        return new self(
            getenv('ZSAPINAME'),
            getenv('ZSAPISECRET'),
            getenv('ZSCLUSTERGROUP'),
            getenv('DBHOST'),
            getenv('DBUSER'),
            getenv('DBPASS'),
            getenv('DBNAME')
        );
    }

    public function getApiKeyname() {
        return $this->apiKeyName;
    }

    public function getApiKeySecret() {
        return $this->apiKeySecret;
    }

    public function getDbHost() {
        return $this->dbhost;
    }

    public function getDbUser() {
        return $this->dbuser;
    }

    public function getDbPassword() {
        return $this->dbpassword;
    }

    public function getDbName() {
       return $this->dbname;
    }

    public function getClusterGroup() {
        return $this->clusterGroup;
    }
}
