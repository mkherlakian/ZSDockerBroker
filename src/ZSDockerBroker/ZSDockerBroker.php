<?php

namespace ZSDockerBroker;

use Zend\Cache\StorageFactory,
    ZSDockerBroker\Variables,
    ZSDockerBroker\Node,
    Zend\Log\Logger,
    Zend\Log\Writer\Stream as LoggerStream;

class ZSDockerBroker {
    /**
     * @var Docker/Docker Holds a Docker client instance
     */
    protected $dockerClient;

    /**
     * @var Zend\Cache To store the runtime info
     */
    protected $cache;

    /**
     * @var
     */
    protected $clusterActions;

    /**
     * @var string The Env variable to look for to determine if a ZS container
     *             should be joined to a cluster
     */
    protected $key = 'ZSCLUSTER';
    
    /**
     * @var ZSDockerBroker\Variables
     */
    protected $variables;

    /**
     * @var string Lock file to use for blocking operations
     */
    protected $lockFile = '/var/run/broker.lock';

    /**
     * @var ZSDockerBroker\ClusterOperations
     */
    protected $clusterOperations;
    
    /**
     * @var Zend\Log\Logger
     */
    protected $logger;


    public function __construct(\Docker\Docker $dockerClient, \ZSDockerBroker\ClusterOperations $co) {
        $this->dockerClient = $dockerClient;
        $this->clusterOperations = $co;
    }

    public function getCache() {
        if(is_null($this->cache)) {
            $this->cache = StorageFactory::factory(array(
                'adapter' => array(
                    'name' => 'filesystem',
                ),
                'plugins' => array(
                    'Serializer'
                )
            )); 
        }
        return $this->cache;
    }
    
    public function getLog() {
        if(is_null($this->logger)) {
            $this->logger = new Logger();
            $writer = new LoggerStream('php://output');
            $this->logger->addWriter($writer);
        }
        return $this->logger;
    }

    public function getVariables() {
        if(is_null($this->variables)) {
            $this->variables = Variables::factoryFromEnv();
        }
        return $this->variables;
    }

    /**
     * Go into an endless loop and join/unjoin any nodes
     * encoutered
     */
    public function run() {
        $variables = $this->getVariables();        

        //2 steps: check if any servers we don't have in list, and check if any we have that are not there anymore
        $containers = $this->retrieveZSContainers();        
        $nextContainer = $this->getNextUnaddedContainer($containers);
        if($nextContainer) {
            //var_dump($nextContainer);
            //var_dump($this->getCache()->getItem('zslist'));
            $this->getLog()->log(Logger::INFO, "Found a new container - ID: {$nextContainer['id']}, trying to join cluster...");
            
            $joinParameters = array(
                'servername' => $nextContainer['name'],
                'dbhost'     => $variables->getDbHost(),
                'dbuser'     => $variables->getDbUser(),
                'dbpass'     => $variables->getDbPassword(),
                'nodeip'     => $nextContainer['ip'],
                'dbname'     => $variables->getDbName(),
                'zsurl'      => "http://{$nextContainer['ip']}:10081/ZendServer",
                'zskey'      => $variables->getApiKeyname(),
                'zssecret'   => $variables->getApiKeysecret(),
            ); 
            $this->clusterOperations->joinCluster($joinParameters);
        }
         
        //var_dump($this->getCache()->getItem('zslist'));
        //$this->clusterOperations->joinCluster(array());
        

        //var_dump($containers);

        /*
        while(1) {
                                    
            
        }
        */
    }
    
    protected function getNextUnaddedContainer($containers) {
        //needs to be atomic - use by default a fs implementation (flock)
        //but provide possibility to use closure in the future
        $fp = fopen($this->lockFile, 'w');
        flock($fp, LOCK_EX);
        $nextContainer = false;

        $list = $this->getCache()->getItem('zslist');
        if(!is_array($list)) $list = array();

        foreach($containers as $container) {
            if(!in_array($container['id'], array_keys($list))) {
                 $nextContainer = $container;
                 break;
            }
        }
        
        $list[$nextContainer['id']] = Node::STATUS_JOINING;
 
        $this->getCache()->setItem('zslist', $list);
        flock($fp, LOCK_UN);

        return $nextContainer;
    }


    /**
     * @return array An array of conatiners that are Zend Server nodes
     */
    protected function retrieveZSContainers() {
        $containers = array();
        $manager = $this->dockerClient->getContainerManager();
        foreach($manager->findAll() as $container) {
            $manager->inspect($container);
            $runtimeInfo = $container->getRuntimeInformations();
            foreach($runtimeInfo['Config']['Env'] as $env) {
                if(stripos($env, $this->key) !== false) {
                    $matches = array();
                    preg_match("/{$this->key}=(.*)$/", $env, $matches);
                    
                    $containers[] = array(
                        'id' => $container->getId(),
                        'cluster' => $matches[1],
                        'name'    => preg_replace('/\W+/', '', $runtimeInfo['Name']),
                        'ip'      => $runtimeInfo['NetworkSettings']['IPAddress'],
                        'container' => $container
                    );
                }
            }
        }
        return $containers;
    }
}
