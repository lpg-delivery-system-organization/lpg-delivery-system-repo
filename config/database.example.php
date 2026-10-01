<?php
/**
 * Database Configuration
 * LPG Delivery System v2
 */

return [
    'host' => '', // dito yung host
    'port' => 3306,
    'dbname' => '', // dito yung database name
    'username' => '', // dito yung username
    'password' => '', // dito yung password
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
];