<?php
/**
 * Cloudflare API配置示例文件
 * 复制此文件为 cloudflare.php 并修改相应配置
 */

class CloudflareAPI {
    private $apiKey;
    private $email;
    private $baseUrl = 'https://api.cloudflare.com/client/v4/';

    public function __construct($apiKey, $email) {
        $this->apiKey = $apiKey;
        $this->email = $email;
    }

    /**
     * 发送API请求
     */
    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'X-Auth-Email: ' . $this->email,
            'X-Auth-Key: ' . $this->apiKey,
            'Content-Type: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('Cloudflare API请求失败');
        }

        $result = json_decode($response, true);
        
        if ($httpCode >= 400 || !$result['success']) {
            $error = isset($result['errors'][0]['message']) ? $result['errors'][0]['message'] : 'API请求失败';
            throw new Exception($error);
        }

        return $result['result'];
    }

    /**
     * 获取DNS记录列表
     */
    public function getDNSRecords($zoneId) {
        return $this->makeRequest("zones/$zoneId/dns_records");
    }

    /**
     * 创建DNS记录
     */
    public function createDNSRecord($zoneId, $type, $name, $content, $proxied = false) {
        $data = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'proxied' => $proxied
        ];
        
        return $this->makeRequest("zones/$zoneId/dns_records", 'POST', $data);
    }

    /**
     * 更新DNS记录
     */
    public function updateDNSRecord($zoneId, $recordId, $type, $name, $content, $proxied = false) {
        $data = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'proxied' => $proxied
        ];
        
        return $this->makeRequest("zones/$zoneId/dns_records/$recordId", 'PUT', $data);
    }

    /**
     * 删除DNS记录
     */
    public function deleteDNSRecord($zoneId, $recordId) {
        return $this->makeRequest("zones/$zoneId/dns_records/$recordId", 'DELETE');
    }

    /**
     * 验证API连接
     */
    public function verifyConnection() {
        try {
            $this->makeRequest('user');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
?>