    <!-- Bootstrap JS -->
    <?php 
    // 检测调用此footer的脚本路径来确定资源路径
    $calling_script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    $calling_dir = dirname($calling_script);
    
    if (strpos($calling_dir, '/admin/channels') !== false) {
        $assets_path = '../../assets/';
    } elseif (strpos($calling_dir, '/admin') !== false) {
        $assets_path = '../assets/';
    } else {
        $assets_path = 'assets/';
    }
    ?>
    <script src="<?php echo $assets_path; ?>js/bootstrap.bundle.min.js"></script>
    
    <!-- 自定义JS -->
    <script>
        // 自动隐藏提示消息
        setTimeout(function() {
            var alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                var bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        // 确认删除
        function confirmDelete(message) {
            return confirm(message || '确定要删除吗？此操作不可恢复！');
        }
        
        // 表格搜索功能
        function searchTable(inputId, tableId) {
            var input = document.getElementById(inputId);
            var filter = input.value.toUpperCase();
            var table = document.getElementById(tableId);
            var tr = table.getElementsByTagName("tr");
            
            for (var i = 1; i < tr.length; i++) {
                var td = tr[i].getElementsByTagName("td");
                var found = false;
                for (var j = 0; j < td.length; j++) {
                    if (td[j]) {
                        var txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                tr[i].style.display = found ? "" : "none";
            }
        }
    </script>
</body>
</html>