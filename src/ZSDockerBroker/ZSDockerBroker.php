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

        while (1) {
            //2 steps: check if any servers we don't have in list, and check if any we have that are not there anymore
            $containers = $this->retrieveZSContainers();        
            $nextContainer = $this->getNextUnaddedContainer($containers, $variables);

            if($nextContainer) {
                //var_dump($nextContainer);
                //var_dump($this->getCache()->getItem('zslist'));
                $this->getLog()->log(Logger::INFO, "Found a new container - ID: {$nextContainer['id']}, Checking if bootstrapped...");
              
                //Check if container is ready
                if($this->isServerReady($nextContainer['ip'])) {
                    //Check if server is bootstrapped
                    $this->getLog()->log(Logger::INFO, "{$nextContainer['id']}, trying to join cluster...");
                    $bootstrappedParams = array(
                        'zsurl'      => $this->getServerUrl($nextContainer['ip']), 
                        'zskey'      => $variables->getApiKeyname(),
                        'zssecret'   => $variables->getApiKeysecret()
                    );

                    if($this->clusterOperations->isServerBootstrappedAndReady($bootstrappedParams)) {
                        $joinParameters = array(
                            'servername' => $nextContainer['name'],
                            'dbhost'     => $variables->getDbHost(),
                            'dbuser'     => $variables->getDbUser(),
                            'dbpass'     => $variables->getDbPassword(),
                            'nodeip'     => $nextContainer['ip'],
                            'dbname'     => $variables->getDbName(),
                            'zsurl'      => $this->getServerUrl($nextContainer['ip']), 
                            'zskey'      => $variables->getApiKeyname(),
                            'zssecret'   => $variables->getApiKeysecret(),
                        ); 
                        $serverInfo = array();
                        $success = $this->clusterOperations->joinCluster($joinParameters, $serverInfo);
                        $status = $success ? Node::STATUS_JOINED : Node::JOIN_ERROR;

                        $this->getLog()->log(Logger::INFO, "Setting status $status, clusterid {$serverInfo['clusterid']} for container {$nextContainer['id']} ({$nextContainer['name']})");
                        $this->setContainerInfo($nextContainer, array('status' => $status, 'clusterid' => $serverInfo['clusterid']));
                    }
                }
            }
 
            $removedServer = $this->getNextRemovedServer($containers);
            if($removedServer) {
                $this->getLog()->log(Logger::INFO, "Server {$removedServer['id']} ({$removedServer['name']}) was removed, removing from cluster...");
                
                //Server to eexcute the unjoin op against
                $randomContainer = $this->retrieveRandomJoinedServer($variables);
                $this->getLog()->log(Logger::INFO, "Using server {$randomContainer['ip']} ({$randomContainer['name']}) to remove server {$removedServer['id']} ({$removedServer['name']})");

                $unjoinParameters = array(
                    'serverid'   => $removedServer['clusterid'],
                    'zsurl'      => "http://{$randomContainer['ip']}:10081/ZendServer",
                    'zskey'      => $variables->getApiKeyname(),
                    'zssecret'   => $variables->getApiKeysecret(),
                );            
                
                $success = $this->clusterOperations->unjoinCluster($unjoinParameters);
                $status = $success ? Node::STATUS_UNJOINED : Node::UNJOIN_ERROR;

                $this->getLog()->log(Logger::INFO, "Setting status $status for container {$removedServer['id']} ({$removedServer['name']})");
                $this->setContainerInfo($removedServer, array('status' => $status));
            }

            $this->cleanUpList();
            usleep(500000); //sleep to avoid overwhelming the Docker server 
        }
    }
   
    protected function cleanUpList() {
        $fp = fopen($this->lockFile, 'w');
        flock($fp, LOCK_EX);

        $list = $this->getCache()->getItem('zslist');
        is_array($list) || $list = array();

        while(true) {
            $containerId = key($list);
            $container = current($list);

            if(!$container) {
                break;
            }

            $this->getLog()->log(Logger::DEBUG, "Checking if {$containerId} ({$container['name']}) needs to be cleaned up...");

            if($container['status'] == Node::STATUS_UNJOINED) {
                $this->getLog()->log(Logger::INFO, "Removing removed server {$container['id']} ({$container['name']}) from internal list");
                unset($list[$containerId]);
            }
            if(!next($list)) {
                break;
            }
        }

        $this->getCache()->setItem('zslist', $list);

        flock($fp, LOCK_UN);
        fclose($fp);

    }
 
    protected function setContainerInfo($container, array $info) {
        $fp = fopen($this->lockFile, 'w');
        flock($fp, LOCK_EX);
        
        $list = $this->getCache()->getItem('zslist');
        foreach($info as $k => $v) {
            $list[$container['id']][$k] = $v;
        }
        $this->getCache()->setItem('zslist', $list);

        flock($fp, LOCK_UN);
        fclose($fp);
    }   

    protected function retrieveRandomJoinedServer(\ZSDockerBroker\Variables $variables) {
        $fp = fopen($this->lockFile, 'w');
        flock($fp, LOCK_EX);
        $joinedContainer = false;        

        $list = $this->getCache()->getItem('zslist');
        $availableServers = '';
        foreach($list as $l) {
            $availableServers .= "{$l['id']}\t{$l['cluster']}\t{$l['status']}\t{$l['name']}\n";
        }
        $this->getLog()->log(Logger::INFO, "Available servers: \n$availableServers\n");

        foreach($list as $id => $container) {
            if($container['status'] == Node::STATUS_JOINED 
                    && $container['cluster'] = $variables->getClusterGroup()
                    && $container['name'] != 'zsbroker') {
                $joinedContainer = $container;
                break;
            }
        }

        flock($fp, LOCK_UN);
        fclose($fp);
        return $joinedContainer;
    }

    protected function isContainerForBrokerGroup($container, $variables) {
        $group = $variables->getClusterGroup();
        if($container['cluster']) {
            $this->getLog()->log(Logger::INFO, "Container {$container['id']} ({$container['name']}) Node group is {$container['cluster']}, broker group is {$group}");
        } else {
            $this->getLog()->log(Logger::INFO, "Container {$container['id']} ({$container['name']}) has no node group, probably not a ZS node"); 
        }
        return $container['cluster'] == $group;
    }
    
    /**
     * Get the next removed container
     * @var array
     */
    protected function getNextRemovedServer($containers) {
        $fp = fopen($this->lockFile, 'w');
        flock($fp, LOCK_EX);
        $nextRemove = false;
                

        $list = $this->getCache()->getItem('zslist');
        is_array($list) || $list = array();

        foreach($list as $id => $container) {
                if($container['status'] == Node::STATUS_JOINED && !array_key_exists($id, $containers)) {
                $nextRemove = $container;
                break;
            }
            $known = array_key_exists($id, $containers);

            $this->getLog()->log(Logger::INFO, sprintf("Skipping %s container {$container['id']} ({$container['name']}) with status {$container['status']}", $known ? "known" : "unknown"));
        }
        
        if($nextRemove) {
            $this->getLog()->log(Logger::DEBUG, "Setting status ".Node::STATUS_UNJOINING." for container {$container['id']} ({$container['name']})");
            $list[$nextRemove['id']]['status'] = Node::STATUS_UNJOINING;
            $this->getCache()->setItem('zslist', $list);
        }

        flock($fp, LOCK_UN);
        fclose($fp);
        return $nextRemove; 
    }

    /**
     * @param array
     * @param ZSDockerBroker\Variable
     */ 
    protected function getNextUnaddedContainer($containers, $variables) {
        //needs to be atomic - use by default a fs implementation (flock)
        //but provide possibility to use closure in the future
        $fp = fopen($this->lockFile, 'w');
        flock($fp, LOCK_EX);
        $nextContainer = false;

        $list = $this->getCache()->getItem('zslist');
        is_array($list) || $list = array();

        foreach($containers as $container) {
            if($this->isContainerForBrokerGroup($container, $variables) 
                    &&  (!in_array($container['id'], array_keys($list)) || 
                            (in_array($container['id'], array_keys($list)) && $list[$container['id']]['status'] == Node::STATUS_JOINING))
                    && $container['name'] != 'zsbroker') {
                 $nextContainer = $container;
                 break;
            }
            $this->getLog()->log(Logger::INFO, "Container {$container['id']} ({$container['name']}) is already known, skipping...");
        }

        if($nextContainer) {
            $nextContainer['status'] = Node::STATUS_JOINING;
         
            $list[$nextContainer['id']] = $nextContainer;
         
            $this->getCache()->setItem('zslist', $list);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return $nextContainer;
    }

    /**
     * @return array An array of containers that are Zend Server nodes
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
                    
                    $containers[$container->getId()] = array(
                        'id'        => $container->getId(),
                        'cluster'   => isset($matches[1]) ? $matches[1] : null,
                        'name'      => substr($runtimeInfo['Name'], 1),
                        'ip'        => $runtimeInfo['NetworkSettings']['IPAddress'],
                        'container' => $container,
                        'status'    => Node::STATUS_NEW,
                        'clusterid' => null
                    );
                }
            }
        }
        $this->getLog()->log(Logger::DEBUG, "Found ".count($containers)." containers.");
        return $containers;
    }

    protected function getServerUrl($ip) {
        return "http://$ip:10081/ZendServer";
    } 

    /**
     * Checks if a server is ready by attempting to connect to its gui port
     * @var string
     */
    public function isServerReady($ip) {
        $url = $this->getServerUrl($ip);
        $this->getLog()->log(Logger::INFO, "Initiating request to $url...");
        $c = curl_init();
        curl_setopt($c, CURLOPT_URL, $this->getServerUrl($ip));
        curl_setopt($c, CURLOPT_AUTOREFERER, true);
        curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 5);
        $success = @curl_exec($c);
        @curl_close($c);
        if(!$success) {
            $this->getLog()->log(Logger::INFO, "Server $url not ready, retry later");
        } else {
            $this->getLog()->log(Logger::INFO, "Server $url is ready!");
        }

        return $success;        
    }
}
