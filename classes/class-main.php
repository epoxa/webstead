<?php

require_once CLASSES_DIR . "class-robot.php";
require_once LOCAL_DIR . "class-game.php";

class YY_Main extends YY_Robot
{

  function __construct()
  {
    parent::__construct([
      'mode' => false,
      'attributes' => [
        'class' => 'container',
      ],
    ]);

    $gameName = 'cat';

    $game = new YY_Game([
      'name' => $gameName,
      'lang' => 'ru',
      'userYYID' => YY::$ME->_YYID,
    ]);
    $game['_parent'] = $this;
    $game->prepareAll();
    $this['game'] = $game;
  }

  public function _PAINT()
  {

    echo '<div class="menu">';
    echo $this->HUMAN_COMMAND($this['mode'] ? ['class' => 'active'] : null, 'МЕНЮ', 'switchMenu');
    echo '</div>'; // menu

    if ($this['mode']) {

      $this->drawMenu();

    } else {

      $this['game']->_SHOW();

    }

  }

  private function drawMenu()
  {
//    $thisGameName = $this['game']['name'];
//    $info = YY::Config('games')[$thisGameName];
//    echo '<h2>' . htmlspecialchars($info['title']) . '</h2>';
    echo '<div style="margin: 0 auto; width: 400px; text-align: center">';
    echo '<div style="font-size: large; margin-bottom: 25px">Настройки звука</div>';
    echo $this->HUMAN_COMMAND([
      'beforeContent' => '<img src="themes/game/img/' . ($this['game']['musicOn'] ? 'check-on.png' : 'check-off.png') . '">'
    ], 'Музыка', 'switchMusic');
    echo '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
    echo $this->HUMAN_COMMAND([
      'beforeContent' => '<img src="themes/game/img/' . ($this['game']['clickOn'] ? 'check-on.png' : 'check-off.png') . '">'
    ], 'Щелчок', 'switchClick');
    echo '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
    echo $this->HUMAN_COMMAND([
      'beforeContent' => '<img src="themes/game/img/' . ($this['game']['soundOn'] ? 'check-on.png' : 'check-off.png') . '">'
    ], 'Эффекты', 'switchSound');
    echo '</div>';
    echo '<div style="clear: both; height: 30px;"></div>';
    echo $this->HUMAN_COMMAND(
      [
        'before' => '<style="float:left;clear:left"><span style="font-size: large">&laquo; </span>',
        'after' => '</span>'
      ],
      'НАЧАТЬ ЗАНОВО', 'restart');
    echo $this->HUMAN_COMMAND(
      [
        'before' => '<span style="float:right;clear:right">',
        'after' => '<span style="font-size: large"> &raquo;</span></span>'
      ],
      'ПРОДОЛЖИТЬ ИГРУ', 'switchMenu');
//    echo '<h2 style="margin-top: 30px">Другие игры</h2>';
//    echo '<ul>';
//    foreach (YY::Config('games') as $gameName => $gameInfo) {
//      if ($gameName == $thisGameName) continue;
//      if (isset($gameInfo['hidden']) && !DEBUG_ALLOWED_IP) continue;
//      echo '<li>' . $this->HUMAN_COMMAND([], htmlspecialchars($gameInfo['title']), 'selectGame', ['gameName' => $gameName]) . '</li>';
//    }
//    echo '</ul>';
  }

  public function switchMenu()
  {
    if ($this['mode']) {
      $this['mode'] = null;
    } else {
      $this['mode'] = true;
    }
  }

  public function switchMusic()
  {
    $this['game']['musicOn'] = !$this['game']['musicOn'];
    if ($this['game']['musicOn']) {
      $ch = $this['game']['audioPlayers']['music'];
      YY::clientExecute('playSound("' . $ch['source'] . '", "music", ' . $ch['loop'] . ');');
    } else {
      YY::clientExecute('stopChannelNow("music");');
    }
  }

  public function switchClick()
  {
    $this['game']['clickOn'] = !$this['game']['clickOn'];
    if ($this['game']['clickOn']) {
      YY::clientExecute('playSound("/themes/games/default/snd/click", "sound_0", 1);');
    }
  }

  public function switchSound()
  {
    $this['game']['soundOn'] = !$this['game']['soundOn'];
    if ($this['game']['soundOn']) {
      YY::clientExecute('playSound("/themes/games/default/snd/glass", "sound_0", 1);');
    } else {
      YY::clientExecute('stopSound;');
    }
  }

  public function restart()
  {
    $this['game']->restart();
    $this['mode'] = null;
  }

}
