#!/bin/php
<?php
/**
 * Configuration for IP Inventory API PHP tests
 */
return [
    'base_url' => getenv('IPINVENTORY_API_URL') ?: 'http://viva.greenhamr.org',
];
