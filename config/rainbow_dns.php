<?php

/**
 * 彩虹聚合DNS API类
 * 基于彩虹聚合DNS接口文档实现
 */
class RainbowDNSAPI {
    private $uid;
    private $api_key;
    private $base_url;
    
    public function __construct($uid, $api_key, $base_url = '') {
        $this->uid = $uid;
        $this->api_key = $api_key;
        $this->base_url = rtrim($base_url, '/');
    }
    
    /**
     * 生成签名
     */
    private function generateSign($timestamp) {
        return md5($this->uid . $timestamp . $this->api_key);
    }
    
    /**
     * 发送API请求
     */
    private function makeRequest($endpoint, $data = []) {
        $url = $this->base_url . $endpoint;
        
        $timestamp = time();
        $sign = $this->generateSign($timestamp);
        
        // 添加公共参数
        $data['uid'] = $this->uid;
        $data['timestamp'] = $timestamp;
        $data['sign'] = $sign;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        // SSL配置 - 如果是HTTPS请求
        if (strpos($url, 'https://') === 0) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  // 开发环境可以关闭，生产环境建议开启
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);  // 在关闭前获取错误信息
        curl_close($ch);
        
        if ($response === false || $curl_errno) {
            throw new Exception('网络请求失败 (错误代码: ' . $curl_errno . '): ' . $curl_error);
        }
        
        $decoded = json_decode($response, true);
        
        if ($http_code >= 400) {
            $error_msg = 'HTTP错误 ' . $http_code;
            if ($decoded && isset($decoded['msg'])) {
                $error_msg .= ': ' . $decoded['msg'];
            }
            throw new Exception($error_msg);
        }
        
        // 检查JSON解析是否成功
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('API响应解析失败: ' . json_last_error_msg() . '，原始响应: ' . substr($response, 0, 200));
        }
        
        // 检查API返回的错误
        if (isset($decoded['code']) && $decoded['code'] != 0) {
            throw new Exception('彩虹DNS API错误: ' . ($decoded['msg'] ?? '未知错误'));
        }
        
        return $decoded;
    }
    
    /**
     * 获取域名列表
     */
    public function getDomains($offset = 0, $limit = 100, $kw = '') {
        try {
            $data = [
                'offset' => $offset,
                'limit' => $limit
            ];
            
            if (!empty($kw)) {
                $data['kw'] = $kw;
            }
            
            $response = $this->makeRequest('/api/domain', $data);
            return $response;
        } catch (Exception $e) {
            throw new Exception('获取域名列表失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取DNS记录列表
     */
    public function getDNSRecords($domain_id, $offset = 0, $limit = 100, $filters = []) {
        try {
            $data = [
                'offset' => $offset,
                'limit' => $limit
            ];
            
            // 添加过滤条件
            $allowed_filters = ['keyword', 'subdomain', 'value', 'type', 'line', 'status'];
            foreach ($allowed_filters as $filter) {
                if (isset($filters[$filter]) && !empty($filters[$filter])) {
                    $data[$filter] = $filters[$filter];
                }
            }
            
            $response = $this->makeRequest("/api/record/data/{$domain_id}", $data);
            return $response;
        } catch (Exception $e) {
            throw new Exception('获取DNS记录失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 添加DNS记录
     */
    public function addDNSRecord($domain_id, $name, $type, $value, $line = 'default', $ttl = 600, $options = []) {
        try {
            $data = [
                'name' => $name,
                'type' => $type,
                'value' => $value,
                'line' => $line,
                'ttl' => $ttl
            ];
            
            // 添加可选参数
            if (isset($options['weight'])) {
                $data['weight'] = $options['weight'];
            }
            if (isset($options['mx'])) {
                $data['mx'] = $options['mx'];
            }
            if (isset($options['remark'])) {
                $data['remark'] = $options['remark'];
            }
            
            $response = $this->makeRequest("/api/record/add/{$domain_id}", $data);
            return $response;
        } catch (Exception $e) {
            throw new Exception('添加DNS记录失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 更新DNS记录
     */
    public function updateDNSRecord($domain_id, $record_id, $name, $type, $value, $line = 'default', $ttl = 600, $options = []) {
        try {
            $data = [
                'recordid' => $record_id,
                'name' => $name,
                'type' => $type,
                'value' => $value,
                'line' => $line,
                'ttl' => $ttl
            ];
            
            // 添加可选参数
            if (isset($options['weight'])) {
                $data['weight'] = $options['weight'];
            }
            if (isset($options['mx'])) {
                $data['mx'] = $options['mx'];
            }
            if (isset($options['remark'])) {
                $data['remark'] = $options['remark'];
            }
            
            $response = $this->makeRequest("/api/record/update/{$domain_id}", $data);
            return $response;
        } catch (Exception $e) {
            throw new Exception('更新DNS记录失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 删除DNS记录
     */
    public function deleteDNSRecord($domain_id, $record_id) {
        try {
            $data = [
                'recordid' => $record_id
            ];
            
            $response = $this->makeRequest("/api/record/delete/{$domain_id}", $data);
            return $response;
        } catch (Exception $e) {
            throw new Exception('删除DNS记录失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 验证API凭据
     */
    public function verifyCredentials() {
        try {
            // 通过获取域名列表来验证凭据
            $response = $this->getDomains(0, 1);
            return isset($response['total']) || isset($response['rows']);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 获取详细的验证信息（用于调试）
     */
    public function getVerificationDetails() {
        $details = [
            'api_valid' => false,
            'domain_count' => 0,
            'error_message' => ''
        ];
        
        try {
            $response = $this->getDomains(0, 1);
            if (isset($response['total']) || isset($response['rows'])) {
                $details['api_valid'] = true;
                $details['domain_count'] = $response['total'] ?? count($response['rows'] ?? []);
            }
        } catch (Exception $e) {
            $details['error_message'] = $e->getMessage();
        }
        
        return $details;
    }
    
    /**
     * 将彩虹DNS记录格式转换为系统标准格式
     */
    public function formatRecord($record) {
        return [
            'id' => $record['RecordId'],
            'name' => $record['Name'],
            'type' => $record['Type'],
            'content' => $record['Value'],
            'ttl' => $record['TTL'],
            'proxied' => false, // 彩虹DNS不支持代理
            'status' => $record['Status'] == '1',
            'line' => $record['Line'] ?? 'default',
            'line_name' => $record['LineName'] ?? '默认',
            'weight' => $record['Weight'] ?? null,
            'mx' => $record['MX'] ?? null,
            'remark' => $record['Remark'] ?? '',
            'updated_at' => $record['UpdateTime'] ?? ''
        ];
    }
    
    /**
     * 将系统格式转换为彩虹DNS API格式
     */
    public function convertToRainbowFormat($type, $name, $content, $options = []) {
        $data = [
            'name' => $name,
            'type' => strtoupper($type),
            'value' => $content,
            'line' => $options['line'] ?? 'default',
            'ttl' => $options['ttl'] ?? 600
        ];
        
        if (isset($options['mx']) && $type == 'MX') {
            $data['mx'] = $options['mx'];
        }
        
        if (isset($options['weight'])) {
            $data['weight'] = $options['weight'];
        }
        
        if (isset($options['remark'])) {
            $data['remark'] = $options['remark'];
        }
        
        return $data;
    }
}