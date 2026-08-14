<?php
declare(strict_types=1);

require __DIR__ . '/app/PeerNode.php';

$peer = new CdnDrive\PeerNode(__DIR__);
$peer->handleHttp();
