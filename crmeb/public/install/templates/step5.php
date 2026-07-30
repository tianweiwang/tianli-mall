<!doctype html>
<html>
<head>
<meta charset="UTF-8" />
<title><?php echo $Title; ?> - <?php echo $Powered; ?></title>
<link rel="stylesheet" href="./css/install.css?v=9.0" />
<link rel="stylesheet" href="./css/step5.css"/>

<?php
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
    ? 'https'
    : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = htmlspecialchars($scheme . '://' . $host, ENT_QUOTES, 'UTF-8');
?>
</head>
<body>
<div class="wrap">
    <div class="title">
        安装完成
    </div>

  <section class="section">
      <div class="title">
          <img src="./images/install/success.png" alt="">
          <h1>安装成功</h1>
      </div>
      <div class="progress">
          <div class="trip p8">
              为了您站点的安全，安装完成后即可将网站根目录下的“install”文件夹下的所有文件删除，防止重复安装。
          </div>
      </div>
    <div class="bottom-btn">
        <div class="pre btn">
            <a href="<?php echo $baseUrl; ?>" class="btn mid">进入前台</a>
        </div>
        <div class="admin btn">
            <a href="<?php echo $baseUrl; ?>/admin" class="btn btn_submit J_install_btn mid">进入后台</a>
        </div>
      </div>
    </div>
  </section>
</div>
<?php require './templates/footer.php';?>
</body>
</html>
