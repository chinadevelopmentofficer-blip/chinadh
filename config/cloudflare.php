<?php
/**
 * Cloudflare API 操作类
 */

class CloudflareAPI {
    private $api_key;
    private $email;
    private $base_url = 'https://api.cloudflare.com/client/v4/';
    
    public function __construct($api_key, $email) {
        $this->api_key = $api_key;
        $this->email = $email;
    }
    
    /**
     * 发送API请求
     */
    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->base_url . $endpoint;
        
        $headers = [
            'X-Auth-Email: ' . $this->email,
            'X-Auth-Key: ' . $this->api_key,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
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
        
        if ($http_code >= 400 || !$decoded['success']) {
            $error_msg = isset($decoded['errors'][0]['message']) ? $decoded['errors'][0]['message'] : 'Unknown API error';
            $error_code = isset($decoded['errors'][0]['code']) ? $decoded['errors'][0]['code'] : 0;
            
            // 检查是否是SSL for SaaS相关错误
            if (strpos($error_msg, 'SSL for SaaS') !== false || strpos($error_msg, 'fallback origin') !== false) {
                throw new Exception('此DNS记录被配置为SSL for SaaS的回退源服务器，无法直接编辑。如需修改，请确保新记录名称已启用代理且属于当前域名。详情请参考Cloudflare文档。');
            }
            
            throw new Exception('Cloudflare API Error: ' . $error_msg);
        }
        
        return $decoded;
    }
    
    /**
     * 获取所有域名
     */
    public function getZones() {
        try {
            $response = $this->makeRequest('zones');
            return $response['result'];
        } catch (Exception $e) {
            throw new Exception('获取域名列表失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取指定域名的DNS记录
     */
    public function getDNSRecords($zone_id) {
        try {
            $response = $this->makeRequest("zones/{$zone_id}/dns_records");
            return $response['result'];
        } catch (Exception $e) {
            throw new Exception('获取DNS记录失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 添加DNS记录
     */
    public function addDNSRecord($zone_id, $type, $name, $content, $proxied = false) {
        $data = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'proxied' => $proxied
        ];
        
        try {
            $response = $this->makeRequest("zones/{$zone_id}/dns_records", 'POST', $data);
            return $response['result'];
        } catch (Exception $e) {
            throw new Exception('添加DNS记录失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取单个DNS记录详情
     */
    public function getDNSRecord($zone_id, $record_id) {
        try {
            $response = $this->makeRequest("zones/{$zone_id}/dns_records/{$record_id}");
            return $response['result'];
        } catch (Exception $e) {
            throw new Exception('获取DNS记录详情失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 检查DNS记录是否为SSL for SaaS回退源
     */
    public function isSSLForSaaSFallbackRecord($zone_id, $record_id) {
        try {
            $record = $this->getDNSRecord($zone_id, $record_id);
            // 检查记录的meta信息或tags中是否包含SSL for SaaS相关标识
            return isset($record['meta']['ssl_for_saas_fallback']) && $record['meta']['ssl_for_saas_fallback'];
        } catch (Exception $e) {
            // 如果无法获取记录信息，返回false
            return false;
        }
    }
    
    /**
     * 更新DNS记录
     */
    public function updateDNSRecord($zone_id, $record_id, $type, $name = null, $content = null, $proxied = false) {
        // 兼容数组参数调用方式
        if (is_array($type)) {
            $params = $type;
            $type = $params['type'];
            $name = $params['name'];
            $content = $params['content'];
            $proxied = $params['proxied'] ?? false;
        }
        
        $data = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'proxied' => $proxied
        ];
        
        try {
            $response = $this->makeRequest("zones/{$zone_id}/dns_records/{$record_id}", 'PUT', $data);
            return $response['result'];
        } catch (Exception $e) {
            // 检查是否是SSL for SaaS相关错误
            if (strpos($e->getMessage(), 'SSL for SaaS') !== false || strpos($e->getMessage(), 'fallback origin') !== false) {
                throw new Exception('此DNS记录被配置为SSL for SaaS的回退源服务器，无法直接编辑。建议解决方案：1) 确保新记录名称启用代理状态；2) 确认记录属于当前域名；3) 如仍有问题，请联系管理员或查看Cloudflare SSL for SaaS文档。');
            }
            throw new Exception('更新DNS记录失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 删除DNS记录
     */
    public function deleteDNSRecord($zone_id, $record_id) {
        try {
            $response = $this->makeRequest("zones/{$zone_id}/dns_records/{$record_id}", 'DELETE');
            return $response['result'];
        } catch (Exception $e) {
            throw new Exception('删除DNS记录失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 验证API密钥
     */
    public function verifyCredentials() {
        try {
            // 首先尝试验证 API Token
            $response = $this->makeRequest('user/tokens/verify');
            return $response['success'];
        } catch (Exception $e) {
            // 如果 API Token 验证失败，尝试使用 Global API Key 验证
            try {
                $response = $this->makeRequest('user');
                return $response['success'];
            } catch (Exception $e2) {
                return false;
            }
        }
    }
    
    /**
     * 获取详细的验证信息（用于调试）
     */
    public function getVerificationDetails() {
        $details = [
            'api_token_valid' => false,
            'global_key_valid' => false,
            'user_info' => null,
            'error_message' => ''
        ];
        
        // 测试 API Token
        try {
            $response = $this->makeRequest('user/tokens/verify');
            if ($response['success']) {
                $details['api_token_valid'] = true;
                return $details;
            }
        } catch (Exception $e) {
            $details['error_message'] .= 'API Token验证失败: ' . $e->getMessage() . '; ';
        }
        
        // 测试 Global API Key
        try {
            $response = $this->makeRequest('user');
            if ($response['success']) {
                $details['global_key_valid'] = true;
                $details['user_info'] = $response['result'];
                return $details;
            }
        } catch (Exception $e) {
            $details['error_message'] .= 'Global API Key验证失败: ' . $e->getMessage();
        }
        
        return $details;
    }
}