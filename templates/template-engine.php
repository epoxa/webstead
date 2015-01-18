<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="ru" lang="ru">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <title><?= htmlspecialchars(GAME_TITLE) ?></title>
  <meta name="Description" content="<?= htmlspecialchars(GAME_SUBTITLE) ?>">
  <meta name="Robots" content="noindex,nofollow">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
  <style type="text/css">
    body {
      background: #DEE4EE;
      padding: 0;
      margin: 0;
    }
  </style>
  <link rel="icon" href="http://<?= ROOT_URL . 'favicon.ico' ?>" type="image/x-icon">
  <link rel="shortcut icon" href="http://<?= ROOT_URL . 'favicon.ico' ?>" type="image/x-icon">
  <link rel="stylesheet" href="../themes/game/css/main.css">
  <script ok="1" type="text/javascript">
    var rootUrl = '<?= ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']) ? 'https://' : 'http://') . ROOT_URL ?>';
    var viewId = '<?= YY::GenerateNewYYID() ?>';
  </script>
  <script ok="1" type="text/javascript" src="/lib/jquery/jquery-1.11.1.min.js"></script>
  <script ok="1" type="text/javascript" src="/lib/jqueryui/jquery-ui.min.js"></script>
  <script ok="1" type="text/javascript" src="/themes/engine/js/engine.js"></script>
  <script ok="1" type="text/javascript" src="/themes/game/js/game.js"></script>
  <script ok="1" type="text/javascript" src="/themes/game/js/mouse.js"></script>
  <script ok="1" type="text/javascript" src="/themes/game/js/sound.js"></script>
  <script ok="1" type="text/javascript" src="/themes/game/js/sprites.js"></script>
</head>
<body style="margin: 0; overflow-x: hidden">
<audio id="music" autoplay></audio>
<audio id="sound_0" autoplay></audio>
<audio id="sound_1" autoplay></audio>
<audio id="sound_2" autoplay></audio>
<audio id="sound_3" autoplay></audio>
<audio id="sound_4" autoplay></audio>
<audio id="sound_5" autoplay></audio>
<audio id="sound_6" autoplay></audio>
<audio id="sound_7" autoplay></audio>
<div id="blind">
</div>
<img id="dummy_radio" style="visibility:hidden;position:absolute;top:-30px" src="/themes/main/img/wait.gif">
<script ok="1" type="text/javascript">
  window.onBeforeConnect = function () {
    var url = "/themes/main/img/wait.gif";
    var img = document.getElementById('dummy_radio');
    blind.style.backgroundImage = "";
    img.src = "";
    img.src = url;
    blind.style.backgroundImage = url;
  }
</script>
<iframe id="upload_result" name="upload_result" src="" style="width:0;height:0;border:0;" tabindex="-1"></iframe>
</body>
</html>
