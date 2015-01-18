<?php
$cookiesErrorText = "<h1>Ошибка</h1><p>Для работы должны быть включены cookie!</p>";
$javascriptErrorText = "<h1>Ошибка</h1><p>Для работы должен быть включен javascript!</p>";
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="ru" lang="ru">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <title><?= htmlspecialchars(GAME_TITLE) ?></title>
  <meta name="Description" content="<?= htmlspecialchars(GAME_SUBTITLE) ?>">
  <meta name="Robots" content="noindex,nofollow">
  <link rel="icon" href="http://<?= ROOT_URL . 'favicon.ico' ?>" type="image/x-icon">
  <link rel="shortcut icon" href="http://<?= ROOT_URL . 'favicon.ico' ?>" type="image/x-icon">
</head>
<body style="margin: 0">
<script type="text/javascript">
  document.cookie = "<?= COOKIE_NAME ?>=<?= YY_Utils::GenerateTempKey() ?>; domain=.<?= DOMAIN_NAME ?>; expires=Monday,31-Jan-2033 00:00:00 GMT";
  if (document.cookie.indexOf('YY=') == -1) {
    document.write("<?= $cookiesErrorText ?>");
  } else {
    window.location = "<?= $newLocation ?>";
  }
</script>
<noscript>
  <?= $javascriptErrorText ?>
</noscript>
</html>
