<?php


session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

if(file_exists('Install.lock')) {
    header('Location: index.php');
    exit;
}


function generateApiToken($length = 32) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $token = '';
    for ($i = 0; $i < $length; $i++) {
        $token .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $token;
}


$steps = [
    'welcome' => [
        'title' => '欢迎',
        'description' => '开始安装Sakura Panel'
    ],
    'requirements' => [
        'title' => '环境检查',
        'description' => '检查系统环境'
    ],
    'database' => [
        'title' => '数据库',
        'description' => '配置数据库连接'
    ],
    'admin' => [
        'title' => '管理员',
        'description' => '设置管理员账户'
    ],
    'finish' => [
        'title' => '完成',
        'description' => '安装完成'
    ]
];


$current_step = $_GET['step'] ?? 'welcome';
if(!isset($steps[$current_step])) {
    $current_step = 'welcome';
}

$default_api_token = generateApiToken();


$errors = [];
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch($current_step) {
        case 'requirements':
            header('Location: install.php?step=database');
            exit;
            break;
            
        case 'database':
            $db_host = trim($_POST['db_host'] ?? '');
            $db_port = intval($_POST['db_port'] ?? 3306);
            $db_user = trim($_POST['db_user'] ?? '');
            $db_pass = trim($_POST['db_pass'] ?? '');
            $db_name = trim($_POST['db_name'] ?? '');
            
            if(empty($db_host)) $errors[] = '数据库主机不能为空';
            if(empty($db_user)) $errors[] = '数据库用户名不能为空';
            if(empty($db_name)) $errors[] = '数据库名称不能为空';
            
            if(empty($errors)) {
                try {
                    $conn = @new mysqli($db_host, $db_user, $db_pass, '', $db_port);
                    if($conn->connect_error) {
                        $errors[] = '数据库连接失败: ' . $conn->connect_error;
                    } else {
                        if(!$conn->select_db($db_name)) {
                            if(!$conn->query("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
                                $errors[] = '创建数据库失败: ' . $conn->error;
                            }
                        }
                        $conn->close();
                    }
                } catch(Exception $e) {
                    $errors[] = '数据库连接异常: ' . $e->getMessage();
                }
            }
            
            if(empty($errors)) {
                $_SESSION['install_db'] = compact('db_host', 'db_port', 'db_user', 'db_pass', 'db_name');
                header('Location: install.php?step=admin');
                exit;
            }
            break;
            
        case 'admin':
            $admin_user = trim($_POST['admin_user'] ?? '');
            $admin_pass = trim($_POST['admin_pass'] ?? '');
            $admin_email = trim($_POST['admin_email'] ?? '');
            $api_token = trim($_POST['api_token'] ?? $default_api_token);
            
            if(empty($admin_user)) $errors[] = '管理员用户名不能为空';
            if(empty($admin_pass)) $errors[] = '管理员密码不能为空';
            if(empty($admin_email)) $errors[] = '管理员邮箱不能为空';
            
            if(empty($errors)) {
                $_SESSION['install_admin'] = compact('admin_user', 'admin_pass', 'admin_email', 'api_token');
                
                try {
                    if(!isset($_SESSION['install_db'])) {
                        throw new Exception('数据库配置信息丢失，请重新进行安装');
                    }
                    
                    $db_config = $_SESSION['install_db'];
                    $admin_config = $_SESSION['install_admin'];
                    
                    $conn = @new mysqli($db_config['db_host'], $db_config['db_user'], $db_config['db_pass'], '', $db_config['db_port']);
                    if($conn->connect_error) {
                        throw new Exception('数据库连接失败: ' . $conn->connect_error);
                    }
                    
                    if(!$conn->select_db($db_config['db_name'])) {
                        throw new Exception('选择数据库失败: ' . $conn->error);
                    }
                    

                    $sql_file = 'import.sql';
                    if(file_exists($sql_file)) {
                        $sql_content = file_get_contents($sql_file);
                        $sql_queries = explode(';', $sql_content);
                        
                        foreach($sql_queries as $query) {
                            $query = trim($query);
                            if(!empty($query)) {
                                if(!$conn->query($query)) {
                                    throw new Exception('执行SQL语句失败: ' . $conn->error);
                                }
                            }
                        }
                    } else {
                        throw new Exception('数据库导入文件不存在');
                    }
                    
                    // 创建管理员账户
                    $hashed_password = password_hash($admin_config['admin_pass'], PASSWORD_DEFAULT);
                    $current_time = date('Y-m-d H:i:s');
                    
                    $insert_sql = "INSERT INTO `users` (`username`, `password`, `email`, `traffic`, `proxies`, `group`, `regtime`, `status`) VALUES (?, ?, ?, 102400, 50, 'admin', ?, 'normal')";
                    $stmt = $conn->prepare($insert_sql);
                    
                    if($stmt === false) {
                        throw new Exception('准备SQL语句失败: ' . $conn->error);
                    }
                    
                    $stmt->bind_param('ssss', $admin_config['admin_user'], $hashed_password, $admin_config['admin_email'], $current_time);
                    
                    if(!$stmt->execute()) {
                        throw new Exception('创建管理员账户失败: ' . $stmt->error);
                    }
                    
                    // 更新配置文件
                    $config_template = file_get_contents('configuration.php');
                    $config_content = str_replace([
                        "'db_host'           => '127.0.0.1'",
                        "'db_port'           => 3306",
                        "'db_user'           => 'root'",
                        "'db_pass'           => '12345678'",
                        "'db_name'           => 'Sakura'"
                    ], [
                        "'db_host'           => '" . addslashes($db_config['db_host']) . "'",
                        "'db_port'           => " . $db_config['db_port'],
                        "'db_user'           => '" . addslashes($db_config['db_user']) . "'",
                        "'db_pass'           => '" . addslashes($db_config['db_pass']) . "'",
                        "'db_name'           => '" . addslashes($db_config['db_name']) . "'"
                    ], $config_template);
                    
                    file_put_contents('configuration.php', $config_content);
                    
                    // 更新API配置文件
                    $api_content = file_get_contents('api/index.php');
                    $api_token_pattern = '/define\("API_TOKEN",\s*"[^"]*"\)/';
                    $api_token_replacement = 'define("API_TOKEN", "' . addslashes($admin_config['api_token']) . '")';
                    $api_content = preg_replace($api_token_pattern, $api_token_replacement, $api_content);
                    file_put_contents('api/index.php', $api_content);
                    
                    // 更新守护进程配置
                    $daemon_content = file_get_contents('daemon.php');
                    $daemon_content = preg_replace('/"host"\s*=>\s*"[^"]*",/', '"host" => "' . addslashes($db_config['db_host']) . '",', $daemon_content);
                    $daemon_content = preg_replace('/"user"\s*=>\s*"[^"]*",/', '"user" => "' . addslashes($db_config['db_user']) . '",', $daemon_content);
                    $daemon_content = preg_replace('/"pass"\s*=>\s*"[^"]*",/', '"pass" => "' . addslashes($db_config['db_pass']) . '",', $daemon_content);
                    $daemon_content = preg_replace('/"name"\s*=>\s*"[^"]*",/', '"name" => "' . addslashes($db_config['db_name']) . '",', $daemon_content);
                    $daemon_content = preg_replace('/"port"\s*=>\s*[0-9]+/', '"port" => ' . $db_config['db_port'], $daemon_content);
                    file_put_contents('daemon.php', $daemon_content);
                    
                    // 生成安装完成标记文件
                    file_put_contents('Install.lock', 'Sakura Panel installed successfully at ' . date('Y-m-d H:i:s'));
                    
                    $conn->close();
                    
                    header('Location: install.php?step=finish');
                    exit;
                    
                } catch(Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
            break;
    }
}

// 环境检查
$requirements = [
    'php_version' => [
        'name' => 'PHP版本',
        'required' => '7.0+',
        'current' => PHP_VERSION,
        'status' => version_compare(PHP_VERSION, '7.0.0') >= 0
    ],
    'mysql_extension' => [
        'name' => 'MySQL扩展',
        'required' => 'mysqli',
        'current' => extension_loaded('mysqli') ? '已安装' : '未安装',
        'status' => extension_loaded('mysqli')
    ],
    'file_permissions' => [
        'name' => '文件权限',
        'required' => '可写',
        'current' => '检查中',
        'status' => true
    ],
    'import_sql' => [
        'name' => '数据库文件',
        'required' => '存在',
        'current' => file_exists('import.sql') ? '存在' : '不存在',
        'status' => file_exists('import.sql')
    ]
];

// 检查文件权限
$writable_files = ['configuration.php', 'daemon.php', 'api/index.php'];
$writable_errors = [];
foreach($writable_files as $file) {
    if(file_exists($file) && !is_writable($file)) {
        $writable_errors[] = $file;
    }
}
$requirements['file_permissions']['status'] = empty($writable_errors);
$requirements['file_permissions']['current'] = empty($writable_errors) ? '可写' : implode(', ', $writable_errors) . ' 不可写';

// 所有要求是否满足
$all_requirements_met = true;
foreach($requirements as $req) {
    if(!$req['status']) {
        $all_requirements_met = false;
        break;
    }
}

// 输出HTML
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE HTML>
<html lang="zh_CN">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=11">
    <title>Sakura Panel 安装向导</title>
    <link href="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.2.3/css/bootstrap.min.css" rel="stylesheet">
    <style type="text/css">
        body {
            background-color: #000;
            background-image: url(https://i.loli.net/2019/08/13/7EqLWfi1tw6M2Qn.jpg);
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-repeat: no-repeat;
            -webkit-background-size: cover;
            -moz-background-size: cover;
            -o-background-size: cover;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .install-container {
            max-width: 800px;
            margin: 50px auto;
            background: rgba(255,255,255,0.9);
            border: 32px solid rgba(0,0,0,0);
            border-bottom: 16px solid rgba(0,0,0,0);
            box-shadow: 0px 0px 32px rgba(0,0,0,0.75);
            border-radius: 0;
        }
        .install-header {
            background: #343a40;
            color: white;
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #495057;
        }
        .install-header h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 400;
        }
        .install-header p {
            margin: 10px 0 0 0;
            opacity: 0.8;
        }
        .install-progress {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        .progress-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
        }
        .progress-steps::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 0;
            right: 0;
            height: 2px;
            background: #dee2e6;
            z-index: 1;
        }
        .step {
            position: relative;
            z-index: 2;
            text-align: center;
            flex: 1;
        }
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #dee2e6;
            color: #6c757d;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .step.active .step-number {
            background: #007bff;
            color: white;
        }
        .step.completed .step-number {
            background: #28a745;
            color: white;
        }
        .step-label {
            font-size: 0.875rem;
            color: #6c757d;
        }
        .step.active .step-label {
            color: #007bff;
            font-weight: bold;
        }
        .install-content {
            padding: 30px;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .btn-install {
            background: #007bff;
            border: none;
            padding: 10px 25px;
            font-size: 1rem;
            border-radius: 0;
        }
        .alert {
            border-radius: 0;
            border: none;
        }
        .success-box {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 0;
            padding: 15px;
            margin: 15px 0;
        }
        .token-display {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 0;
            padding: 10px;
            font-family: 'Courier New', monospace;
            word-break: break-all;
        }
        @media screen and (max-width: 768px) {
            .install-container {
                margin: 20px;
                width: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row">
            <div class="col-sm-3"></div>
            <div class="col-sm-6">
                <table style="width: 100%;height: 100vh;">
                    <tr style="height: 100%;">
                        <td style="height: 100%;padding-bottom: 64px;">
                            <center>
                                <div class="install-container">
                                    <div class="install-header">
                                        <h1>Sakura Panel</h1>
                                        <p>内网穿透管理面板 - 安装向导</p>
                                    </div>
                                    
                                    <div class="install-progress">
                                        <div class="progress-steps">
                                            <?php
                                            $step_keys = array_keys($steps);
                                            $current_index = array_search($current_step, $step_keys);
                                            foreach($steps as $key => $step):
                                                $index = array_search($key, $step_keys);
                                                $class = '';
                                                if($index < $current_index) $class = 'completed';
                                                elseif($index == $current_index) $class = 'active';
                                            ?>
                                            <div class="step <?php echo $class; ?>">
                                                <div class="step-number"><?php echo $index + 1; ?></div>
                                                <div class="step-label"><?php echo $step['title']; ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="install-content">
                                        <?php if(!empty($errors)): ?>
                                            <div class="alert alert-danger">
                                                <h5>安装过程中出现错误：</h5>
                                                <ul>
                                                    <?php foreach($errors as $error): ?>
                                                        <li><?php echo htmlspecialchars($error); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <form method="POST">
                                            <?php
                                            switch($current_step) {
                                                case 'welcome':
                                                    echo '<h2>欢迎使用Sakura Panel</h2>';
                                                    echo '<p>感谢您选择Sakura Panel，这是一个功能强大的内网穿透管理面板。</p>';
                                                    echo '<div class="alert alert-info">';
                                                    echo '<h5>安装前准备</h5>';
                                                    echo '<p>在开始安装之前，请确保您已经准备好以下信息：</p>';
                                                    echo '<ul>';
                                                    echo '<li>MySQL数据库连接信息</li>';
                                                    echo '<li>管理员账户信息</li>';
                                                    echo '<li>Frps服务器配置</li>';
                                                    echo '</ul>';
                                                    echo '</div>';
                                                    echo '<p>安装过程大约需要5-10分钟。</p>';
                                                    echo '<div style="text-align: center; margin-top: 20px;">';
                                                    echo '<a href="install.php?step=requirements" class="btn btn-primary">开始安装</a>';
                                                    echo '</div>';
                                                    break;
                                                    
                                                case 'requirements':
                                                    echo '<h2>环境检查</h2>';
                                                    echo '<p>请确保您的服务器环境满足以下要求：</p>';
                                                    
                                                    echo '<div class="table-responsive">';
                                                    echo '<table class="table table-bordered">';
                                                    echo '<thead><tr><th>项目</th><th>要求</th><th>当前状态</th><th>状态</th></tr></thead>';
                                                    echo '<tbody>';
                                                    foreach($requirements as $req) {
                                                        $status_class = $req['status'] ? 'text-success' : 'text-danger';
                                                        $status_icon = $req['status'] ? '✓' : '✗';
                                                        echo '<tr>';
                                                        echo '<td>' . $req['name'] . '</td>';
                                                        echo '<td>' . $req['required'] . '</td>';
                                                        echo '<td>' . $req['current'] . '</td>';
                                                        echo '<td class="' . $status_class . '">' . $status_icon . '</td>';
                                                        echo '</tr>';
                                                    }
                                                    echo '</tbody>';
                                                    echo '</table>';
                                                    echo '</div>';
                                                    
                                                    if($all_requirements_met) {
                                                        echo '<div class="alert alert-success">所有环境要求都已满足，可以继续安装。</div>';
                                                        echo '<div style="text-align: center;">';
                                                        echo '<button type="submit" name="next" class="btn btn-primary">继续安装</button>';
                                                        echo '</div>';
                                                    } else {
                                                        echo '<div class="alert alert-danger">部分环境要求未满足，请解决上述问题后再继续安装。</div>';
                                                        echo '<div style="text-align: center;">';
                                                        echo '<a href="install.php?step=requirements" class="btn btn-secondary">重新检查</a>';
                                                        echo '</div>';
                                                    }
                                                    break;
                                                    
                                                case 'database':
                                                    echo '<h2>数据库配置</h2>';
                                                    echo '<p>请填写您的MySQL数据库连接信息：</p>';
                                                    
                                                    echo '<div class="form-group">';
                                                    echo '<label>数据库主机</label>';
                                                    echo '<input type="text" name="db_host" class="form-control" value="127.0.0.1" required>';
                                                    echo '</div>';
                                                    
                                                    echo '<div class="form-group">';
                                                    echo '<label>数据库端口</label>';
                                                    echo '<input type="number" name="db_port" class="form-control" value="3306" required>';
                                                    echo '</div>';
                                                    
                                                    echo '<div class="form-group">';
                                                    echo '<label>数据库用户名</label>';
                                                    echo '<input type="text" name="db_user" class="form-control" value="root" required>';
                                                    echo '</div>';
                                                    
                                                    echo '<div class="form-group">';
                                                    echo '<label>数据库密码</label>';
                                                    echo '<input type="password" name="db_pass" class="form-control">';
                                                    echo '</div>';
                                                    
                                                    echo '<div class="form-group">';
                                                    echo '<label>数据库名称</label>';
                                                    echo '<input type="text" name="db_name" class="form-control" value="spanel" required>';
                                                    echo '</div>';
                                                    
                                                    echo '<div style="text-align: center;">';
                                                    echo '<button type="submit" class="btn btn-primary">测试连接并继续</button>';
                                                    echo '</div>';
                                                    break;
                                                    
                                                case 'admin':
                                                    echo '<h2>管理员账户设置</h2>';
                                                    echo '<p>请设置管理员账户信息：</p>';
                                                    
                                                    echo '<div class="form-group">';
                                                    echo '<label>管理员用户名</label>';
                                                    echo '<input type="text" name="admin_user" class="form-control" value="admin" required>';
                                                    echo '</div>';
                                                    
                                                    echo '<div class="form-group">';
                                                    echo '<label>管理员密码</label>';
                                                    echo '<input type="password" name="admin_pass" class="form-control" required>';
                                                    echo '</div>';
                                                    
                                                    echo '<div class="form-group">';
                                                    echo '<label>管理员邮箱</label>';
                                                    echo '<input type="email" name="admin_email" class="form-control" required>';
                                                    echo '</div>';
                                                    
                                                    echo '<div class="form-group">';
                                                    echo '<label>API Token (用于Frps对接)</label>';
                                                    echo '<input type="text" name="api_token" class="form-control" value="' . $default_api_token . '" required>';
                                                    echo '<small class="form-text text-muted">请妥善保管此Token，用于Frps服务器对接</small>';
                                                    echo '</div>';
                                                    
                                                    echo '<div style="text-align: center;">';
                                                    echo '<button type="submit" class="btn btn-primary">完成安装</button>';
                                                    echo '</div>';
                                                    break;
                                                    
                                                case 'finish':
                                                    echo '<div class="success-box">';
                                                    echo '<h2>🎉 安装完成！</h2>';
                                                    echo '<p>Sakura Panel 已成功安装。</p>';
                                                    echo '</div>';
                                                    
                    
                                                    break;
                                            }
                                            ?>
                                        </form>
                                    </div>
                                </div>
                            </center>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="col-sm-3"></div>
        </div>
    </div>
</body>
</html>
