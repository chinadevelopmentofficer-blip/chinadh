<?php
/**
 * GitHub OAuth 配置和处理类 - 改进版本
 */

class GitHubOAuth {
    private $client_id;
    private $client_secret;
    private $redirect_uri;
    private $scope = 'user:email';
    
    public function __construct() {
        $this->client_id = getSetting('github_client_id', '');
        $this->client_secret = getSetting('github_client_secret', '');
        $this->redirect_uri = $this->getRedirectUri();
    }
    
    /**
     * 获取重定向URI
     */
    private function getRedirectUri() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        return $protocol . '://' . $host . '/user/github_callback.php';
    }
    
    /**
     * 生成GitHub授权URL
     */
    public function getAuthUrl() {
        if (empty($this->client_id)) {
            throw new Exception('GitHub Client ID 未配置');
        }
        
        $state = $this->generateState();
        $_SESSION['github_oauth_state'] = $state;
        
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'scope' => $this->scope,
            'state' => $state,
            'allow_signup' => 'true'
        ];
        
        return 'https://github.com/login/oauth/authorize?' . http_build_query($params);
    }
    
    /**
     * 生成随机state参数
     */
    private function generateState() {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * 验证state参数
     */
    public function verifyState($state) {
        return isset($_SESSION['github_oauth_state']) && 
               hash_equals($_SESSION['github_oauth_state'], $state);
    }
    
    /**
     * 通过授权码获取访问令牌
     */
    public function getAccessToken($code) {
        if (empty($this->client_id) || empty($this->client_secret)) {
            throw new Exception('GitHub OAuth 配置不完整');
        }
        
        $data = [
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'code' => $code,
            'redirect_uri' => $this->redirect_uri
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://github.com/login/oauth/access_token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'User-Agent: DNS-Management-System'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $curl_error = curl_error($ch);
            $curl_errno = curl_errno($ch);
            curl_close($ch);
            throw new Exception("获取访问令牌时网络错误 (错误代码: {$curl_errno}): {$curl_error}");
        }
        
        curl_close($ch);
        
        if ($http_code !== 200) {
            throw new Exception("GitHub API请求失败，HTTP状态码: {$http_code}，响应内容: " . substr($response, 0, 200));
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['error'])) {
            throw new Exception('GitHub OAuth错误: ' . $result['error_description']);
        }
        
        if (!isset($result['access_token'])) {
            throw new Exception('未能获取访问令牌，响应内容: ' . substr($response, 0, 200));
        }
        
        return $result['access_token'];
    }
    
    /**
     * 获取用户信息
     */
    public function getUserInfo($access_token) {
        // 获取基本用户信息
        $user_info = $this->makeApiRequest('https://api.github.com/user', $access_token);
        
        // 获取用户邮箱（如果公开邮箱为空，则获取私有邮箱）
        if (empty($user_info['email'])) {
            try {
                $emails = $this->makeApiRequest('https://api.github.com/user/emails', $access_token);
                foreach ($emails as $email) {
                    if ($email['primary'] && $email['verified']) {
                        $user_info['email'] = $email['email'];
                        break;
                    }
                }
            } catch (Exception $e) {
                // 如果获取邮箱失败，继续使用空邮箱
                error_log("获取GitHub用户邮箱失败: " . $e->getMessage());
            }
        }
        
        return $user_info;
    }
    
    /**
     * 计算GitHub账户注册天数
     */
    public function getAccountAgeDays($github_user) {
        if (!isset($github_user['created_at'])) {
            return 0;
        }
        
        $created_date = new DateTime($github_user['created_at']);
        $current_date = new DateTime();
        $interval = $current_date->diff($created_date);
        
        return $interval->days;
    }
    
    /**
     * 根据GitHub账户年龄计算积分
     */
    public function calculatePointsByAge($account_age_days) {
        $min_days = getSetting('github_min_account_days', 30);
        $github_bonus_points = getSetting('github_bonus_points', 200);
        $default_points = getSetting('default_user_points', 100);
        
        if ($account_age_days >= $min_days) {
            return $github_bonus_points;
        } else {
            return $default_points;
        }
    }
    
    /**
     * 发起GitHub API请求
     */
    private function makeApiRequest($url, $access_token) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: token ' . $access_token,
            'User-Agent: DNS-Management-System',
            'Accept: application/vnd.github.v3+json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $curl_error = curl_error($ch);
            $curl_errno = curl_errno($ch);
            curl_close($ch);
            throw new Exception("GitHub API请求网络错误 (错误代码: {$curl_errno}): {$curl_error}");
        }
        
        curl_close($ch);
        
        if ($http_code !== 200) {
            throw new Exception("GitHub API请求失败，HTTP状态码: {$http_code}，URL: {$url}");
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON解析错误: ' . json_last_error_msg());
        }
        
        return $result;
    }
    
    /**
     * 检查OAuth是否已配置
     */
    public function isConfigured() {
        return !empty($this->client_id) && !empty($this->client_secret);
    }
}
?>