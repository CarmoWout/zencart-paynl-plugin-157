<?php

/**
 * Pay. logging helper voor Zencart 1.5.7d
 *
 * Gebruik:
 *   paynl_log('IDEAL', 'after_process', 'Transaction started', ['transactionId' => 'EX-...']);
 *
 * Debug logging wordt alleen geschreven als MODULE_PAYMENT_PAYNL_{METHOD}_DEBUG_LOG === 'True'.
 * Fouten (level 'ERROR') worden altijd geschreven, ongeacht de debug-instelling.
 *
 * Logbestand: <zencart-root>/logs/paynl_YYYY-MM-DD.log
 *
 * Volgorde voor het bepalen van de logmap:
 *   1. DIR_FS_LOGS   – standaard Zencart 1.5.7 constante (configure.php)
 *   2. DIR_FS_CATALOG . 'logs'  – als DIR_FS_LOGS niet gedefinieerd is
 *   3. sys_get_temp_dir()       – absolute noodoplossing
 */

if (!function_exists('paynl_log')) {

    function paynl_log($method, $context, $message, $data = array(), $level = 'DEBUG')
    {
        $level = strtoupper($level);

        // Fouten altijd loggen; debug alleen als debug-log aan staat
        if ($level !== 'ERROR') {
            $configKey = 'MODULE_PAYMENT_PAYNL_' . strtoupper($method) . '_DEBUG_LOG';
            if (!defined($configKey) || constant($configKey) !== 'True') {
                return;
            }
        }

        // Bepaal logmap – gebruik Zencart's eigen logs-map
        if (defined('DIR_FS_LOGS') && DIR_FS_LOGS !== '') {
            $logDir = rtrim(DIR_FS_LOGS, '/\\');
        } elseif (defined('DIR_FS_CATALOG') && DIR_FS_CATALOG !== '') {
            $logDir = rtrim(DIR_FS_CATALOG, '/\\') . DIRECTORY_SEPARATOR . 'logs';
        } else {
            $logDir = sys_get_temp_dir();
        }

        // Maak de map aan als die nog niet bestaat
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Schrijf alleen als de map beschrijfbaar is
        if (!is_writable($logDir)) {
            return;
        }

        $logFile = $logDir . DIRECTORY_SEPARATOR . 'paynl_' . date('Y-m-d') . '.log';

        $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] [' . strtoupper($method) . '] [' . $context . '] ' . $message;

        if (!empty($data)) {
            $line .= ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $line .= PHP_EOL;

        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

}
