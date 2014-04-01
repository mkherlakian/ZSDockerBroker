<?php

namespace ZSDockerBroker;

use ZSDockerBroker\Exception\ArgumentsMissingException,
    Zend\Log\Logger,
    Zend\Log\Writer\Stream as LoggerStream;

class ClusterOperations {
    /**
     * @var string
     */
    protected $zsclient = '';

    /**
     * @var Zend\Log
     */
    protected $logger = null;
    
    public function __construct($sdkPath) {
        $this->zsclient = $sdkPath;
    }

    public function getLog() {
        if(is_null($this->logger)) {
            $this->logger = new Logger();
            $writer = new LoggerStream('php://output');
            $this->logger->addWriter($writer);
        }
        return $this->logger;
    }

    protected function validateArgs(array $defaults, array $required, array $params) {
        //Check that all required are present
        $diff = array_diff_key(array_flip($required), $params);

        if(count($diff) > 0) {
            throw new ArgumentsMissingException("Missing arguments in array: ".implode(', ', array_flip($diff)));
        }

        //Check that values in required args are not empty
        $empty = array();

        foreach($required as $arg) {
            if(!$params[$arg]) $empty[] = $required;
        }

        if(count($empty) > 0) {
            throw new ArgumentsMissingException("Argments must be non-empty: ".implode(", ", $empty));
        }

        return array_merge($defaults, $params);
    }

    public function joinCluster(array $params) {
        $defaults = array(
            'servername' => '', 
            'dbhost' => '',
            'dbuser' => '', 
            'dbpass' => '', 
            'nodeip' => '', 
            'dbname' => '', 
            'failifconnected' => false, 
            'target' => null, 
            'zsurl' => '', 
            'zskey' => '', 
            'zssecret' => '', 
            //'zsversion' => '6.1', 
            'http' => '', 
            'wait' => ''
        );

        $required = array('servername', 'dbhost', 'dbuser', 'dbpass', 'dbname', 'nodeip', 'zsurl', 'zskey', 'zssecret');
        $validated = $this->validateArgs($defaults ,$required, $params); 
        extract($validated);

        $command = "{$this->zsclient} serverAddToCluster --serverName=$servername --dbHost=$dbhost --dbUsername=$dbuser --dbPassword=$dbpass --nodeIp=$nodeip --dbname=$dbname --zsurl=$zsurl --zskey=$zskey --zssecret=$zssecret";
        $command .= " --failIfConnected=".($failifconnected?"true":"false");
        if(!is_null($target)) $command .= " --target=$target";
        if($http) $command .= " --http='$http'";
        if($wait) $command .= " --wait=$wait";

        $success = $this->runCommand($command);

        return $success;
    }
    
    public function unjoinCluster() {

    }

    protected function runCommand($command) {
        $desc = array(
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
        );
       
        $this->getLog()->log(Logger::DEBUG, "About to execute: $command"); 
        $p = proc_open($command, $desc, $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $return = proc_close($p);
        $this->getLog()->log(Logger::INFO, "Executed $command - return value was $return");
        $this->getLog()->log(Logger::DEBUG, "RET: $return OUT was: $out, ERR was: $err");
        
        if($return > 0) {
            $this->getLog(Logger::ERROR, "Command was not successful. RET: $return OUT was: $out, ERR was: $err");
        }
        
        return ($return == 0); 
    }
}
