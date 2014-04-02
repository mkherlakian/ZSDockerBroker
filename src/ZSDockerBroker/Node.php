<?php

namespace ZSDockerBroker;

class Node {
    const STATUS_JOINING = 1;

    const STATUS_JOINED = 2;

    const STATUS_UNJOINING = 3;

    const STATUS_UNJOINED = 4;

    const JOIN_ERROR = 5;

    const UNJOIN_ERROR = 6;

    const STATUS_NEW = 7;
}
