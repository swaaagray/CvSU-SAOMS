<?php
/**
 * System Settings Helper Functions
 * Provides get/set functions for system settings stored in database
 */

/**
 * Ensure the system_settings table exists
 */
function ensureSettingsTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS system_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT NOT NULL,
        description VARCHAR(255),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $conn->query($sql);
    
    // Insert default values if they don't exist
    $defaults = [
        ['permit_recommending_name', 'DENMARK A. GARCIA', 'Activity Permit - Recommending Approval Name'],
        ['permit_recommending_position', 'Head, SDS', 'Activity Permit - Recommending Approval Position'],
        ['permit_approved_name', 'SHARON M. ISIP', 'Activity Permit - Approved By Name'],
        ['permit_approved_position', 'Dean, OSAS', 'Activity Permit - Approved By Position']
    ];
    
    foreach ($defaults as $default) {
        $stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $default[0], $default[1], $default[2]);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Get a setting value by key
 * @param mysqli $conn Database connection
 * @param string $key Setting key
 * @param string $default Default value if not found
 * @return string Setting value
 */
function getSetting($conn, $key, $default = '') {
    ensureSettingsTable($conn);
    
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return $row['setting_value'];
    }
    
    $stmt->close();
    return $default;
}

/**
 * Set a setting value
 * @param mysqli $conn Database connection
 * @param string $key Setting key
 * @param string $value Setting value
 * @param string $description Optional description
 * @return bool Success status
 */
function setSetting($conn, $key, $value, $description = null) {
    ensureSettingsTable($conn);
    
    if ($description !== null) {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, description) 
                                VALUES (?, ?, ?) 
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)");
        $stmt->bind_param("sss", $key, $value, $description);
    } else {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) 
                                VALUES (?, ?) 
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param("ss", $key, $value);
    }
    
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Get multiple settings at once
 * @param mysqli $conn Database connection
 * @param array $keys Array of setting keys
 * @return array Associative array of key => value
 */
function getSettings($conn, $keys) {
    ensureSettingsTable($conn);
    
    $settings = [];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $types = str_repeat('s', count($keys));
    $stmt->bind_param($types, ...$keys);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $stmt->close();
    return $settings;
}

/**
 * Get Activity Permit official settings
 * @param mysqli $conn Database connection
 * @return array Associative array with official names and positions
 */
function getActivityPermitOfficials($conn) {
    $keys = [
        'permit_recommending_name',
        'permit_recommending_position',
        'permit_approved_name',
        'permit_approved_position'
    ];
    
    $settings = getSettings($conn, $keys);
    
    return [
        'recommending_name' => $settings['permit_recommending_name'] ?? 'DENMARK A. GARCIA',
        'recommending_position' => $settings['permit_recommending_position'] ?? 'Head, SDS',
        'approved_name' => $settings['permit_approved_name'] ?? 'SHARON M. ISIP',
        'approved_position' => $settings['permit_approved_position'] ?? 'Dean, OSAS'
    ];
}
?>

