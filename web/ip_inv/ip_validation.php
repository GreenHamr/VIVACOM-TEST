<?php
/**
 * IP validation helpers (same semantics as C++ ip_validation).
 */

function ip_inv_is_valid_ipv4($ip) {
    if ($ip === '' || strlen($ip) > 15) return false;
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function ip_inv_is_valid_ipv6($ip) {
    if ($ip === '' || strlen($ip) > 45) return false;
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}

function ip_inv_is_valid_ip_with_type($ip, $ipType) {
    if ($ipType === 'IPv4') return ip_inv_is_valid_ipv4($ip);
    if ($ipType === 'IPv6') return ip_inv_is_valid_ipv6($ip);
    return false;
}
