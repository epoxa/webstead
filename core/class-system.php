<?php

/**
 * Связанные понятия:
 * 1) Инкарнация. Виртуальный персонаж. Имеет длительный срок жизни. Как правило соответствует одному реальному пользователю.
 * 2) Отображение. Может быть несколько для одной инкарнации. Содержит текущее состояние отображения роботов пользователю.
 * 3) Сеанс. Соответствует интервалу связи от момента открытия страницы до обновления страницы или закрытия браузера (даже, если сессия PHP не прерывается).
 *    Поддерживает список роботов, отображение которых передано на клиент.
 *    Также, возможно, прерывается при смене инкарнации (например, после аутентификации анонимного пользователя).
 *    Без понятия сеанс (и соответствующего объекта) можно обойтись, но тогда на клиент могут передаваться лишние отображения роботов.
 * 4) Сессия PHP - содержит ссылку на запись в списке инкарнаций.
 *    Может последовательно (но не одновременно!) содержать несколько сеансов.
 *    Может менять текущую инкарнацию (по-видимому с прерыванием сеанса? а может и нет!)
 */

require_once CLASSES_DIR . "class-robot.php";

const IGNORE_DBA_ERROR = 'dba_open(/www/vvproject.ru/runtime/data/DATA.db,ct): Driver initialization failed for handler: db4: Unable to establish lock';
const IGNORE_LUA_ERROR = 'Lua::eval(): corrupted Lua object (1)'; // Единичку в конце я приляпал в исходники php-lua

function _error_handler($errno, $errstr, $errfile, $errline, $errcontext)
{
  if ($errstr === IGNORE_DBA_ERROR) return false;
  if ($errstr === IGNORE_LUA_ERROR) return false;
  if ($errno != E_NOTICE) { // Подавляемые значком @ ошибки, не протоколируем.
    $msg = $errno . ': ' . $errfile . "(" . $errline . ")" . "\n" . $errstr;
    YY::Log('error', $msg);
    if (isset(YY::$WORLD, YY::$WORLD['SYSTEM'], YY::$WORLD['SYSTEM']['error'])) {
      YY::$WORLD['SYSTEM']->error(array('message' => $msg));
    }
  }
  return false;
}

set_error_handler('_error_handler');

function __autoload($className)
{
  if (strpos($className, 'YY_') === 0) {
    $className = substr($className, 3);
  }
  $defFile1 = CLASSES_DIR . $className . '.php';
  $defFile2 = CLASSES_DIR . 'class-' . strtolower($className) . '.php';
  if (file_exists($defFile1)) {
    require_once $defFile1;
  } else if (file_exists($defFile2)) {
    require_once $defFile2;
  } else if (isset(YY::$WORLD)) {
    $sys = YY::$WORLD['SYSTEM'];
    if (isset($sys['classFile'])) {
      $classFile = $sys['classFile'];
      if (isset($classFile[$className])) {
        require_once CLASSES_DIR . $classFile[$className];
      }
    }
  }
}

/*
* Исключение предназначенное для прерывания выполнения скрипта при выполнении метода GO
*/

class EReloadSignal extends Exception
{
}

// TODO: Привести как-то к единообразию - либо везде использовать self:: либо YY:: (второе удобнее, когда переносишь из/в другие классы)

/**
 * @property YY_Data CONFIG
 * @property YY_Data VIEWS
 */
class YY extends YY_Robot // Странно, похоже, такое наследование позволяет вызвать защищенный метод _PAINT у (другого) экземпляра класса YY_Robot
{

  // TODO: Может $WORLD и $ME сделать функциями?
  static public $WORLD;
  static public $ME;

  static public $RELOAD_URL;
  static public $RELOAD_TOP;

  static public $CURRENT_VIEW;
  /**
   * @var YY_Data $OUTGOING
   */
  static private $OUTGOING;
  static private $DELETED;
  static private $HEAD;
  static private $EXECUTE_BEFORE;
  static private $EXECUTE_AFTER;

  static public function Log($kind, $msg = null)
  {
    if (DEBUG_MODE) { // TODO: Чой-то так? Кое какие логи могут быть всегда, например, gatekeeper. Надо отдельно для каждого регулировать, видимо.
      require_once CLASSES_DIR . 'class-log.php';
      if ($msg === null) { // Отладочный вывод можно одним аргументом передавать
        $msg = $kind;
        $kind = 'debug';
      }
      YY_Log::Log($kind, $msg);
    }
  }

  static public function GetEditorPath($item)
  {
    // TODO: Переделать на использование свойства _source (сделав его у всех узлов конфигурации)
    $fileWay = $item['_path'];
    if ($fileWay === null) throw new Exception('Node not found in config');
    $fileWay = explode('/', $fileWay);
    array_shift($fileWay);
    $root = self::$WORLD;
    $physicalPath = EDITOR_CONFIG_ROOT;
    $inlinePrefix = '';
    $lastDir = '';
    foreach ($fileWay as $delta) {
      // TODO: Должно быть согласовано со стратегией из YY_Exporter
      $isInline = $delta === 'visual' || isset($root[$delta]['kind']);
      if ($isInline) {
        if ($inlinePrefix > '') $inlinePrefix .= '-';
        $inlinePrefix .= $delta;
      } else {
        if ($inlinePrefix > '') {
          $lastDir = $inlinePrefix . '-' . $delta;
          $subdir = '.' . $lastDir;
        } else {
          $lastDir = $delta;
          $subdir = $delta;
        }
        $physicalPath .= '/' . $subdir;
        $inlinePrefix = '';
      }
      $root = $root[$delta];
    }
    $physicalPath .= '/' . $lastDir . '.php';
    return $physicalPath;
  }

  static public function Config($way = null)
  {
    $object = self::$WORLD['CONFIG'];
    if ($way) {
      $way = explode('.', $way);
      while (count($way)) {
        $prop = array_shift($way);
        if ($prop !== '') $object = $object[$prop];
      }
    }
    return $object;
  }

  static public function Local($way)
  {
    $object = self::$WORLD['LOCAL'];
    $way = explode('.', $way);
    while (count($way)) {
      $prop = array_shift($way);
      if ($prop !== '') $object = $object[$prop];
    }
    return $object;
  }

  static public function LoadWorld()
  {
    if (isset(self::$WORLD)) return;
    $fname = DATA_DIR . "world.id";
    if (file_exists($fname)) {
      $world_id = file_get_contents($fname);
      self::$WORLD = YY_Data::_load($world_id);
    } else {
      $world_id = null;
    }
    if (!self::$WORLD) {
      YY::Log('system', 'Will create World...');
      $init = [];
      if ($world_id) {
        $init['_YYID'] = $world_id;
      }
      self::$WORLD = new YY_Data($init);
      self::$WORLD->_REF; // Чтобы сохранялся в постоянной памяти
      require_once CLASSES_DIR . "class-importer.php";
      YY_Importer::reloadWorld();
      file_put_contents($fname, self::$WORLD->_YYID);
      YY::Log('system', 'World created!');
    }
  }

  static public function createNewView($YYID = null)
  {
    assert(!isset(YY::$CURRENT_VIEW));

    // Проверяем на превышение максимального количества. При превышении убиваем самое старое или которое вообще без даты доступа.
    $views = self::$ME->VIEWS;
    $maxViews = self::$WORLD['SYSTEM']['maxViewsPerIncarnation'];
    while (count($views) >= $maxViews) {
      $oldestViewKey = null;
      $oldestAccess = null;
      foreach ($views as $key => $view) {
        if ($oldestAccess === null || !isset($view['lastAccess']) || $view['lastAccess'] < $oldestAccess) {
          $oldestViewKey = $key;
          $oldestAccess = isset($view['lastAccess']) ? $view['lastAccess'] : 0;
        }
      }
      if (isset($oldestViewKey)) {
        $oldestView = $views->_DROP($oldestViewKey);
        unset($views[$oldestViewKey]);
        $oldestView->_delete();
      }
    }

    // Создаем новый сеанс
    $init = array(
      'RENDERED' => [],
      'HEADERS' => [],
      'DELETED' => [],
      'created' => time(), // Нужно для протоколирования
    );
    if ($YYID) { // TODO: Не помню, зачем создавать новый объект с таким-же YYID, который был? Может, поэтому и теряются объекты?
      // TODO: Надо это или прокомментировать, или удалить
      $init['_YYID'] = $YYID;
    }
    $newView = new YY_Data($init);
    $views[$newView->_YYID] = $newView;
    self::$CURRENT_VIEW = $newView;
    if (isset($_SESSION['request'])) {
      // Перемещаем изначальный запрос из сессии PHP в свой сеанс
      $request = $_SESSION['request'];
      $queryString = $_SESSION['queryString'];
      unset($_SESSION['request']);
      unset($_SESSION['queryString']);
    } else {
      $request = [];
      $queryString = '';
    }
//    YY::Log('debug', 'VIEW CREATED. REQUEST:');
//    foreach($request as $key => $val) {
//      YY::Log('debug', "$key=$val");
//    }
    YY::$CURRENT_VIEW['request'] = $request;
    YY::$CURRENT_VIEW['queryString'] = $queryString;
    YY::$CURRENT_VIEW['secure'] = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'];

    YY::Log('system', 'New view created: ' . $newView->_full_name());

    // Пользовательская бизнес-логика
    YY::$WORLD['SYSTEM']->viewCreated();
  }

  static public function DrawEngine($templateName)
  {
    YY::Log('system', 'Draw engine ' . $templateName);

//    // Из оверлея или браузером загружается движок. Если сессиия отсутствует, то создаем новую
//    if (!isset(self::$ME)) { // Такое бывает, например, при рестарте из интерфейса
//      self::createNewIncarnation();
//    }

    // Новая вьюха должна быть уже создана ранее, в момент запроса оверлея
//    assert(self::$CURRENT_VIEW);

    $debugOutput = YY_Log::GetScreenOutput();
    if (isset(self::$CURRENT_VIEW) && isset(self::$CURRENT_VIEW['ROBOT']) && is_object(self::$CURRENT_VIEW['ROBOT'])) {
      self::$CURRENT_VIEW['ROBOT']['_debugOutput'] = $debugOutput;
    }

    self::DisableCaching();
    header('Content-Type: text/html; charset=utf-8');
    include TEMPLATES_DIR . $templateName;
  }

  static public function TryRestore()
  {
    if (isset(self::$ME)) { // TODO: По-хорошему не должно быть, но почему-то бывают повторные входы
      YY::Log('error', 'Duplicate TryRestore');
      return;
    }
    // Загружаем инкарнацию, если есть
    if (YY_Utils::IsSessionValid() && isset($_SESSION[YYID])) {
      self::$ME = YY_Data::_load($_SESSION[YYID]); // Может и не существовать - тогда останется null
    }
  }

  static public function createNewIncarnation($YYID = null)
  {
    assert(!self::$ME);
    $init = ['VIEWS' => []];
    if ($YYID) {
      $init['_YYID'] = $YYID;
    }
    self::$ME = new YY($init);
    self::$ME->_REF; // Блокирует объект, чтобы он записался в постоянную память
    YY_Utils::StartSession(YY::$ME->_YYID);
    if (!$YYID) {
      YY::$WORLD['SYSTEM']->incarnationCreated();
    }
  }

  static private function sendJson($data)
  {
    $json = json_encode($data);
    header('Content-Type: application/json; charset=utf-8');
    //    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Length: ' . strlen($json));
    echo $json;
  }

  /**
   * @param string|null $url
   * @param bool $top
   * @throws EReloadSignal
   * Может вызываться не только в обработчиках, но и при отрисовке робота из _PAINT
   */
  static public function redirectUrl($url = null, $top = false)
  {
    YY::Log('system', 'Reload signal initiated');
    if ($url) {
      self::$RELOAD_URL = $url;
    } else {
      self::$RELOAD_URL =
        (isset(YY::$CURRENT_VIEW, YY::$CURRENT_VIEW['secure']) && YY::$CURRENT_VIEW['secure']
          ? 'https://' : 'http://') . ROOT_URL . (YY::$CURRENT_VIEW['queryString'] ? '?' . YY::$CURRENT_VIEW['queryString'] : '');
    }
    self::$RELOAD_TOP = $top;
    throw new EReloadSignal();
  }

  static private function drawReload()
  {
    $signal = self::$RELOAD_TOP ? '!!' : '!';
    if (self::$RELOAD_URL) {
      $url = self::$RELOAD_URL;
      self::$RELOAD_URL = null;
    } else {
      $url = null;
    }
    self::sendJson(array($signal => $url));
    YY::Log('system', 'Reload signal send');
  }

  static public function Run()
  {
    YY_Data::InitializeStorage();

    self::Log(array('time', 'system'), '============START============');

    // Загружаем или создаем мир
    self::LoadWorld(); // TODO: Может не стоит загружать, если статический запрос? Надо потестировать профайлером!

    self::$WORLD['SYSTEM']->started();

    self::$ME = null;

    $view = null;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

      if (isset($_GET['who'])) { // В этом случае who содержит код сеанса и дескриптор (внутри сеанся) робота, склеенные через дефис

        self::_GET($_GET); // Самостоятельно ставит заголовки, в т. ч. управляющие кэшем

      } else {

        YY::$WORLD['SYSTEM']->processGetRequest();

      }

    } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {

      if (!isset($_POST['view'])) {
        YY::Log('system', 'Invalid POST request: ' . print_r($_POST, true));
        self::drawReload();
        YY_Cache::Flush(); // TODO: А зачем? Что могло поменяться в мире? Разве что SYSTEM->started выполнено. Но там сейчас пусто
        return;
      }

      self::$RELOAD_URL = false;
      self::$OUTGOING = new YY_Data();
      self::$HEAD = [];
      self::$EXECUTE_BEFORE = null;
      self::$EXECUTE_AFTER = null; // По крайней мере, clientExecute может вызываться в обработчике, а не только в PAINT
      // TODO: Проверить, может и showRobot можно тогда?

      $viewId = $_POST['view'];
      $isFirstPost = count($_POST) === 1;

      self::TryRestore();

      if ($isFirstPost) {
        self::$WORLD['SYSTEM']->incarnationRequired();
        if (!isset(self::$ME)) {
          YY::createNewIncarnation();
        }
      } else if (!isset(self::$ME)) {
        self::drawReload();
        return;
      }

      YY::$CURRENT_VIEW = null;
      $views = YY::$ME['VIEWS'];
      if (isset($views[$viewId])) {
        $view = $views[$viewId];
        if ($view === null || !isset($view['ROBOT']) || $view['ROBOT'] === null) { // Видимо, сильно старый, удален.
          unset($views[$viewId]); // Без куратора нет смысла в сеансе.
          YY_Cache::Flush();
          self::drawReload();
          return;
        }
        YY::$CURRENT_VIEW = $view;
      } else if ($isFirstPost) {
        try {
          YY::createNewView($viewId);
        } catch (EReloadSignal $e) {
          YY_Cache::Flush();
          self::drawReload();
          return;
        }
        if (isset(YY::$CURRENT_VIEW['ROBOT'])) { // Устанавливается в SYSTEM->viewCreated()
          $robot = YY::$CURRENT_VIEW['ROBOT'];
          $robotAttributes = isset($robot['attributes']) ? YY::GetAttributesText($robot['attributes']) : "";
          $robotText = '<div id="_YY_' . YY::GetHandle(YY::$CURRENT_VIEW["ROBOT"]) . '"' . $robotAttributes . '></div>';
          // TODO: Каким-то образом бывает, что добавляется к непустому телу документа. Надо бы разобраться
          YY::clientExecute("document.body.insertAdjacentHTML('afterBegin','$robotText');", true);
        }
      } else {
        // Ненормальная ситуация
        YY_Cache::Flush();
        self::drawReload();
        return;
      }
      self::$CURRENT_VIEW->lastAccess = time();

      YY::$WORLD['SYSTEM']->initializePostRequest();

      if (count($_FILES)) { // Вот такие странные соглашения!

        self::_UPLOAD(array_pop($_FILES), $_GET);
        YY::Log('system', 'File uploaded');
        // TODO: Тут можно бы как-то сообщать об успехе

      } else {

        self::DisableCaching();

        if ($isFirstPost) { // Специальный случай - инициализация после обновления.

          YY::$WORLD['SYSTEM']->viewRetrieved();

        } else {

          try {
            self::_DO($_POST);
            $debugOutput = YY_Log::GetScreenOutput();
            if (isset(self::$CURRENT_VIEW) && isset(self::$CURRENT_VIEW['ROBOT']) && is_object(self::$CURRENT_VIEW['ROBOT'])) {
              self::$CURRENT_VIEW['ROBOT']['_debugOutput'] = $debugOutput;
            }
            $output = ob_get_clean();
            if ($output > "") {
              YY::Log(array('system', 'error'), "Output during method execution:\n" . $output);
            }
          } catch (Exception $e) {
            YY::Log('error', $e->getMessage());
            if (DEBUG_MODE && DEBUG_ALLOWED_IP) {
              throw $e;
            } else {
              self::drawReload();
              YY_Cache::Flush();
              return;
            }
          }
        }

        if (YY::$RELOAD_URL) {
          self::drawReload();
        } else {
          ob_start();
          try {
            $robot = isset(self::$CURRENT_VIEW['ROBOT']) ? self::$CURRENT_VIEW['ROBOT'] : null; // TODO: Может быть уничтожен в обработчике
            if ($robot) $robot->_SHOW(); // На самом деле не выводится, а помещается в массив
            ob_end_clean();
          } catch (Exception $e) {
            if (get_class($e) !== 'EReloadSignal') {
              YY::Log('system,error', $e->getMessage());
            }
            ob_end_clean(); // Отрисовка не производится ни при ошибке ни при редиректе или перезагрузке сеанса
          }
          if (self::$RELOAD_URL) {
            self::drawReload(); // TODO: А не надо ли выполнить что-нибудь типа discardChanges?
          } else {
            self::sendJson(self::receiveChanges());
          }
        }
      }

    } else if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {

      // Тупо игнорируем, а то логи засираются из-за всяких роботов

    } else {

      YY::Log('system', "Unknown HTTP request method: " . $_SERVER['REQUEST_METHOD']);

    }

    YY_Cache::Flush();

    self::Log(array('time', 'system'), '============FINISH===========');
  }

  static public function DisableCaching()
  {
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    header("Expires: Mon, 26 Jul 1997 05:05:05 GMT");
    header("Cache-Control: no-cache, must-revalidate");
    header("Cache-Control: post-check=0,pre-check=0", false);
    header("Cache-Control: max-age=0", false);
    header("Pragma: no-cache");
  }

  static public function GetHandle($object, $view = null)
  {
    if (!$view) {
      $view = self::$CURRENT_VIEW;
    }
    if (isset($view['TRANSLATE'])) {
      $trans = $view['TRANSLATE'];
    } else {
      $trans = new YY_Data(); // TODO: Заменить на статический метод, который генерирует ссылку, чтобы всегда работать со ссылками
      $view['TRANSLATE'] = $trans;
    }
    if (isset($trans[$object])) {
      $transId = $trans[$object];
    } else {
      $transId = count($trans); // Количество скалярных свойств
      $trans[$object] = $transId;
      $trans[$transId] = $object;
//      YY::Log('debug', $view->_YYID . '(' . $transId .') <= ' . $object);
    }
    return $transId;
  }

  static private function GetObjectByHandle($handle, $view = null)
  {
    if (!$view) {
      $view = self::$CURRENT_VIEW;
    }
//    YY::Log('debug', '? ' . $view->_YYID . '(' . $handle .') ');
    if (!isset($view['TRANSLATE'])) {
      return null;
    }
    if (isset($view['TRANSLATE'][$handle])) {
      return $view['TRANSLATE'][$handle];
    } else {
      return null;
    }
  }

  static private function GetHeadersTexts($include, $firstTime)
  {
    if (is_object($include)) {
      if (isset(self::$CURRENT_VIEW->HEADERS[$include])) {
        return [];
      }
      self::$CURRENT_VIEW->HEADERS[$include] = null;
      $res = [];
      foreach ($include as $key => $subinclude) {
        if (substr($key, 0, 1) === '_') continue;
        $res = array_merge($res, self::GetHeadersTexts($subinclude, $firstTime));
      }
      return $res;
    } else { // TODO: Это нужно либо запретить, либо добавить параметр $firstTime, и строки инициализировать только при первоначальном входе.
      return array($include);
    }
  }

  static public function GetAttributesText($attributes)
  {
    if (is_string($attributes)) {
      return ' ' . $attributes;
    } else {
      $res = '';
      foreach ($attributes as $name => $value) {
        if ($name === 'style' && !is_string($value)) {
          $val = '';
          foreach ($value as $k => $v) {
            $val .= ';' . $k . ':' . $v;
          }
          $value = substr($val, 1);
        }
        $res .= ' ' . $name . '="' . htmlspecialchars($value) . '"';
      }
    }
    return $res;
  }

  static private function modifyVisual($visual, &$htmlBefore, &$htmlBeforeContent, &$htmlAfterContent, &$htmlAfter, &$attributes, &$styles, &$classes)
  {
    if (!isset($visual)) return;
    if (is_string($visual)) {
      if (isset(self::$WORLD['SYSTEM']['defaultStyles'])) {
        $defaultStyles = self::$WORLD['SYSTEM']['defaultStyles'];
        if (isset($defaultStyles[$visual])) {
          $visual = $defaultStyles[$visual];
        } else {
          throw new Exception('В разделе стилей не найден стиль ' . $visual);
        }
      } else {
        throw new Exception('Не найден раздел для стиля: ' . $visual);
      }
    }
    foreach ($visual as $name => $value) {
      if (substr($name, 0, 1) === '_' || $name === 'class' || $name === 'style' || $name === 'before' || $name === 'after' || $name === 'beforeContent' || $name === 'afterContent') {
        continue;
      }
      if ($name === '@') {
        self::modifyVisual($value, $htmlBefore, $htmlBeforeContent, $htmlAfterContent, $htmlAfter, $attributes, $styles, $classes);
      } else if ($name === '@@') {
        foreach ($value as $vis) {
          self::modifyVisual($vis, $htmlBefore, $htmlBeforeContent, $htmlAfterContent, $htmlAfter, $attributes, $styles, $classes);
        }
      } else {
        $attributes[$name] = $value;
      }
    }
    if (isset($visual['before'])) $htmlBefore = $visual['before'] . $htmlBefore;
    if (isset($visual['beforeContent'])) $htmlBeforeContent = $htmlBeforeContent . $visual['beforeContent'];
    if (isset($visual['afterContent'])) $htmlAfterContent = $visual['afterContent'] . $htmlAfterContent;
    if (isset($visual['after'])) $htmlAfter = $htmlAfter . $visual['after'];
    if (isset($visual['class'])) {
      $cls = explode(' ', $visual['class']);
      foreach ($cls as $className) {
        if ($className !== '') {
          $classes[$className] = null;
        }
      }
    }
    if (isset($visual['style'])) {
      // TODO: Может сделать тут вариант, когда стиль передается строкой (текст всего стиля), или нефиг?
      foreach ($visual['style'] as $key => $val) {
        if (substr($key, 0, 1) === '_') continue;
        $styles[$key] = $val;
      }
    }
  }

  static private function parseVisual($visual, &$htmlBefore, &$htmlBeforeContent, &$htmlAfterContent, &$htmlAfter, &$attributesText)
  {
    // TODO: Сделать универсальную возможность задавать HTML деревом, аналогично сохранению в XML, только можно попроще (используя имя свойства в качестве тэга)
    $htmlBefore = '';
    $htmlBeforeContent = '';
    $htmlAfterContent = '';
    $htmlAfter = '';
    $attributes = null;
    $styles = null;
    $classes = null;
    self::modifyVisual($visual, $htmlBefore, $htmlBeforeContent, $htmlAfterContent, $htmlAfter, $attributes, $styles, $classes);
    $attributesText = '';
    if ($attributes) {
      foreach ($attributes as $name => $value) {
        $attributesText .= ' ' . $name . '="' . htmlspecialchars($value) . '"'; // TODO: А может тут другую какую-то функцию надо.
      }
    }
    if ($classes) {
      $attributesText .= ' class="' . implode(' ', array_keys($classes)) . '"';
    }
    if ($styles) {
      $attributesText .= ' style="';
      foreach ($styles as $key => $val) {
        $attributesText .= $key . ': ' . $val . ';'; // А тут типа не надо ни htmlspecialchars ни другого ничего?
      }
      $attributesText .= '"';
    }
  }

  /**
   * @param $visual
   * @param null|'before'|'after' $part
   *
   * @return string
   */

  static public function drawVisual($visual, $part = null)
  {
    /**@var $htmlBefore string
     * @var $htmlBeforeContent string
     * @var $htmlAfterContent string
     * @var $htmlAfter string
     * @var $attributesText string
     */
    self::parseVisual($visual, $htmlBefore, $htmlBeforeContent, $htmlAfterContent, $htmlAfter, $attributesText);
    if ($attributesText > '') {
      YY::Log('error', "Can not use attributes in 'drawVisual' (" . $attributesText . ")");
    }
    $res = '';
    if ($part === null || $part === 'before') $res .= $htmlBefore . $htmlBeforeContent;
    if ($part === null || $part === 'after') $res .= $htmlAfterContent . $htmlAfter;
    return $res;
  }

  static private function packParams($params, $isScript)
  {
    $res = '';
    if ($params) {
      foreach ($params as $paramName => $paramValue) {
        if ($res) $res .= $isScript ? ',' : '&';
        $paramType = null;
        if (is_array($paramValue)) { // Ссылка на параметр какого-то робота (возможно, себя), который будет передаваться как строка
          $paramType = "r_";
          $paramValue = self::GetHandle($paramValue[0]) . '.' . $paramValue[1];
        } else if (is_object($paramValue)) {
          $paramType = "o_";
          $paramValue = self::GetHandle($paramValue);
        } else if (is_bool($paramValue)) {
          $paramType = "b_";
          $paramValue = ($paramValue ? "1" : "0");
        } else if (is_string($paramValue)) {
          $paramType = "s_";
        } else if (is_int($paramValue)) {
          $paramType = "i_";
        } else if (is_numeric($paramValue)) {
          $paramType = "d_";
        }
        if ($paramType) {
          if ($isScript) {
            $res .= $paramType . $paramName . ":'" . htmlspecialchars($paramValue) . "'";
          } else {
            if ($paramType) $res .= $paramType . $paramName . "=" . urlencode($paramValue);
          }
        }
      }
    }
    return $res;
  }

  static public function drawCommand($visual, $htmlCaption, $robot, $method, $params = null)
  {
    /**@var $htmlBefore string
     * @var $htmlBeforeContent string
     * @var $htmlAfterContent string
     * @var $htmlAfter string
     * @var $attributesText string
     */
    self::parseVisual($visual, $htmlBefore, $htmlBeforeContent, $htmlAfterContent, $htmlAfter, $attributesText);
    $otherParams = self::packParams($params, true);
    return $htmlBefore . '<a' . $attributesText . ' href="javascript:void(0);" onclick="go(' . self::GetHandle($robot) . ',\'' . htmlspecialchars($method) . '\',{' . $otherParams . '});">' . $htmlBeforeContent . $htmlCaption . $htmlAfterContent . '</a>' . $htmlAfter;
  }

  static public function drawInternalLink($visual, $htmlCaption, $robot, $params = null)
  {
    /**@var $htmlBefore string
     * @var $htmlBeforeContent string
     * @var $htmlAfterContent string
     * @var $htmlAfter string
     * @var $attributesText string
     */
    self::parseVisual($visual, $htmlBefore, $htmlBeforeContent, $htmlAfterContent, $htmlAfter, $attributesText);
    $otherParams = self::packParams($params, false);
    return $htmlBefore . '<a' . $attributesText . ' href="?who=' . self::$CURRENT_VIEW->_YYID . '-' . self::GetHandle($robot) . '&' . $otherParams . '" target="_blank">' . $htmlBeforeContent . $htmlCaption . $htmlAfterContent . '</a>' . $htmlAfter;
  }

  static public function drawDocument($visual, $robot, $params = null)
  {
    /**@var $htmlBefore string
     * @var $htmlBeforeContent string
     * @var $htmlAfterContent string
     * @var $htmlAfter string
     * @var $attributesText string
     */
    self::parseVisual($visual, $htmlBefore, $htmlBeforeContent, $htmlAfterContent, $htmlAfter, $attributesText);
    $otherParams = self::packParams($params, false);
    return $htmlBefore . '<iframe src="?who=' . self::$CURRENT_VIEW->_YYID . '-' . self::GetHandle($robot) . '&' . $otherParams . '"' . $attributesText . '>' . $htmlBeforeContent . $htmlAfterContent . '</iframe>' . $htmlAfter;
  }

  static public function drawExternalLink($visual, $htmlCaption, $href)
  {
    /**@var $htmlBefore string
     * @var $htmlBeforeContent string
     * @var $htmlAfterContent string
     * @var $htmlAfter string
     * @var $attributesText string
     */
    self::parseVisual($visual, $htmlBefore, $htmlBeforeContent, $htmlAfterContent, $htmlAfter, $attributesText);
    return $htmlBefore . '<a' . $attributesText . ' href="' . $href . '">' . $htmlBeforeContent . $htmlCaption . $htmlAfterContent . '</a>' . $htmlAfter;
  }

  static public function drawInput($visual, $robot, $propertyName)
  {
    /**@var $htmlBefore string
     * @var $htmlBeforeContent string
     * @var $htmlAfterContent string
     * @var $htmlAfter string
     * @var $attributesText string
     */
    self::parseVisual($visual, $htmlBefore, $htmlBeforeContent, $htmlAfterContent, $htmlAfter, $attributesText);
    //    if ($htmlBeforeContent > '' || $htmlAfterContent > '') YY::Log('error', "Can not use 'beforeContent' or 'afterContent' in 'drawInput'");
    if (isset($visual['multiline']) && $visual['multiline']) {
      return $htmlBefore . $htmlBeforeContent . '<textarea' . $attributesText . ' name="' . $propertyName . '" id="' . self::GetHandle($robot) . '[' . $propertyName . ']" onchange="changed(this)" />' . htmlspecialchars($robot->$propertyName) . '</textarea>' . $htmlAfterContent . $htmlAfter;
    } else {
      return $htmlBefore . $htmlBeforeContent . '<input' . $attributesText . ' type="text" name="' . $propertyName . '" id="' . self::GetHandle($robot) . '[' . $propertyName . ']" value="' . htmlspecialchars($robot->$propertyName) . '" onchange="changed(this)" />' . $htmlAfterContent . $htmlAfter;
    }
  }

  static public function drawText($visual, $htmlText)
  {
    /**@var $htmlBefore string
     * @var $htmlBeforeContent string
     * @var $htmlAfterContent string
     * @var $htmlAfter string
     * @var $attributesText string
     */
    self::parseVisual($visual, $htmlBefore, $htmlBeforeContent, $htmlAfterContent, $htmlAfter, $attributesText);
    $htmlText = $htmlBeforeContent . $htmlText . $htmlAfterContent;
    if ($attributesText) $htmlText = '<span' . $attributesText . '>' . $htmlText . '</span>';
    return $htmlBefore . $htmlText . $htmlAfter;
  }

  // TODO: Надо кардинально переделать. Таким образом даже два аплоада не будут работать.
  static public function drawFile($visual, $robot, $propertyName)
  {
    /**@var $htmlBefore string
     * @var $htmlBeforeContent string
     * @var $htmlAfterContent string
     * @var $htmlAfter string
     * @var $attributesText string
     */
    self::parseVisual($visual, $htmlBefore, $htmlBeforeContent, $htmlAfterContent, $htmlAfter, $attributesText);
    $html = '<form style="display:inline" id="file_upload_form" method="post" enctype="multipart/form-data" action="?who=' . self::GetHandle($robot) . '&what=' . $propertyName . '">';
    $html .= $htmlBeforeContent;
    $html .= '<input type="file"' . $attributesText . ' name="' . $propertyName . '" id="' . self::$CURRENT_VIEW->_YYID . '-' . self::GetHandle($robot) . '[' . $propertyName . ']" onchange="changed(this)" />';
    $html .= $htmlAfterContent;
    $html .= '<input type="hidden" name="view" value="' . self::$CURRENT_VIEW->_YYID . '" />'; // TODO: Не нужно, наверное!
    $html .= '</form>';
    return $htmlBefore . $html . $htmlAfter;
  }

  /**
   * @param $visual YY_Data
   * @param $robot YY_Robot
   *
   * @return string
   */
  static public function drawSlaveRobot($visual, $robot)
  {
    /**@var $htmlBefore string
     * @var $htmlBeforeContent string
     * @var $htmlAfterContent string
     * @var $htmlAfter string
     * @var $attributesText string
     */
    self::parseVisual($visual, $htmlBefore, $htmlBeforeContent, $htmlAfterContent, $htmlAfter, $attributesText);
    ob_start();
    $robot->_SHOW();
    $htmlText = ob_get_clean();
    $htmlText = $htmlBeforeContent . $htmlText . $htmlAfterContent;
    // TODO: Сделать единый механизм для showRobot и drawSlaveRobot вместо вкладывания в div
    if ($attributesText) $htmlText = '<div' . $attributesText . '>' . $htmlText . '</div>';
    return $htmlBefore . $htmlText . $htmlAfter;
  }

  static public function clientExecute($script, $immidiate = false)
  {
    if ($immidiate) self::$EXECUTE_BEFORE .= "\n" . $script;
    else self::$EXECUTE_AFTER .= "\n" . $script;
  }

  //  static public function  getCurrentCurator()
  //  {
  //    if (!isset(self::$CURRENT_VIEW)) return null;
  //    if (!isset(self::$CURRENT_VIEW['ROBOT'])) return null;
  //    return self::$CURRENT_VIEW['ROBOT'];
  //  }

  /**
   * @static
   *
   * @param YY_Robot $robot
   *
   * @return void
   */
  static public final function showRobot($robot)
  {

    $handle = self::GetHandle($robot);

    // HEAD and attributes

    $firstTime = !isset(self::$CURRENT_VIEW->HEADERS[$robot]);

    // TODO: Может сделать, чтобы все вхождения в HEAD тоже были роботами, с возможностью замены содержимого? А может и лишнее это.

    if (isset($robot['include'])) {
      self::$HEAD = array_merge(self::$HEAD, self::GetHeadersTexts($robot['include'], $firstTime));
    }

    if ($firstTime) { //  Атрибуты робота устанавливаются в окне один раз, и менять нельзя
      if (isset($robot['attributes'])) {
        $attributes = self::GetAttributesText($robot['attributes']);
      } else {
        $attributes = '';
      }
      self::$CURRENT_VIEW->HEADERS[$robot] = $attributes;
    } else {
      $attributes = self::$CURRENT_VIEW->HEADERS[$robot];
    }

    // BODY

    // TODO: Сделать, чтобы хотя бы некоторые свойства стиля этого элемента (например display, visibility, position, а может и все свойства)
    // TODO: можно было менять программно, не передавая заново содержимое блока

    $faceExists = isset(self::$CURRENT_VIEW->RENDERED[$handle]);
    if ($faceExists) {
      $wasFace = self::$CURRENT_VIEW->RENDERED[$handle];
    } else {
      $wasFace = '';
      //self::$OUTGOING[$robot] = null; // Резервируем место, чтобы выдавались от старших к младшим
    }
    ob_start();
    try {
      $robot->_PAINT();
      $newFace = ob_get_clean();
    } catch (Exception $e) {
      if (get_class($e) === 'EReloadSignal') throw $e;
      $errorMessage = $e->getMessage();
      if (DEBUG_MODE && DEBUG_ALLOWED_IP) {
        $newFace = ob_get_clean() . '<br/>' . $errorMessage;
      } else {
        $newFace = 'Ошибка :(';
      }
      YY::Log('error', $errorMessage);
    }
    echo "<div id=_YY_" . $handle . $attributes . ">";
    if ($newFace !== $wasFace) {
      self::$OUTGOING[$handle] = $newFace;
    }
    echo "</div>";
  }

  static public function robotDeleting($robot)
  {
    if (self::$ME && !self::$ME->_DELETED) {
      foreach (self::$ME->VIEWS as $view) {
        $handle = self::GetHandle($robot, $view);
        $view->DELETED[] = $handle;
        unset($view->RENDERED[$handle]);
      }
    }
  }

  static private function receiveChanges()
  {
    $json = [];
    if (self::$EXECUTE_BEFORE !== null) {
      $json['<'] = self::$EXECUTE_BEFORE;
      self::$EXECUTE_BEFORE = null;
    }
    foreach (self::$CURRENT_VIEW->DELETED as $yyid) {
      $json['-_YY_' . $yyid] = null;
    }
    self::$CURRENT_VIEW->DELETED->_CLEAR();
    foreach (self::$HEAD as $idx => $head) {
      $json['^' . $idx] = $head;
    }
    foreach (self::$OUTGOING as $handle => $view) {
      $json['_YY_' . $handle] = $view;
      self::$CURRENT_VIEW->RENDERED[$handle] = $view;
    }
    if (self::$EXECUTE_AFTER !== null) {
      $json['>'] = self::$EXECUTE_AFTER;
      self::$EXECUTE_AFTER = null;
    }
    return $json;
  }

  // При вызове этой функции надо подавлять вывод на экран. Только действие, никакого отображения!

  static private final function _DO($_DATA)
  {
    $who = $_DATA['who'];
    assert(isset($who));
    $who = self::GetObjectByHandle($who);
    assert(isset($who));
    $do = $_DATA['do'];
    // if (!isset($do)) $do = 'do'; // Вот нифига! Раз явно не указано, то просто заполняем данные, не вызывая методов
    // TODO: Как бы тут (да и не только тут), по изменяемым свойствам автоматом узнать, что инвалидейтить?
    if (substr($do, 0, 1) === "_") throw new Exception("Can not call system methods"); // Это что еще за юный хакер тут?

    self::Log('system', 'DO ' . $who . '->' . $do);

    foreach ($_DATA as $key => $val) {
      if ($key === 'do' || $key === 'who' || $key === 'view') {
        // Уже обработаны
      } else if (is_array($val)) {
        // Изменившиеся свойства объектов
        $obj = self::GetObjectByHandle($key);
        foreach ($val as $prop => $prop_val) {
          $obj->$prop = $prop_val;
        }
      }
    }

    if (isset($do)) {
      $params = [];
      foreach ($_DATA as $key => $val) {
        if ($key === 'do' || $key === 'who' || $key == 'view' || is_array($val)) {
          // Уже обработаны
        } else {
          $type = substr($key, 0, 2);
          switch ($type) {
            case 'r_':
              $val = preg_split('/\./', $val);
              if (count($val) === 1) {
                //                $obj = $this; // TODO: Разобраться, может ли быть такой случай
                $paramName = $val[0];
              } else {
                $obj = self::GetObjectByHandle($val[0]);
                $paramName = $val[1];
              }
              $val = $obj->$paramName;
              break;
            case 'o_':
              if ($val == "") {
                $val = null;
              } else {
                $val = self::GetObjectByHandle($val);
              }
              break;
            case 'b_':
              if ($val == "") {
                $val = null;
              } else $val = ($val == "1");
              break;
            case 's_':
              break;
            case 'i_':
              if ($val == "") {
                $val = null;
              } else $val = intval($val);
              break;
            case 'd_':
              if ($val == "") {
                $val = null;
              } else $val = floatval($val);
              break;
            default:
              throw new Exception("Untyped parameter: " . $key);
          }
          $key = substr($key, 2);
          $params[$key] = $val;
        }
      }
      try {
        call_user_func(array($who, $do), $params);
      } catch (Exception $e) {
        if (get_class($e) !== 'EReloadSignal') throw($e);
      }
    }
  }

  static private final function _UPLOAD($file, $_DATA)
  {
    $who = $_DATA['who'];
    assert(isset($who));
    $who = explode('-', $who);
    assert(count($who) === 2);
    $view = YY_Data::_load($who[0]);
    assert(isset($view));
    $who = self::GetObjectByHandle($who[1], $view);
    assert(isset($who));
    $prop_name = $_DATA['what'];
    assert(isset($prop_name));
    $who[$prop_name] = file_get_contents($file['tmp_name']);
  }

  static private final function _GET($_DATA)
  {
    $who = $_DATA['who'];
    assert(isset($who));
    $who = explode('-', $who);
    assert(count($who) === 2);
    $view = YY_Data::_load($who[0]);
    assert(isset($view));
    $who = self::GetObjectByHandle($who[1], $view);
    assert(isset($who));
    $methodName = isset($_DATA['get']) ? $_DATA['get'] : 'get';

    $params = [];
    foreach ($_DATA as $key => $val) {
      if ($key === 'who' || $key === 'get') {
        // Уже обработано
      } else {
        $type = substr($key, 0, 2);
        switch ($type) {
          case 'r_':
            $val = preg_split('/\./', $val);
            if (count($val) === 1) {
              //              $obj = $this; // TODO: Разобраться
              $paramName = $val[0];
            } else {
              $obj = self::GetObjectByHandle($val[0], $view);
              $paramName = $val[1];
            }
            $val = $obj->$paramName;
            break;
          case 'o_':
            if ($val == "") {
              $val = null;
            } else {
              $val = self::GetObjectByHandle($val, $view);
            }
            break;
          case 'b_':
            if ($val == "") {
              $val = null;
            } else $val = ($val == "1");
            break;
          case 's_':
            break;
          case 'i_':
            if ($val == "") {
              $val = null;
            } else $val = intval($val);
            break;
          case 'd_':
            if ($val == "") {
              $val = null;
            } else $val = floatval($val);
            break;
          default:
            throw new Exception("Untyped parameter: " . $key);
        }
        $key = substr($key, 2);
        $params[$key] = $val;
      }
    }

    $who->$methodName($params);
    //    call_user_func(array($who, $methodName), $params);
  }

}
