<?php

require "vendor/autoload.php";

$client = new Docker\Http\Client('unix:///docker.sock');
$docker = new Docker\Docker($client);

$manager = $docker->getContainerManager();
foreach ($manager->findAll() as $container) {
    // $container is an instance of Docker\Container
    $manager->inspect($container);
    $ri = $container->getRuntimeInformations();
    var_dump($ri['Name']);
}

