<?php

/**
 * @class Lua
 * @function Lua::eval($command string)
 */

require_once CLASSES_DIR . "class-robot.php";
require_once LOCAL_DIR . "class-sprite.php";

define('GAMEDATA_DIR', realpath(__DIR__ . '/../runtime/games') . '/');
define('GAME_TITLE', 'INSTEAD');
define('GAME_SUBTITLE', 'Simple Text Adventure, The Interpreter');

class YY_Game extends YY_Robot
{

  /**
   * @var $stead Lua
   */
  private $stead;
  private $changedPlayers = [];

  public function __construct($init)
  {
    parent::__construct($init);
    $this->resetState();
    $this['titleContainer'] = new YY_Container(['method' => 'drawTitle']);
    $this['titleContainer']['parent'] = $this;
    $this['pictureContainer'] = new YY_Container(['method' => 'drawPicture']);
    $this['pictureContainer']['parent'] = $this;
    $this['waysContainer'] = new YY_Container(['method' => 'drawWays']);
    $this['waysContainer']['parent'] = $this;
    $this['sceneContainer'] = new YY_Container([
      'method' => 'drawScene',
      'attributes' => [
        'class' => 'scene',
      ],
    ]);
    $this['sceneContainer']['parent'] = $this;
    $this['inventoryContainer'] = new YY_Container(['method' => 'drawInventory']);
    $this['inventoryContainer']['parent'] = $this;
    $this['debugContainer'] = new YY_Container(['method' => 'drawDebug']);
    $this['debugContainer']['parent'] = $this;
    if (!isset($this['musicOn'])) $this['musicOn'] = true; // !isset(YY::$CURRENT_VIEW['knownLocation']); // Если при отладке уши устают
    if (!isset($this['clickOn'])) $this['clickOn'] = true;
    if (!isset($this['soundOn'])) $this['soundOn'] = true;
    $this['audioPlayers'] = [
      'music' => null,
      'sound_0' => null,
      'sound_1' => null,
      'sound_2' => null,
      'sound_3' => null,
      'sound_4' => null,
      'sound_5' => null,
      'sound_6' => null,
      'sound_7' => null,
    ]; // Содержат ссылку на проигрываемые в данный момент аудиофайлы (чтобы можно было обновить сеанс, а музыка и фон не терялись)
  }

  /**
   * Вызывается, когда игра пройдена. При окончании запроса автосохраненки будут стерты.
   */

  public function invalidate()
  {
    $this['invalid'] = true;
  }

  /**
   * Очищает существующий объект игры перед сменой игры
   */

  public function reset()
  {
    $this->setMusic(null);
    $this->playSound(null, -1, null);
    unset($this['paused']);
  }

  /**
   * Приостанавливает или возобновляет звуки и таймер. Можно использовать, например, для временного выхода в меню
   */

  public function pauseSoundAndTimer()
  {
    $this['paused'] = true;
    YY::clientExecute('stopSound()');
    YY::clientExecute('stopChannelNow("music")');
    YY::clientExecute('clearGameTimer()');
  }

  public function continueSoundAndTimer()
  {
    unset($this['paused']);
    if ($this['musicOn']) {
      if ($this['audioPlayers']['music'] && $this['audioPlayers']['music']['loop'] === 0) {
        YY::clientExecute('playSound("' . $this['audioPlayers']['music']['source'] . '", "music", 0);');
      }
    }
    if ($this['soundOn']) {
      for ($ch = 0; $ch <= 7; $ch++) {
        $channelName = 'sound_' . $ch;
        if ($this['audioPlayers'][$channelName] && $this['audioPlayers'][$channelName]['loop'] === 0) {
          YY::clientExecute("playSound('" . $this['audioPlayers'][$channelName]['source'] . "', '$channelName', 0);");
        }
      }
    }

  }

  private function exec($script)
  {
    //    YY::Log('stead', 'EXEC: ' . $script);
    try {
      return @$this->stead->eval($script);
    } catch (Exception $e) {
      if (strpos($e->getMessage(), 'corrupted Lua object') === false) {
        throw new Exception($script . ": " . $e->getMessage());
      }
    }
    return false; // Чисто, чтобы убрать предупреждения PHPStorm
  }

  private function doGameCmd($cmd)
  {
    $result = $this->exec('return iface:cmd("' . $cmd . '")');

    if (isset($this['fixBadInventory']) && $this['fixBadInventory']) { // Не соответствует оригинальному INSTEAD. Применяется в игре "Зеркало"
      $this->exec('return instead.get_inv(false)');
      $autosaveFile = $this->getAutosaveFileName();
      $this->exec('iface:cmd("save ' . $autosaveFile . '")');
      $this->loadState(false);
    }

    if (isset($this['fixRetag']) && $this['fixRetag']) { // Не соответствует оригинальному INSTEAD. Применяется в игре "Особняк"
      $this->exec('stead.me():tag();');
    }

    YY::Log('stead', '=' . serialize($result));
    if ($result[0] !== null) {
      $this['scene'] = $result[0];
      $this->makeSnapshot();
    } else if  ($result[1]) {
      $this['inventory'] = $this->exec('return instead.get_inv(false)');
    } else {
      //      $this['inventory'] = $this->exec('return instead.get_inv(false)'); // TODO: По идее здесь ничего не надо делать
    }
    if (isset($this['selectedObject'])) {
      YY::clientExecute('cancelUseMode();');
    }
    unset($this['selectedObject']);
    unset($this['selectedAction']);
  }

  private function makeSnapshot()
  {
    $sound = $this->exec('return instead.get_sound()');
//    YY::Log('debug', 'SOUND: ' . print_r($sound, true));
    if ($sound) { // Видимо всегда есть
      list($sound, $channel, $loop) = $sound;
      $soundList = explode(';', $sound);
      foreach ($soundList as $sound) {
        if (preg_match('/^([^@]*)(?:@(?:(\d+)(?:,(\d+))?)?)?$/', $sound, $a)) {
          $file = trim($a[1]);
          $c = isset($a[2]) ? $a[2] : $channel;
          $l = isset($a[3]) ? $a[3] : $loop;
          if ($file) {
            $file = 'themes/games/' . $this['name'] . '/' . $file;
          } else {
            $file = null;
          }
          $this->playSound($file, $c, $l);
        } else {
          YY::Log('error', 'UNKNOWN SOUND FORMAT: ' . $sound);
        }
      }
    }
    $music = $this->exec('return instead.get_music()');
//    YY::Log('debug', 'MUSIC: ' . print_r($music, true));
    if ($music) {
      list($music, $loop) = $music;
      if ($music && $loop >= 0) {
        $this->setMusic('themes/games/' . $this['name'] . '/' . $music, $loop);
      } else {
//        YY::Log('debug', 'STOP MUSIC! LOOP = ' . $loop);
        $this->setMusic(null);
      }
    } else {
//      YY::Log('debug', 'NO MUSIC AT ALL!!!');
      $this->setMusic(null);
    }
    $this['title'] = $this->exec('return instead.get_title()');
    $this['picture'] = $this->exec('return instead.get_picture()');
    $this['inventory'] = $this->exec('return instead.get_inv(false)');
    $this['ways'] = $this->exec('return instead.get_ways()');
    YY::Log("GET WAYS = " . $this['ways']);
  }

  private function loadStead()
  {
    $this->stead = new Lua();

    //    $this->exec("table_get_maxn = function(tbl) return #tbl end");

    $callbacks = [

      "vv_log" => function ($message) {
        YY::Log('debug', 'LOG: ' . $message);
      },
      "vv_menu" => function () {
        if (isset($this['_parent'])) {
          $this['_parent']->EXTERNAL_GO('cmdGoMenu');
        }
      },
      "vv_finished" => function () {
        if (isset($this['_parent'])) {
          $this['_parent']->EXTERNAL_GO('eventGameFinished');
        }
      },
      "vv_setTempMode" => function ($on) {
        if ($on) {
          $this['tempMode'] = true;
        } else {
          unset($this['tempMode']);
        }
      },
      "vv_ee_init" => function ($openEggsCount, $eggsNames) {
        if (is_string($eggsNames)) $eggsNames = explode(',', $eggsNames);
        $totalEggsCount = count($eggsNames);
        $this['easterEggs'] = [];
        $allEggs = $this['easterEggs'];
        foreach ($eggsNames as $eggName) {
          $eggName = trim($eggName);
          if (isset($allEggs[$eggName])) {
            $totalEggsCount--;
          } else {
            $allEggs[$eggName] = false;
          }
        }
        $eggsNames = [];
        foreach ($allEggs as $eggName => $dummy) {
          $eggsNames[] = $eggName;
        }
        if ($openEggsCount < 0) $openEggsCount = 0;
        if ($openEggsCount > $totalEggsCount) $openEggsCount = $totalEggsCount;
        for ($i = 0; $i < $openEggsCount; $i++) {
          do {
            $j = rand(0, $totalEggsCount - 1);
            $newName = $eggsNames[$j];
          } while ($allEggs[$newName]);
          $allEggs[$newName] = true;
        }
      },
      "vv_ee_check" => function ($eggName) {
        if (DEBUG_ALLOWED_IP) return true; // Надо же потестировать
        return false; // Пока что закрыто для публики
        $flagFile = GAMEDATA_DIR . $this['name'] . '/' . $this['userYYID'] . "/ee_" . $eggName;
        if (file_exists($flagFile)) return false;
        $result = isset($this['easterEggs'], $this['easterEggs'][$eggName]) && $this['easterEggs'][$eggName];
        if ($result) file_put_contents($flagFile, '');
        return $result;
      },
      "table_get_maxn" => function ($table) {
        //          YY::Log('debug', 'CALL: table_get_maxn');
        $max = 0;
        if (is_array($table)) {
          foreach ($table as $idx => $dummy) {
            if (is_numeric($idx) && $idx > $max) {
              $max = $idx;
            }
          }
        }
        //          YY::Log('debug', 'CALL: table_get_maxn(' . implode(',', array_keys($table)) . ') = ' . $max);
        return $max;
      },
      "doencfile" => function () {
        echo "luaB_doencfile<br>\n";
      },
      "print" => function () {
        ob_start();
        try {
          $na = func_num_args();
          for ($i = 0; $i < $na; $i++) {
            $a = func_get_arg($i);
            echo $a;
          }
        } catch (Exception $e) {
          echo $e->getMessage();
        }
        $res = ob_get_clean();
        YY::Log('stead', $res);
      },
      "instead_readdir" => function () {
        echo "dir_iter_factory<br>\n";
      },
      "instead_sound" => function ($chan = null) {
        //          YY::Log('debug', 'CALL: luaB_is_sound');
        return false; // Что это значит, хз
      },
      "instead_savepath" => function () {
        return GAMEDATA_DIR . $this['name'];
      },
      "instead_gamepath" => function () {
        YY::Log('debug', 'instead_gamepath: ' . LIB_DIR . 'games/' . $this['name']);
        return LIB_DIR . 'games/' . $this['name'];
      },
      "instead_steadpath" => function () {
        //          echo "luaB_get_steadpath<br>\n";
        return LIB_DIR . 'stead';
      },
      "instead_themespath" => function () {
        echo "luaB_get_themespath<br>\n";
      },
      "instead_gamespath" => function () {
        echo "luaB_get_gamespath<br>\n";
      },
      "instead_realpath" => function ($path = null) {
        //          echo "luaB_get_realpath($path)<br>\n";
        return $path;
      },
      "instead_timer" => function ($delay) {
        YY::Log('debug', 'instead_timer: ' . $delay);
        $delay = intval($delay);
        if ($delay) {
          $this['timer_threshold'] = microtime(true) + $delay / 1000;
          $this['timer_delay'] = $delay;
        } else if (isset($this['timer_delay'])) {
          unset($this['timer_threshold']);
          unset($this['timer_delay']);
          $this->clientSetTimer(null); // Отключаем таймер сразу
        }
      },
      "instead_theme_var" => function ($name, $value = null) {
        //          echo "luaB_theme_var($name, $value)<br>\n";
        YY::Log("debug", "luaB_theme_var($name, $value)");
        if ($value !== null) {
          $this['vars'][$name] = $value;
        } else if (strpos($name, 'vv.') === 0) {
          if (!isset(YY::$ME)) throw new Exception('instead_theme_var without incarnation');
          $value = YY::$ME[substr($name, strlen('vv.'))];
          $this['vars'][$name] = $value;
        } else if (!isset($this['vars'][$name])) {
          $ini_file_name = LIB_DIR . "games/" . $this['name'] . "/theme.ini";
          if (file_exists($ini_file_name)) {
            $db = dba_open($ini_file_name, 'rd', 'inifile');
            if (dba_exists($name, $db)) {
              $value = dba_fetch($name, $db);
              $this['vars'][$name] = $value;
            }
            dba_close($db);
          }
        }
        return isset($this['vars'][$name]) ? $this['vars'][$name] : null;
      },
      "instead_theme_name" => function () {
        return '.';
      },
      "instead_menu_toggle" => function () {
        echo "luaB_show_menu<br>\n";
      },
      "instead_busy" => function ($on) {
        //          echo "luaB_stead_busy<br>\n";
        //          YY::Log('debug', 'CALL: luaB_stead_busy');
        return false; // А чо тут еще делать?
      },
      "instead_sound_load" => function () {
        YY::Log('debug', 'CALL: luaB_load_sound');
        echo "luaB_load_sound<br>\n";
      },
      "instead_sound_free" => function () {
        YY::Log('debug', 'CALL: luaB_free_sound');
        //        echo "luaB_free_sound<br>\n";
      },
      "instead_sound_channel" => function () {
        YY::Log('debug', 'CALL: luaB_channel_sound');
        echo "luaB_channel_sound<br>\n";
      },
      "instead_sound_panning" => function () {
        YY::Log('debug', 'CALL: luaB_panning_sound');
        echo "luaB_panning_sound<br>\n";
      },
      "instead_sound_volume" => function () {
        YY::Log('debug', 'CALL: luaB_volume_sound');
        echo "luaB_volume_sound<br>\n";
      },
      "instead_sounds_free" => function () {
        YY::Log('debug', 'CALL: luaB_free_sounds');
        //  echo "luaB_free_sounds<br>\n";
      },

      "vv_mouse_pos" => function ($x = -1, $y = -1) {
        return [1 => 0, 2 => 0];
        //          echo "luaB_mouse_pos<br>\n";
      },
      "instead_mouse_filter" => function () {
        //          echo "luaB_mouse_filter<br>\n";
      },

      "instead_font_load" => function () {
        echo "luaB_load_font<br>\n";
      },
      "instead_font_free" => function () {
        echo "luaB_free_font<br>\n";
      },
      "instead_font_scaled_size" => function () {
        echo "luaB_font_size_scaled<br>\n";
      },
      "instead_ticks" => function () {
        return time(); // TODO: Не соответствует оригинальному INSTEAD!!!
      },
      "instead_sprite_load" => function ($fname = null, $desc = null) {
        if ($fname === null) throw new Exception('instead_sprite_load: $fname is null');
        $res = YY_Sprite::load($fname, $desc);
        //        YY::Log("debug", "luaB_load_sprite($fname,$desc)=$res");
        return $res;
      },
      "instead_sprite_text" => function () {
        echo "luaB_text_sprite<br>\n";
      },
      "instead_sprite_free" => function ($desc) {
        //        YY::Log('debug', "luaB_free_sprite($desc)");
        unset($this['images'][$desc]);
      },
      "instead_sprites_free" => function () {
        //          echo "luaB_free_sprites<br>\n";
        $this['images']->_CLEAR();
      },
      "instead_sprite_draw" => function () {
        //        echo "luaB_draw_sprite<br>\n";
      },
      "instead_sprite_copy" => function ($src = null, $x = 0, $y = 0, $w = -1, $h = -1, $dst = null, $xx = 0, $yy = 0, $alpha = 255) {
        //          echo "luaB_copy_sprite($src,$x,$y,$w,$h,$dst,$xx,$yy,$alpha)<br>\n";
        //        YY::Log("debug", "luaB_copy_sprite($src,$x,$y,$w,$h,$dst,$xx,$yy,$alpha)");
        $src = YY_Sprite::find($src);
        //        YY::Log("debug", "src=" . $src);
        $dst = YY_Sprite::find($dst);
        //        YY::Log("debug", "dst=" . $dst);
        if ($w === -1 || $h === -1) {
          $sz = $src->getSize();
          if ($w === -1) $w = $sz['w'];
          if ($h === -1) $h = $sz['h'];
        }
        $dst->copyFrom($src, $x, $y, $w, $h, $xx, $yy, 100);
        return true;
      },
      "instead_sprite_compose" => function ($src = null, $x = 0, $y = 0, $w = -1, $h = -1, $dst = null, $xx = 0, $yy = 0, $alpha = 255) {
        //          echo "luaB_compose_sprite($src,$x,$y,$w,$h,$dst,$xx,$yy,$alpha)<br>\n";
        //          YY::Log("debug", "luaB_compose_sprite($src,$x,$y,$w,$h,$dst,$xx,$yy,$alpha)");
        $src = YY_Sprite::find($src);
        $dst = YY_Sprite::find($dst);
        if ($w === -1 || $h === -1) {
          $sz = $src->getSize();
          if ($w === -1) $w = $sz['w'];
          if ($h === -1) $h = $sz['h'];
        }
        if ($alpha === null) {
          $alpha = 255;
        }
        $dst->composeFrom($src, $x, $y, $w, $h, $xx, $yy);
        return true;
      },
      "instead_sprite_fill" => function () {
        //          echo "luaB_fill_sprite<br>\n";
      },
      "instead_sprite_dup" => function () {
        echo "luaB_dup_sprite<br>\n";
      },
      "instead_sprite_alpha" => function () {
        echo "luaB_alpha_sprite<br>\n";
      },
      "instead_sprite_colorkey" => function () {
        echo "luaB_colorkey_sprite<br>\n";
      },
      "vv_sprite_size" => function ($desc) {
        return YY_Sprite::find($desc)->getSize();
      },
      "instead_sprite_scale" => function () {
        echo "luaB_scale_sprite<br>\n";
      },
      "instead_sprite_rotate" => function () {
        echo "luaB_rotate_sprite<br>\n";
      },
      "instead_sprite_text_size" => function () {
        echo "luaB_text_size<br>\n";
      },
      "instead_sprite_pixel" => function () {
        echo "luaB_pixel_sprite<br>\n";
      },

      "bit_or" => function () {
        echo "luaB_bit_or<br>\n";
      },
      "bit_and" => function () {
        echo "luaB_bit_and<br>\n";
      },
      "bit_xor" => function () {
        echo "luaB_bit_xor<br>\n";
      },
      "bit_shl" => function () {
        echo "luaB_bit_shl<br>\n";
      },
      "bit_shr" => function () {
        echo "luaB_bit_shr<br>\n";
      },
      "bit_not" => function () {
        echo "luaB_bit_not<br>\n";
      },
      "bit_div" => function () {
        echo "luaB_bit_div<br>\n";
      },
      "bit_idiv" => function () {
        echo "luaB_bit_idiv<br>\n";
      },
      "bit_mod" => function () {
        echo "luaB_bit_mod<br>\n";
      },
      "bit_mul" => function () {
        echo "luaB_bit_mul<br>\n";
      },
      "bit_imul" => function () {
        echo "luaB_bit_imul<br>\n";
      },
      "bit_sub" => function () {
        echo "luaB_bit_sub<br>\n";
      },
      "bit_add" => function () {
        echo "luaB_bit_add<br>\n";
      },
      "bit_signed" => function () {
        echo "luaB_bit_signed<br>\n";
      },
      "bit_unsigned" => function () {
        echo "luaB_bit_unsigned<br>\n";
      },
    ];

    foreach ($callbacks as $name => $function) {
      $this->stead->registerCallback($name, $function);
    }

    $this->exec("instead_sprite_size = function(desc) local sz = vv_sprite_size(desc); return sz[1], sz[2] end");
    $this->exec("instead_mouse_pos = function(x, y) local pos = vv_mouse_pos(x, y); return pos[1], pos[2] end");

    $this->exec('package.path = "' . LIB_DIR . 'stead/?.lua"');
    $this->exec("PLATFORM='UNIX' LANG='" . $this['lang'] . "'");
    $this->stead->include(LIB_DIR . 'stead/stead.lua');
    $this->stead->include(LIB_DIR . 'stead/gui.lua');
    $this->exec('stead:init()');
  }

  private function loadGame()
  {
    YY_Sprite::setGame($this);
    $this->exec('game.gui.vvGameDir = "' . LIB_DIR . 'games/' . $this['name'] . '/"');
    $this->exec('game.gui.vvRefBase = "themes/games/' . $this['name'] . '/"');
    //    $this->exec('game.gui.vvHandle = "' . YY::GetHandle($this) . '"');
    $this->exec('package.path = "' . LIB_DIR . 'stead/?.lua;' . LIB_DIR . 'games/' . $this['name'] . '/?.lua"');
    $this->stead->include(LIB_DIR . 'stead/vv-gui.lua');
    $this->stead->include(LIB_DIR . "games/" . $this['name'] . "/main.lua");
    $this->exec('game:ini()');
  }

  private function loadState($restart)
  {
    $savepath = $this->getGameDir();
    if (!file_exists($savepath)) {
      mkdir($savepath, 0777, true);
    }

    if ($restart) unset($this["tempMode"]);
    $autosaveFile = $this->getAutosaveFileName();

    if ($restart || !file_exists($autosaveFile)) {
      if ($restart && file_exists($autosaveFile)) {
        unlink($autosaveFile);
      }
      $this->doGameCmd('look');
      //      $this->exec('if not stead.started then game:start(); stead.started = true end');
      $this->exec('iface:cmd("save ' . $autosaveFile . '")');
    }
    $result = $this->exec('return iface:cmd("load ' . $autosaveFile . '")');
    if ($result) {
      if (is_array($result)) $result = implode("\n", $result);
      $this['scene'] = $result;
      //      YY::Log('debug', 'LOAD: ' . print_r($result, true));
      //    } else {
      //      YY::Log('debug', 'LOAD NONE');
    }
    //      $this->doGameCmd('look');
    $this->exec('stead.phrase_prefix = "-- "');
  }

  public function prepareAll($restart = false)
  {
    if ($this->stead) return;
    $this->resetState();
    $this->loadStead();
    $this->loadGame();
    $this->loadState($restart);
    $this->makeSnapshot();
  }

  protected function _PAINT()
  {
    YY::Log('system', '_PAINT');
    if (isset($this['theme'])) echo($this['theme']);
    echo '<div class="main">';
    echo '<div class="head">';
    $this['titleContainer']->_SHOW();
    $this['pictureContainer']->_SHOW();
    echo '</div>'; // head
    $this['waysContainer']->_SHOW();
    $this['sceneContainer']->_SHOW();
    echo '</div>';
    echo '<ul class="inventory">';
    $this['inventoryContainer']->_SHOW();
    echo '</ul>'; // inventory

    if (isset($this['selectedObject'])) {
      YY::clientExecute("$('.main, .inventory').addClass('use-mode');");
    }

    if (DEBUG_ALLOWED_IP) {
      $this['debugContainer']->_SHOW();
    }

    if (isset($this['strictMode'])) {
      YY::clientExecute("prepareStrict()");
    } else {
      if (!isset($this['disableDragAndDrop'])) {
        YY::clientExecute("prepareDragAndDrop()");
      }
      YY::clientExecute("prepareNoDrag()");
    }

    if (isset($this['timer_threshold'])) {
      $newDelay = $this['timer_threshold'] - microtime(true);
      if ($newDelay >= 0) {
        $newDelay = round($newDelay * 1000);
      } else {
        $newDelay = 0;
      }
      $this->clientSetTimer($newDelay);
    }

    //    YY::clientExecute('cancelUseMode();');

    $s = trim(mb_strtoupper(strip_tags($this['title']))); // TODO: Чо-то я не вижу, чтобы большими буквами писалось в логи!
    if ($s) YY::Log('game', "\n" . $s);
    $s = trim(strip_tags($this['scene']));
    if ($s) YY::Log('game', $s);
  }

  public function sendGameHandle()
  {
    //    YY::Log('debug', 'SET GAME HANDLE');
    YY::clientExecute('gameHandle=' . YY::GetHandle($this), true);
  }

  public function drawTitle()
  {
    if ($this['title'] !== "" && $this['title'] !== null) {
      echo '<h1>' . $this->HUMAN_COMMAND(null, htmlspecialchars(strip_tags($this['title'])), 'look') . '</h1>';
    }
  }

  public function drawPicture()
  {
    $pic = $this['picture'];
    $pics = explode(';', $pic);
    $pic = array_shift($pics);
    $sprites = [];
    foreach ($pics as $picDescr) {
      if ($picDescr && preg_match('/([^@]*)(\@(\d+),(\d+))?/', $picDescr, $a)) {
        @list($all, $sprite, $coord, $x, $y) = $a;
        $sprites[] = [
          'pic' => (string)$sprite,
          'left' => intval($x),
          'top' => intval($y)
        ];
      };
    }
    if ($pic) {
      $board = '<img class="main-image" src="themes/games/' . $this['name'] . '/' . $pic . '">';
      foreach ($sprites as $sprite) {
        $board .= '<img class="sprite" style="left:' . $sprite['left'] . 'px;top:' . $sprite['top'] . 'px"'
          . ' src="themes/games/' . $this['name'] . '/' . $sprite['pic'] . '">';
      }
      echo '<div class="image" onclick="mouseClick(event,gameHandle,this)">' . $board . '</div>';
    }
  }

  public function drawWays()
  {
    $ways = $this['ways'];
    if ($ways) {
      $ways = preg_replace(
        [
          //          '#([^|]) ([^|0-9])#',
          '#<a:([^>]*)>(([^<]*).*?)</a>#',
          '#<g:([^>]*)>#',
        ], [
          //          '$1&nbsp;$2',
          '<a href="javascript:void(0);" onclick="go(gameHandle,\'walk\',{s_way:\'$1\',s_title:\'$3\'})">$2</a>',
          '<img src="themes/games/' . $this['name'] . '/$1">',
        ]
        , $ways);
      echo '<div class="ways">' . $ways . '</div>';
    }
  }

  public function drawScene()
  {
    //    file_put_contents(LOG_DIR . 'debug.txt', $this['scene']);

    $scene = preg_replace(
      [
        '#\n#m',
        '#<w:([^>]*)>#m',
        '#<a:([^>]*)>(.*?)</a[^<>]*>#m',
        '#<g:([^>]*)>#m',
      ], [
        '</p><p>',
        '$1',
        '<a href="javascript:void(0);" name="$1">$2</a>',
        '<img src="themes/games/' . $this['name'] . '/$1">',
      ],
      $this['scene']);

    echo '<p>' . $scene . '</p>';

  }

  public function drawInventory()
  {
    $inv = $this['inventory'];
    if ($inv) {
      $inv = preg_replace(
        [
          '#<a:([^>]*' . (isset($this['selectedObject']) ? $this['selectedObject'] : '$$$') . ')>([^<]*)</a>#',
          '#<a:([^>]*)>(([^<]*).*)</a>#',
          '#<g:([^>]*)>#',
          '#<u>#',
          '#</u>#',
          '#<c>#',
          '#</c>#',
        ], [
          '<a class="dragging" href="javascript:void(0);" name="$1">$2</a>',
          '<a href="javascript:void(0);" name="$1">$2</a>',
          '<img src="themes/games/' . $this['name'] . '/$1">',
          '<span class="u">',
          '</span>',
          '<div class="c">',
          '</div>',
        ]
        , $inv);
      echo $inv;
    }
  }

  public function drawDebug()
  {
    if (DEBUG_MODE && isset(YY::$CURRENT_VIEW, YY::$CURRENT_VIEW['ROBOT'], YY::$CURRENT_VIEW['ROBOT']['_debugOutput'])) {
      echo '<pre class="debug">';
      echo htmlspecialchars(YY::$CURRENT_VIEW['ROBOT']['_debugOutput']);
      echo "</pre>";
    }
  }

  public function restart()
  {
    $this->prepareAll(true);
  }

  public function look()
  {
    if (isset($this['selectedObject'])) {
      return;
    }
    $this->press(['cmd' => 'look']);
  }

  public function walk($params)
  {
    if (isset($this['selectedObject'])) {
      return;
    }
    $this->press(['cmd' => $params['way'], 'title' => $params['title']]);
  }

  public function inventoryClick($params)
  {
    $obj = $params['obj'];
    $title = $params['title'];
    if (preg_match('/^act /', $obj, $a)) { // Менюшный клик
      //      YY::Log('stead', 'MENU ' . $obj);
      $this->press(['cmd' => $obj, 'title' => $title, 'fromInventory' => true]);
    } else { // Обычный клик
      //      YY::Log('stead', 'USE ' . $obj);
      $this->press(['cmd' => $obj, 'title' => $title, 'fromInventory' => true, 'finalize' => false]);
      $this->press(['cmd' => $obj, 'title' => $title, 'fromInventory' => true]);
    }
  }

  public function press($params)
  {
    $cmd = $params['cmd'];
    $title = isset($params['title']) ? $params['title'] : null;
    $fromInventory = isset($params['fromInventory']) && $params['fromInventory'];
    $finalize = !isset($params['finalize']) || $params['finalize'] == true;
    $menu_mode = false;
    $use_mode = false;
    $go_mode = false;
    if (preg_match('/^act (.*)$/', $cmd, $a)) {
      $menu_mode = true;
      $obj = $a[1];
    } else if (preg_match('/^use (.*)$/', $cmd, $a)) {
      $use_mode = true;
      $obj = $a[1];
    } else if (preg_match('/^go (.*)$/', $cmd, $a)) {
      $go_mode = true;
      $obj = $a[1];
    } else if ($fromInventory) {
      $use_mode = true;
    }
    if (isset($this['selectedObject'])) {
      if ($menu_mode) return;
      $cmd = explode(' ', $cmd);
      $obj = array_pop($cmd);
      if ($obj == $this['selectedObject']) {
        $cmd = (count($cmd) ? $cmd[0] : 'use') . ' ' . $obj;
      } else {
        $cmd = 'use ' . $this['selectedObject'] . ',' . $obj;
        $title = $this['selectedTitle'] . '=>' . $title;
      }
    } else {
      if ($use_mode) {
        $cmd = explode(' ', $cmd);
        assert(count($cmd) <= 2);
        if (count($cmd) === 1) {
          array_unshift($cmd, 'use');
        }
        $this['selectedAction'] = $cmd[0];
        $this['selectedObject'] = $cmd[1];
        $this['selectedTitle'] = $title;
        YY::clientExecute("$('.main, .inventory').addClass('use-mode');");
        return;
      }
      if ($menu_mode) {
        if ($fromInventory) {
          $cmd = "use " . $obj;
        } else {
          $cmd = "act " . $obj;
        }
      }
    }
    YY::Log('game', '>' . $title);
    $this->prepareAll();
    YY::Log($cmd);
    $this->doGameCmd($cmd);
    if ($finalize) {
      $this->playClickSound();
      $this->finalizeRequest();
    }
  }

  public function dropped($params)
  {
    $this->press([
      'cmd' => $params['src'],
      'title' => $params['srcTitle'],
      'fromInventory' => true,
      'finalize' => false,
    ]);
    $this->press([
      'cmd' => $params['dest'],
      'title' => $params['destTitle'],
    ]);
  }

  public function keyPressed($params)
  {
    $key = $params['code'];
    YY::Log('debug', 'KEY: ' . $key);
    switch ($key) {
      case 37:
        $name = 'left';
        break;
      case 38:
        $name = 'up';
        break;
      case 39:
        $name = 'right';
        break;
      case 40:
        $name = 'down';
        break;
      default:
        $name = 'unknown';
    }
    $this->prepareAll();
    $res = $this->exec("return stead.input('kbd', true, '$name')");
    if ($res) {
      YY::Log($res);
      $this->doGameCmd($res);
      $this->finalizeRequest();
    }
  }

  public function mouseClick($params)
  {
    $x = $params['x'];
    $y = $params['y'];
    YY::Log('stead', "MOUSE($x,$y)");
    $this->prepareAll();
    $res = $this->exec("return stead.input('mouse', 1, 1, $x, $y)");
    if ($res) {
      YY::Log($res);
      $this->doGameCmd($res);
      $this->finalizeRequest();
    }
  }

  public function timer()
  {
    if (!isset($this['timer_threshold'])) return;
    if (microtime(true) < $this['timer_threshold']) return; // Кто это дергает раньше времени? Странно!
    YY::Log('stead', "TIMER");
    $this->prepareAll();
    $result = $this->exec('return stead.timer()');
    if (isset($this['timer_delay'])) {
      while ($this['timer_threshold'] < microtime(true)) {
        $this['timer_threshold'] += $this['timer_delay'] / 1000;
      }
    }
    if ($result) {
      YY::Log($result);
      $this->doGameCmd($result);
      $this->finalizeRequest();
    }
  }

  private function finalizeRequest()
  {
    if (!isset(YY::$ME)) throw new Exception('game finalize without incarnation');

    $gameDir = $this->getGameDir();
    if (isset($this['invalid'])) {

      if (file_exists($gameDir . 'temp')) @unlink($gameDir . 'temp');
      if (file_exists($gameDir . 'current')) @unlink($gameDir . 'current');
      unset($this['invalid']);

    } else {

      $autosaveFile = $this->getAutosaveFileName();
      $this->exec('iface:cmd("save ' . $autosaveFile . '")');

      // Звук

      if ($this['soundOn']) {
        $stopChannels = [];
        for ($i = 0; $i <= 7; $i++) {
          $channelName = 'sound_' . $i;
          if (isset($this->changedPlayers[$channelName])) {
            if ($this['audioPlayers'][$channelName]) {
              $ch = $this['audioPlayers'][$channelName];
              YY::clientExecute('playSound("' . $ch['source'] . '", "' . $channelName . '", ' . $ch['loop'] . ');');
            } else {
              $stopChannels[] = $i;
            }
          }
        }
        if (count($stopChannels)) {
          YY::clientExecute('stopChannels([' . implode(',', $stopChannels) . ']);');
        }
      } else if ($this['clickOn']) {
        $ch = $this['audioPlayers']['sound_0'];
        YY::clientExecute('playSound("' . $ch['source'] . '", "sound_0", 1);');
      }

      // Музыка
      if ($this['musicOn'] && isset($this->changedPlayers['music'])) {
        if ($this['audioPlayers']['music'] === null) {
          YY::clientExecute('stopChannelNow("music");');
        } else {
          YY::clientExecute('playSound("' . $this['audioPlayers']['music']['source'] . '", "music", ' . $this['audioPlayers']['music']['loop'] . ');');
        }
      }

      // Спрайты
      $changedSprites = YY_Sprite::finalize();
      if (count($changedSprites)) {
        YY::clientExecute('reloadSprites([' . implode(',', $changedSprites) . ']);');
      }

      // Считаем оперативную статистику
      $statFileName = $gameDir . 'stat';
      $now = time();
      if (file_exists($statFileName)) {
        list($cnt, $duration, $last) = explode(',', file_get_contents($statFileName));
        $cnt = intval($cnt);
        $duration = intval($duration);
        $last = intval($last);
      } else {
        $cnt = 0;
        $duration = 0;
        $last = $now;
      }
      $cnt++;
      $delta = $now - $last;
      if ($delta < 60) $duration += $delta;
      $last = $now;
      $userId = YY::$ME->_YYID;
      file_put_contents($statFileName, "$cnt,$duration,$last,$userId");
    }
  }

  protected function resetState()
  {
    unset($this['timer_threshold']);
    unset($this['timer_delay']);
    $this['music'] = null;
    $this['images'] = [];
    $this['vars'] = [];
    $theme = WEB_DIR . 'themes/games/' . $this['name'] . '/theme/theme.css';
    if (file_exists($theme)) {
      $this['theme'] = '<link rel="stylesheet" href="themes/games/' . $this['name'] . '/theme/theme.css">';
    } else {
      $this['theme'] = '<link rel="stylesheet" href="themes/games/default/theme/theme.css">';
    }
  }

  public function setMusic($music, $loop = 0)
  {
    $loop = intval($loop); // На всякий случай
    if ($music) {
      if (preg_match('/^(.*)\.[^.]*$/', $music, $a)) { // Убираем расширение
        $music = $a[1];
      }
      $changed = !$this['audioPlayers']['music']
        || $this['audioPlayers']['music']['source'] !== $music
        || $this['audioPlayers']['music']['loop'] !== $loop;
      if ($changed) {
        $this['audioPlayers']['music'] = [
          'source' => $music,
          'loop' => $loop,
        ];
        $this->changedPlayers['music'] = true;
      }
    } else {
      $changed = !!$this['audioPlayers']['music'];
      if ($changed) {
        $this['audioPlayers']['music'] = null;
        $this->changedPlayers['music'] = true;
      }
    }
  }

  public function playSound($sound, $channel, $loop = 1)
  {
    //    YY::Log('debug', "PLAY SOUND: ($sound,$channel,$loop)");
    $loop = intval($loop);
    if ($sound) {
      if (preg_match('/^(.*)\.[^.]*$/', $sound, $a)) { // Убираем расширение
        $sound = $a[1];
      }
      if ($channel == -1) { // Если канал не указан, то ищем свободный
        for ($ch = 1; $ch < 8; $ch++) {
          if (!$this['audioPlayers']['sound_' . $ch] || $this['audioPlayers']['sound_' . $ch]['source'] === $sound) {
            $channel = $ch;
            break;
          }
        }
      }
      if ($channel >= 0) { // В противном случае звук просто теряется
        $changed = !$this['audioPlayers']['sound_' . $channel]
          || $this['audioPlayers']['sound_' . $channel]['source'] !== $sound
          || $this['audioPlayers']['sound_' . $channel]['loop'] !== $loop;
        if ($changed) {
          $this['audioPlayers']['sound_' . $channel] = [
            'source' => $sound,
            'loop' => $loop,
          ];
          $this->changedPlayers['sound_' . $channel] = true;
        }
      }
    } else {
      if ($channel == -1) { // Если канал не указан, то выключаем все
        $start = 0;
        $end = 7;
      } else {
        $start = $channel;
        $end = $channel;
      }
      for ($i = $start; $i <= $end; $i++) {
        $channelName = 'sound_' . $i;
        $changed = !!$this['audioPlayers'][$channelName];
        if ($changed) {
          $this['audioPlayers'][$channelName] = null;
          $this->changedPlayers[$channelName] = true;
        }
      }
    }
  }

  public function playClickSound() // public чтобы из настроек кликнуть при включении звука
  {
    if (!$this['clickOn']) return;
    if (isset($this['clickSound'])) {
      if ($this['clickSound'] === null) {
        $file = null;
      } else {
        $file = 'themes/games/' . $this['name'] . '/' . $this['clickSound'];
      }
    } else {
      $file = 'themes/games/default/snd/click.ogg';
    }
    if ($file) {
      $this->playSound($file, 0, 1);
    }
  }

  private function clientSetTimer($delay)
  {
    $delay = ($delay === null) ? 'null' : $delay;
    $script = "setGameTimer($delay)";
    YY::clientExecute($script);
  }

  /**
   * @param $gameDir
   * @return string
   */
  private function getAutosaveFileName()
  {
    return $this->getGameDir() . (isset($this['tempMode']) ? "temp" : "current");
  }

  /**
   * @return string
   */
  private function getGameDir()
  {
    return GAMEDATA_DIR . $this['name'] . '/' . $this['userYYID'] . "/";
  }

}

class YY_Container extends YY_Robot
{

  protected function _PAINT()
  {
    $parentMethod = isset($this['method']) ? $this['method'] : 'doReallyPaint';
    $this['parent']->$parentMethod();
  }

}
