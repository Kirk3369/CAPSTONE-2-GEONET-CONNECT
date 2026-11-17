<?php

return [
    "host" => getenv("DB_HOST") !== false ? getenv("DB_HOST") : "127.0.0.1",
    "port" => getenv("DB_PORT") !== false ? (int) getenv("DB_PORT") : 3306,
    "name" => getenv("DB_NAME") !== false ? getenv("DB_NAME") : "geonet_connect",
    "user" => getenv("DB_USER") !== false ? getenv("DB_USER") : "root",
    "pass" => getenv("DB_PASSWORD") !== false ? getenv("DB_PASSWORD") : "",
    "charset" => "utf8mb4",
    "ssl_ca" => getenv("DB_SSL_CA") ?: null,
];
