<?php declare(strict_types=1);

if (!is_file($releaseTypeFN = (__DIR__ . '/release_type'))) {
    echo 'release_type is not found in the root of the project';
    exit;
}

define('RELEASE_TYPE', file_get_contents($releaseTypeFN));

if (!is_file($releaseTypeFN = (__DIR__ . '/config/' . RELEASE_TYPE . '.inc.php'))) {
    echo 'release config is not found in ' . $releaseTypeFN;
    exit;
}
require $releaseTypeFN;
unset($releaseTypeFN);

if (!defined('WORKLOGS_PATH')) {
    define('WORKLOGS_PATH', dirname(__DIR__, 2) . '/php-worklogs');
}

// Internal Exceptions error codes
const INT_EXC_API_ERROR = -97;
const INT_EXC_DB = -98;
const INT_EXC_FATAL = -99;

const INDEXER_DB_TABLES = ['checkpoints', 'domains', 'domains_history', 'params', 'marketplace'];

// params
const PARAM_LAST_PROCESSED_EVM_BLOCK = 'last_processed_evm_block';
const PARAM_LAST_PROCESSED_PIVX_BLOCK = 'last_processed_pivx_block';

// API Errors
const API_ERROR_INTERNAL = 'Internal Error';
const API_ERROR_WRONG_API_METHOD = 'Wrong API method';
const API_ERROR_INVALID_INCOMING_JSON = 'Invalid incoming JSON';
const API_ERROR_WRONG_INCOMING_PARAM_VALUE = 'One or several params provided have wrong value';
const API_ERROR_NOT_FOUND = 'Domain not found';

// DEBUG (write debug log)
if (!defined('DEBUG')) {
    define('DEBUG', true);
}
