<?php


include_once __DIR__ . '/../vendor/autoload.php';

$clusterOps = new ZSDockerBroker\ClusterOperations('/usr/local/zend/zs-client');
$variables = ZSDockerBroker\Variables::factoryFromEnv();

$clusterOps->joinCluster(array(
    'servername' => 'zend-server-1',
    'dbhost'     => $variables->getDbHost(),
    'dbuser'     => $variables->getDbUser(),
    'dbpass'     => $variables->getDbPassword(),
    'nodeip'     => '172.17.0.8',
    'dbname'     => $variables->getDbName(), 
    'zsurl'      => 'http://172.17.0.8:10081/ZendServer',
    'zskey'      => $variables->getApiKeyname(),
    'zssecret'   => $variables->getApiKeysecret(),
));
