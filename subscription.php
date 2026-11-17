<?php
require_once __DIR__ . "/includes/auth.php";
geonet_require_login("subscriber");
$_GET["view"] = "subscription";
require __DIR__ . "/subscriber.php";
