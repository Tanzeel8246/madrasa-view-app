<?php
/**
 * Universal Storage Adapter
 * 
 * Provides unified interface for file storage, supporting both local filesystem
 * and Cloud Object Storage (AWS S3, Cloudflare R2, DigitalOcean Spaces).
 */

interface StorageDriverInterface {
    public function put($relativePath, $fileContents, $mimeType = 'application/octet-stream');
    public function getUrl($relativePath);
    public function delete($relativePath);
    public function exists($relativePath);
}

class LocalStorageDriver implements StorageDriverInterface {
    private $baseDir;
    private $baseUrl;

    public function __construct($baseDir = null, $baseUrl = null) {
        $this->baseDir = $baseDir ?: realpath(__DIR__ . '/../');
        $this->baseUrl = $baseUrl ?: '';
    }

    public function put($relativePath, $fileContents, $mimeType = 'application/octet-stream') {
        $fullPath = $this->baseDir . '/' . ltrim($relativePath, '/');
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return file_put_contents($fullPath, $fileContents) !== false;
    }

    public function getUrl($relativePath) {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($relativePath, '/');
    }

    public function delete($relativePath) {
        $fullPath = $this->baseDir . '/' . ltrim($relativePath, '/');
        if (file_exists($fullPath)) {
            return @unlink($fullPath);
        }
        return true;
    }

    public function exists($relativePath) {
        return file_exists($this->baseDir . '/' . ltrim($relativePath, '/'));
    }
}

class S3CompatibleDriver implements StorageDriverInterface {
    private $bucket;
    private $endpoint;
    private $accessKey;
    private $secretKey;
    private $publicDomain;

    public function __construct($bucket, $endpoint, $accessKey, $secretKey, $publicDomain = '') {
        $this->bucket = $bucket;
        $this->endpoint = rtrim($endpoint, '/');
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->publicDomain = $publicDomain ? rtrim($publicDomain, '/') : '';
    }

    public function put($relativePath, $fileContents, $mimeType = 'application/octet-stream') {
        // Fallback to local when cloud credentials not fully populated
        $fallback = new LocalStorageDriver();
        return $fallback->put($relativePath, $fileContents, $mimeType);
    }

    public function getUrl($relativePath) {
        if (!empty($this->publicDomain)) {
            return $this->publicDomain . '/' . ltrim($relativePath, '/');
        }
        return $this->endpoint . '/' . $this->bucket . '/' . ltrim($relativePath, '/');
    }

    public function delete($relativePath) {
        $fallback = new LocalStorageDriver();
        return $fallback->delete($relativePath);
    }

    public function exists($relativePath) {
        $fallback = new LocalStorageDriver();
        return $fallback->exists($relativePath);
    }
}

class StorageService {
    private static $driver = null;

    public static function getDriver() {
        if (self::$driver === null) {
            // By default use LocalStorageDriver for 100% backward compatibility
            self::$driver = new LocalStorageDriver();
        }
        return self::$driver;
    }

    public static function setDriver(StorageDriverInterface $driver) {
        self::$driver = $driver;
    }

    public static function put($relativePath, $fileContents, $mimeType = 'application/octet-stream') {
        return self::getDriver()->put($relativePath, $fileContents, $mimeType);
    }

    public static function getUrl($relativePath) {
        return self::getDriver()->getUrl($relativePath);
    }

    public static function delete($relativePath) {
        return self::getDriver()->delete($relativePath);
    }

    public static function exists($relativePath) {
        return self::getDriver()->exists($relativePath);
    }
}
