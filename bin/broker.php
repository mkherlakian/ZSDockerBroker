<?php

include_once __DIR__ . '/../vendor/autoload.php';


$client = new Docker\Http\Client('unix:///docker.sock');
$docker = new Docker\Docker($client);

$clusterOps = new ZSDockerBroker\ClusterOperations('/usr/local/zend/zs-client');

$broker = new ZSDockerBroker\ZSDockerBroker($docker, $clusterOps);

$broker->run();
