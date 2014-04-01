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

    public function __construct($apiKeyName, $apiKeySecret, $cluster, $dbhost, $dbuser, $dbpassword, $dbname) {
        $this->apiKeyName = $apiKeyName;
        $this->apiKeySecret = $apiKeySecret;
        $this->cluster = $cluster;
        $this->dbhost = $dbhost;
        $this->dbuser = $dbuser;
        $this->dbpassword = $dbpassword;
        $this->dbname = $dbname;
    }

    /**
     * @var bool throw exception if var is not in ENV vars
     */
    public static function factoryFromEnv() {
        $vars = array('ZSAPINAME', 'ZSAPISECRET', 'ZSCLUSTER', 'DBHOST', 'DBNAME', 'DBUSER', 'DBPASS');
        foreach($vars as $var) {
            $notFound = array();
            if(!getenv($var))
                $notFound[] = $var;
        }

        if(count($notFound) > 0) {
            throw new Exception("Environment variables ".implode(', ', $notFound)." must be set.");
        }

        return new self(
            getenv('ZSAPINAME'),
            getenv('ZSAPISECRET'),
            getenv('ZSCLUSTER'),
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

    public function getCluster() {
        return $this->cluster;
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
}
