<?php

namespace App\Services;

class EwayBillFileService
{
    private string $storageDir;

    public function __construct()
    {
        $this->storageDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'eway_bills';
    }

    /**
     * @param array{tmp_name:string,name:string,size:int,error:int} $file
     */
    public function store(int $dispatchId, int $orderId, array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('E-way bill file upload failed');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Invalid uploaded file');
        }

        $maxBytes = 10 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxBytes) {
            throw new \RuntimeException('E-way bill file must be 10 MB or smaller');
        }

        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            throw new \RuntimeException('E-way bill must be PDF or image (JPG, PNG, WebP)');
        }

        $orderDir = $this->storageDir . DIRECTORY_SEPARATOR . $orderId;
        if (!is_dir($orderDir) && !@mkdir($orderDir, 0775, true) && !is_dir($orderDir)) {
            throw new \RuntimeException('Could not create e-way bill storage directory');
        }

        $filename = 'dispatch_' . $dispatchId . '_' . time() . '.' . $ext;
        $absolutePath = $orderDir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            throw new \RuntimeException('Failed to save e-way bill file');
        }

        return 'eway_bills/' . $orderId . '/' . $filename;
    }

    public function getAbsolutePath(string $relativePath): string
    {
        $relativePath = str_replace(['\\', '..'], ['/', ''], $relativePath);
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    public function isReadable(string $relativePath): bool
    {
        if ($relativePath === '') {
            return false;
        }
        $path = $this->getAbsolutePath($relativePath);
        return is_file($path) && is_readable($path);
    }
}
