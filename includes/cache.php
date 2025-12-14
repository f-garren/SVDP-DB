<?php
/**
 * Simple In-Memory Cache for Settings and Frequently Accessed Data
 */
class Cache {
    private static $cache = [];
    private static $ttl = [];
    
    /**
     * Get cached value
     */
    public static function get($key) {
        if (!isset(self::$cache[$key])) {
            return null;
        }
        
        // Check TTL
        if (isset(self::$ttl[$key]) && self::$ttl[$key] < time()) {
            unset(self::$cache[$key], self::$ttl[$key]);
            return null;
        }
        
        return self::$cache[$key];
    }
    
    /**
     * Set cached value
     */
    public static function set($key, $value, $ttl = 300) {
        self::$cache[$key] = $value;
        self::$ttl[$key] = time() + $ttl;
    }
    
    /**
     * Delete cached value
     */
    public static function delete($key) {
        unset(self::$cache[$key], self::$ttl[$key]);
    }
    
    /**
     * Clear all cache
     */
    public static function clear() {
        self::$cache = [];
        self::$ttl = [];
    }
    
    /**
     * Check if key exists
     */
    public static function has($key) {
        if (!isset(self::$cache[$key])) {
            return false;
        }
        
        if (isset(self::$ttl[$key]) && self::$ttl[$key] < time()) {
            unset(self::$cache[$key], self::$ttl[$key]);
            return false;
        }
        
        return true;
    }
}

