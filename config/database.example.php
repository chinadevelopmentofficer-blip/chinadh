<?php
/**
 * 数据库配置示例文件
 * 复制此文件为 database.php 并修改相应配置
 */

class Database {
    private static $instance = null;
    private $db;

    private function __construct() {
        $db_file = __DIR__ . '/../data/cloudflare_dns.db';
        
        // 确保数据目录存在
        $data_dir = dirname($db_file);
        if (!is_dir($data_dir)) {
            mkdir($data_dir, 0755, true);
        }
        
        // 创建数据库连接并设置优化参数
        $this->db = new SQLite3($db_file);
        $this->db->enableExceptions(true);
        
        // 设置SQLite优化参数以减少锁定问题
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA synchronous = NORMAL');
        $this->db->exec('PRAGMA cache_size = 1000');
        $this->db->exec('PRAGMA temp_store = MEMORY');
        $this->db->exec('PRAGMA busy_timeout = 30000');
        $this->db->exec('PRAGMA foreign_keys = ON');
        
        $this->initTables();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->db;
    }

    private function initTables() {
        // 数据库表初始化代码
        // 实际配置请参考原始 database.php 文件
        // 注意：dns_records 表应包含 remark, ttl, priority 等字段
    }
}
?>