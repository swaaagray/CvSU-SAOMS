<?php
/**
 * OSAS Organization/Council Applications Management
 * 
 * This page has been integrated into org_registration.php
 * Redirecting to the new unified management page.
 */

// Preserve any query parameters
$queryString = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] . '&tab=applications' : '?tab=applications';
header('Location: org_registration.php' . $queryString);
exit;
