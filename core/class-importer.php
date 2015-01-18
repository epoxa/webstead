<?php

// TODO: Почему-то при обычном полном обновлении каждый раз появляется один безхозный сохраненный объект (утечка памяти)

class YY_Builder {
  const WAY_SEPARATOR = '/';
  const FILE_SEPARATOR = '/';
}

class YY_Importer
{

  static private $ROOT_IMPORT_WAY;
  static private $CURRENT_IMPORT_WAY;
  static private $CURRENT_IMPORT_PATH;
  static private $WORLD_REAL_PATH;
  static private $NEW_SUBTREE;
  static private $IMPORT_WAITING;
  static private $ALREADY_IMPORTED;
  static private $NODE_OWNERS;
  static private $NODE_OWNER_KEYS;
  static private $newUpdateMode;
  static private $idList;

  static public function UpdateNodePathInfo($node, $currentWay, $unsetSource = false)
  {
    $node['_path'] = $currentWay;
    if ($unsetSource) unset($node['_source']);
    $keysArray = $node->_all_keys();
    foreach ($keysArray as $key) {
      $val = $node[$key];
      if ($val instanceof YY_Ref && $val->_OWNER) {
        if (is_object($key)) throw new Exception('Object keys not allowed (in ' . $currentWay . ')');
        $val = $val->_DAT;
        self::UpdateNodePathInfo($val, $currentWay . YY_Builder::WAY_SEPARATOR . $key, $unsetSource);
      }
    }
  }

  static private function ImportDirectoryFromFileSystem(&$object, $wayFromRoot, $fullRealPath)
  {
    YY::Log('import', 'ImportDirectoryFromFileSystem: $wayFromRoot = ' . $wayFromRoot);
    if (isset(self::$ALREADY_IMPORTED[$wayFromRoot])) { // TODO: А нужно ли это теперь?
      $object = self::$ALREADY_IMPORTED[$wayFromRoot];
      return;
    }
    //    $realPath = self::getRealPath($wayFromRoot);
    if (!is_dir($fullRealPath)) throw new Exception('Not a directory: ' . $fullRealPath);
    $lastDirName = pathinfo($fullRealPath, PATHINFO_BASENAME);
    if (substr($lastDirName, 0, 1) === '.') $lastDirName = substr($lastDirName, 1);
    $scriptFileName = realpath($fullRealPath . YY_Builder::FILE_SEPARATOR . $lastDirName . '.php');
    if (file_exists($scriptFileName)) {
      $oldWay = self::$CURRENT_IMPORT_WAY;
      $oldPath = self::$CURRENT_IMPORT_PATH;
      self::$CURRENT_IMPORT_WAY = $wayFromRoot;
      self::$CURRENT_IMPORT_PATH = $fullRealPath;
      $res = require($scriptFileName);
      self::$CURRENT_IMPORT_PATH = $oldPath;
      self::$CURRENT_IMPORT_WAY = $oldWay;
      if (is_array($res)) $res = new YY_Data($res);
    } else {
      $res = new YY_Data();
    }
    $object = $res; // TODO: Нужно рассмотреть случай, когда объект будет передан уже заполненым, и не уничтожать его, если совпадают классы.
    $dh = opendir($fullRealPath);
    if (!$dh) {
      throw new Exception('Can not open dir: ' . $fullRealPath);
    }
    while (($childFileName = readdir($dh)) !== false) {
      $fullChildPath = $fullRealPath . YY_Builder::FILE_SEPARATOR . $childFileName;
      if (is_dir($fullChildPath) && substr($childFileName, 0, 1) != ".") {
        if (!isset($object[$childFileName])) {
          $childWayFromRoot = $wayFromRoot . YY_Builder::WAY_SEPARATOR . $childFileName;
          $child = null;
          self::ImportDirectoryFromFileSystem($child, $childWayFromRoot, $fullChildPath); // Не зависимо от того, есть ли уже такое свойство
          $object[$childFileName] = $child;
        }
      }
    }
    closedir($dh);
    self::$ALREADY_IMPORTED[$wayFromRoot] = $object; // $ALREADY_IMPORTED - обычный массив, поэтому владение не устанавливается
    // Нужно для последующего инкрементального обновления.
    if ($scriptFileName !== false) {
      $sourceInfo = YY::$WORLD->SOURCE;
      $sourceInfo->fileTime[$scriptFileName] = filemtime($scriptFileName);
      $sourceInfo->fileNode[$scriptFileName] = new YY_Ref($res, false);
      $sourceInfo->nodeFile[$res] = $scriptFileName;
    }
    if (file_exists($scriptFileName)) {
      $object['_source'] = $scriptFileName;
    }
  }

  /**
   * @static
   * @param $way - здесь указывается именно путь в системе дерева, а не файловой системы!
   *  но он считается относительно узла, который возвращает текущий файл при загрузке.
   * @return string|YY_Ref
   * @throws Exception
   * Предназначена для вызова пользовательскими скриптами в процессе загрузки мира из файловой системы (внутри вызова ReloadWorldPart)
   */

  static private function FS($way)
  {
    //    $realPath = self::getRealPath($way);
    //    if (file_exists($realPath) && !is_dir($realPath)) {
    //      $text = file_get_contents($realPath);
    //      // Уберем начальный маркер php, если есть.
    //      $pos = strpos($text,'<?');
    //      if ($pos !== false) {
    //        if (trim(substr($text,0,$pos)) === '') { // Только начальный
    //          $pos += 2;
    //          if (substr($text,$pos,3) === 'php') $pos += 3;
    //          $text = trim(substr($text,$pos));
    //        }
    //      }
    //      $res = eval($text);
    //      if ($res === false) {
    //        throw new Exception('Parse error of data creation script (' . $realPath . ') in ' . self::$CURRENT_IMPORT_WAY);
    //      }
    //      return $res;
    //    } else {
    // В случае программной загрузки объекта через FS, создаем временную заглушку и помещаем её в очередь ожидания, чтобы загрузка поддиректорий имела приоритет на владение объектом.
    $wayFromRoot = self::getWayFromRoot($way);
    $stub = new YY_Data(array('wayFromRoot' => $wayFromRoot));
    self::$IMPORT_WAITING[$stub] = $stub;
    return $stub->_REF;
    //    }
  }

  static private function FS_OWN($path, $wayOffset = null)
  {
    $fullRealPath = self::getFullPath($path);
    if (!file_exists($fullRealPath)) throw new Exception('File not exists: ' . $fullRealPath . '. Path: ' . $path . '. CURRENT_IMPORT_PATH: ' . self::$CURRENT_IMPORT_PATH);
    if (is_dir($fullRealPath)) {
      if (self::$newUpdateMode) {
        $lastDir = pathinfo($fullRealPath, PATHINFO_BASENAME);
        if (substr($lastDir, 0, 1) === '.') $lastDir = substr($lastDir, 1);
        $fullScriptPath = realpath($fullRealPath . YY_Builder::FILE_SEPARATOR . $lastDir . '.php');
        $notChanged = isset(YY::$WORLD->SOURCE->fileTime[$fullScriptPath]) && filemtime($fullScriptPath) === YY::$WORLD->SOURCE->fileTime[$fullScriptPath];
        if ($notChanged) {
          $res = YY::$WORLD->SOURCE->fileNode[$fullScriptPath];
          // Отбираем у предыдущего владельца
          $YYID = $res->_YYID;
          $res = self::$NODE_OWNERS[$YYID]->_DROP(self::$NODE_OWNER_KEYS[$YYID]);
          unset(self::$NODE_OWNERS[$YYID]);
          unset(self::$NODE_OWNER_KEYS[$YYID]);
        } else {
          $res = self::importNode(self::getWayFromRoot($wayOffset), $fullScriptPath);
        }
      } else {
        $res = null;
        if ($wayOffset === null) {
          $a = explode(YY_Builder::FILE_SEPARATOR, $path);
          $wayOffset = array_pop($a);
          if (substr($wayOffset, 0, 1) === '.') $wayOffset = substr($wayOffset, 1);
        }
        self::ImportDirectoryFromFileSystem($res, self::getWayFromRoot($wayOffset), $fullRealPath);
      }
    } else {
      $res = include $fullRealPath;
    }
    return $res;
  }

  static private function FS_SCRIPT($path)
  {
    $fullRealPath = self::getFullPath($path);
    if (!file_exists($fullRealPath)) {
      throw new Exception('File not exists (' . $fullRealPath . ') in ' . self::$CURRENT_IMPORT_WAY);
    } else if (is_dir($fullRealPath)) {
      throw new Exception('Can not import folder as script (' . $fullRealPath . ') in ' . self::$CURRENT_IMPORT_WAY);
    } else {
      if (true /* DEBUG_MODE */) {
        $text = "return (require '" . $fullRealPath . "');";
      } else {
        $text = file_get_contents($fullRealPath);
        // Уберем начальный маркер php, если есть.
        $pos = strpos($text, '<?');
        if ($pos !== false) {
          if (trim(substr($text, 0, $pos)) === '') { // Только начальный
            $pos += 2;
            if (substr($text, $pos, 3) === 'php') $pos += 3;
            $text = trim(substr($text, $pos));
          }
        }
      }
      return $text;
    }
  }

  static private function FS_TEXT($path)
  {
    $fullRealPath = self::getFullPath($path);
    if (!file_exists($fullRealPath)) {
      throw new Exception('File not exists (' . $fullRealPath . ') in ' . self::$CURRENT_IMPORT_WAY);
    } else if (is_dir($fullRealPath)) {
      throw new Exception('Can not import folder as text (' . $fullRealPath . ') in ' . self::$CURRENT_IMPORT_WAY);
    } else {
      return file_get_contents($fullRealPath);
    }
  }

  /**
   * Функция предназначена для того, чтобы создавать объекты с объектными индексами свойств.
   * Если четное количество аргументов, то они попарно означают имя и значение свойств создаваемого объекта.
   * Есди нечетное, то то же самое, а первый из них содержит имя класса объекта (по умолчанию YY_Data).
   */
  static private function FS_OBJECT()
  {
    $cnt = func_num_args();
    if ($cnt % 2) {
      $cls = func_get_arg(0);
      $start = 1;
    } else {
      $cls = 'YY_Data';
      $start = 0;
    }
    $yyid = null;
    for ($i = $start; $i < $cnt; $i += 2) {
      if (func_get_arg($i) === '_YYID') {
        $yyid = func_get_arg($i + 1);
      };
    }
    $init = [];
    if ($yyid) $init['_YYID'] = $yyid;
    $res = new $cls($init);
    for ($i = $start; $i < $cnt; $i += 2) {
      $res[func_get_arg($i)] = func_get_arg($i + 1);
    }
    return $res;
  }

  static private function getFullPath($path)
  {
    if (substr($path, 0, 1) === YY_Builder::FILE_SEPARATOR) {
      $path = self::$WORLD_REAL_PATH . $path;
    } else {
      $path = self::$CURRENT_IMPORT_PATH . YY_Builder::FILE_SEPARATOR . $path;
    }
    return realpath($path);
  }

  static private function getWayFromRoot($way)
  {
    $relative = substr($way, 0, 1) !== YY_Builder::WAY_SEPARATOR;
    if ($relative) {
      $way = self::$CURRENT_IMPORT_WAY . YY_Builder::WAY_SEPARATOR . $way;
    }
    $way = str_replace('\\', YY_Builder::WAY_SEPARATOR, $way); // TODO: По идее, надо запретить указывать при вызове функции FS обратные слеши, и убрать эту замену
    assert(substr($way, 0, 1) === YY_Builder::WAY_SEPARATOR);
    $way = substr($way, 1);
    $src = explode(YY_Builder::WAY_SEPARATOR, $way);
    $dst = [];
    $skip = 0;
    while (($dir = array_pop($src)) !== null) {
      if ($dir === '.') {
        // Ничего не делаем
      } else if ($dir === '..') {
        $skip++;
      } else if ($skip) {
        $skip--;
      } else {
        array_unshift($dst, $dir);
      }
    }
    assert(!$skip);
    return YY_Builder::WAY_SEPARATOR . implode(YY_Builder::WAY_SEPARATOR, $dst);
  }

  /**
   * @static
   * @param string $wayFromRoot - нормализованный
   * @throws Exception
   * @return YY_Ref
   * Возвращает null, если дочерний объект не существует.
   * Генерирует исключение, если по указанному пути находится скалярное свойство.
   */

  static private function getExistingChild($wayFromRoot, $root)
  {
    if (isset(self::$ALREADY_IMPORTED[$wayFromRoot])) {
      return self::$ALREADY_IMPORTED[$wayFromRoot];
    }
    if ($wayFromRoot === YY_Builder::WAY_SEPARATOR) $wayFromRoot = '';
    $props = explode(YY_Builder::WAY_SEPARATOR, $wayFromRoot);
    if ($props[0] === '') array_shift($props);
    $obj = $root;
    foreach ($props as $prop) {
      if (!isset($obj[$prop])) {
        $obj = null;
        break;
      }
      $obj = $obj[$prop];
      if (!is_object($obj)) throw new Exception('Not an object: ' . $wayFromRoot . " (" . var_export($obj, true) . ")");
    }
    if (isset($obj)) { // Нашли в указаном поддереве
      self::$ALREADY_IMPORTED[$wayFromRoot] = $obj; // Кэширование. Чтобы последующие ссылки возвращали уже точно этот объект
    }
    return $obj;
  }

  static private function calculateRelativePath($fromWay, $toWay)
  {
    $fromWay = explode(YY_Builder::WAY_SEPARATOR, $fromWay);
    array_shift($fromWay);
    $toWay = explode(YY_Builder::WAY_SEPARATOR, $toWay);
    array_shift($toWay);
    while (count($fromWay) && count($toWay) && $fromWay[0] === $toWay[0]) {
      array_shift($fromWay);
      array_shift($toWay);
    }
    return str_repeat('..' . YY_Builder::FILE_SEPARATOR, count($fromWay)) . implode(YY_Builder::WAY_SEPARATOR, $toWay);
  }

  /**
   * @static
   * @param $object
   * Разрешает отложенные программные ссылки на директории, загружая их при необходимости.
   * При загрузке владельцем становится первая из ожидающих ссылок
   */

  static private function continuePendingImport($object, $root)
  {
    static $dep = 0;
    $dep++;

    // Пройдемся по свойствам с объектными ключами, причем незагруженными могут быть как значения, так и ключи
    $keys_changed = false;
    $old_keys = array_merge($object->_object_keys(), $object->_scalar_keys());
    $newKeys = [];
    $newValues = [];
    foreach ($old_keys as $key) {
      $val = $object->_DROP($key);
      if (isset(self::$IMPORT_WAITING[$key])) {
        $wayFromRoot = $key->wayFromRoot;
        YY::Log('import', $wayFromRoot);
        $relPath = self::calculateRelativePath(self::$ROOT_IMPORT_WAY, $wayFromRoot);
        // TODO: Наверное, надо предусмотреть случай "внешних ссылок", когда ссылка идет на узел, который есть в старом дереве, но за пределами нового поддерева
        $realKey = self::getExistingChild($relPath, $root);
        if ($realKey) {
          unset(self::$IMPORT_WAITING[$key]);
          $key = $realKey;
          $keys_changed = true;
        }
      }
      $newKeys[] = $key;
      if (is_object($val)) {
        if (isset(self::$IMPORT_WAITING[$val])) {
          $wayFromRoot = $val->wayFromRoot;
          YY::Log('import', $wayFromRoot);
          $relPath = self::calculateRelativePath(self::$ROOT_IMPORT_WAY, $wayFromRoot);
          // TODO: Наверное, надо предусмотреть случай "внешних ссылок", когда ссылка идет на узел, который есть в старом дереве, но за пределами нового поддерева
          $newVal = self::getExistingChild($relPath, $root);
          unset(self::$IMPORT_WAITING[$val]);
          $val = $newVal;
        } else if ($val instanceof YY_Ref && $val->_OWNER) {
          self::continuePendingImport($val, $root);
        } else if (!($val instanceof YY_Ref)) {
          self::continuePendingImport($val, $root);
        }
      }
      $newValues[] = $val;
    }
    $object->_CLEAR();
    foreach ($newKeys as $index => $key) {
      $val = $newValues[$index];
      $object[$key] = $val;
    }
    $dep--;
  }

  static private $mappings;

  static private function fillObjectMappings($old, $new, $path)
  {
    if (!is_object($old)) return false;
    $id = $new->_YYID;
    if (array_key_exists($id, self::$idList)) {
      //      throw new Exception("Bad new tree! Path: " . $path);
    };
    self::$idList[$id] = true;
    $hasNewMapping = !isset(self::$mappings[$new->_YYID]);
    if ($hasNewMapping) {
      self::$mappings[$new->_YYID] = $old;
    }
    $oldKeys = array_merge($old->_scalar_keys(), $old->_object_keys());
    $newKeys = array_merge($new->_scalar_keys(), $new->_object_keys());
    YY::Log('import', 'fillObjectMappings: ' . $new);
    foreach ($newKeys as $newKey) {
      $val = $new[$newKey];
      if (is_object($val) && $val->_OWNER) {
        if (is_object($newKey)) {
          if (isset(self::$mappings[$newKey->_YYID])) {
            $oldKey = self::$mappings[$newKey->_YYID];
          } else if (in_array($newKey, $oldKeys)) { // TODO: Здесь, видимо надо искать не просто, а функцией YY_Data::_IsEqual
            $oldKey = $newKey;
          } else $oldKey = null;
        } else {
          $oldKey = $newKey;
        }
        if (isset($old[$oldKey])) {
          $oldChild = $old[$oldKey];
          // TODO: Если скалярное значение меняется на объект, то тут возникает ошибка!
          if (self::fillObjectMappings($oldChild, $val, $path . YY_Builder::FILE_SEPARATOR . $newKey)) $hasNewMapping = true;
        }
      }
    }
    return $hasNewMapping;
  }

  static private function compareObject($old, $new)
  {
    // TODO: Похоже, надо делать две отдельные функции, а не кучу ветвлений по $doUpdate
    $oldKeys = $old->_all_keys();
    $newKeys = $new->_all_keys();
    $temp = new YY_Data();
    foreach ($oldKeys as $oldKey) {
      $temp[$oldKey] = $old->_DROP($oldKey);
    }
    $old->_CLEAR();
    foreach ($newKeys as $newKey) {
      $val = $new[$newKey];
      if (is_object($newKey) && isset(self::$mappings[$newKey->_YYID])) {
        $oldKey = self::$mappings[$newKey->_YYID];
      } else {
        $oldKey = $newKey;
      }
      if (!is_object($val)) {
        $old[$oldKey] = $val;
      } else {
        if (!$val->_OWNER) {
          if (isset(self::$mappings[$val->_YYID])) $val = self::$mappings[$val->_YYID];
          $old[$oldKey] = $val;
        } else if (isset($temp[$oldKey])) {
          $oldChild = $temp->_DROP($oldKey);
          if ($oldChild instanceof YY_Ref) $oldChild = $oldChild->_DAT;
          if ($val instanceof YY_Ref) $val = $val->_DAT;
          if (is_object($oldChild) && get_class($oldChild) === get_class($val)) {
            self::compareObject($oldChild, $val);
            $old[$oldKey] = $oldChild;
          } else {
            $old[$oldKey] = $new->_DROP($newKey); // Не делается копия, так как может быть не YY_Data, а производный класс
          }
        } else {
          $old[$oldKey] = $new->_DROP($newKey); // Не делается копия, так как может быть не YY_Data, а производный класс
        }
      }
    }
  }

  static private function wake($root)
  {
    static $dep;
    try {
      foreach ($root as $key => $child) {
        if (is_object($child) && $child->_OWNER) {
          self::$NODE_OWNERS[$child->_YYID] = $root;
          self::$NODE_OWNER_KEYS[$child->_YYID] = $key;
          self::wake($child);
        }
      }
    } catch (Exception $e) {
      // Тупо так
    }
  }

  static private function processWorldPart($wayToPart, $worldTemplateDir, $doLoad)
  {
    self::$newUpdateMode = false;
    self::setupWorldSourceInfo();
    self::$ROOT_IMPORT_WAY = $wayToPart;
    $originalSubtree = self::getExistingChild($wayToPart, YY::$WORLD);
    assert(isset($originalSubtree), 'Way absent: ' . $wayToPart);
    self::$NODE_OWNERS = []; // Здесь вроде не нужно, но wake их заполняет
    self::$NODE_OWNER_KEYS = []; // Здесь вроде не нужно, но wake их заполняет
    self::wake($originalSubtree);
    if ($originalSubtree instanceof YY_Ref) {
      $originalSubtree = $originalSubtree->_DAT; // А может и на ссылках будет работать?
    }
    eval('self::$NEW_SUBTREE = new ' . get_class($originalSubtree) . '();');
    self::$WORLD_REAL_PATH = $worldTemplateDir;
    self::$IMPORT_WAITING = new YY_Data();
    self::$CURRENT_IMPORT_PATH = '';
    self::$ALREADY_IMPORTED = [];
    // Сначала импортируем новую копию, все невладеющие ссылки в которой заменены на заглушки, помещенные в $IMPORT_WAITING
    self::ImportDirectoryFromFileSystem(self::$NEW_SUBTREE, $wayToPart, self::$WORLD_REAL_PATH . $wayToPart /* Временно */);
    // Разрешаем все ссылки-заглушки, причем, если путь не найден в мире, и он входит в импортируемое поддерево, то берется новый объект (если есть)
    $pendingCount = count(self::$IMPORT_WAITING->_object_keys());
    while ($pendingCount) {
      $r = count(self::$IMPORT_WAITING->_object_keys()) . ' (';
      foreach (self::$IMPORT_WAITING->_object_keys() as $k) {
        $r .= self::$IMPORT_WAITING[$k]->wayFromRoot . ', ';
      }
      $r .= ')';
      YY::Log('import', $r);
      self::continuePendingImport(self::$NEW_SUBTREE, self::$NEW_SUBTREE);
      $newPendingCount = count(self::$IMPORT_WAITING->_object_keys());
      if ($newPendingCount === $pendingCount) {
        YY::Log('import, error', "Can not load next nodes: \n" . $r);
        // TODO: Разобраться
        throw new Exception("Can not load next nodes: \n" . $r);
      }
      $pendingCount = $newPendingCount;
    }
    self::$IMPORT_WAITING = null;
    // Рекурсивно сравниваем со старым деревом.
    // В зависимости от режима, или просто сравниваем, или заменем отличающиеся свойства.
    // При замене, если свойство имеется, причем объектное, причем тип объекта совпадает с новым значением,
    // то используется (изменяется) оригинальное свойство. В противном случае - устанавливается новое.
    self::$mappings = [];
    self::$idList = []; // Это чисто для отслеживания бесконечной рекурсии
    while (self::fillObjectMappings($originalSubtree, self::$NEW_SUBTREE, '')) ;
    self::compareObject($originalSubtree, self::$NEW_SUBTREE, $doLoad);
    if (DEBUG_MODE) {
      self::UpdateNodePathInfo($originalSubtree, $wayToPart);
    }
    self::$NEW_SUBTREE->_delete();
  }

  static public function reloadWorldPart($wayToPart, $worldTemplateDir)
  {
    self::processWorldPart($wayToPart, $worldTemplateDir, true);
  }

  static public function reloadWorld()
  {
    $godId = isset(YY::$WORLD['godId']) ? YY::$WORLD['godId'] : null;
    $temp = new YY_Data(YY::$WORLD);
    self::reloadWorldPart('', CONFIGS_DIR . '.current');
    $configTimestamp = time();
    $subroots = ['CONFIG', 'SYSTEM'];
    if (file_exists(CONFIGS_DIR . 'server.id')) { // Признак кластерного устройства
      $serverId = file_get_contents(CONFIGS_DIR . 'server.id');
      YY::$WORLD['LOCAL'] = YY::$WORLD['SERVERS'][$serverId];
      $subroots[] = 'SERVERS';
      $subroots[] = 'LOCAL';
    }
    foreach ($temp as $node => $dummy) {
      if (!in_array($node, $subroots)) {
        YY::$WORLD[$node] = $temp->_DROP($node);
      }
    }
    if ($godId) {
      YY::$WORLD['godId'] = $godId;
    }
    YY::$WORLD['configTimestamp'] = $configTimestamp;
    unset(YY::$WORLD['configModified']);
    return $configTimestamp;
  }

  static public function compareWorldPart($wayToPart, $worldTemplateDir) // TODO: А похоже, эта функция не нужна, а значит и параметр $doLoad в processWorldPart
  {
    self::processWorldPart($wayToPart, $worldTemplateDir, false);
  }

  static private function setupWorldSourceInfo()
  {
    if (!isset(YY::$WORLD['SOURCE'])) {
      YY::$WORLD['SOURCE'] = array(
        'nodeFile' => [],
        'fileNode' => [],
        'fileTime' => [],
      );
    }
  }

  static private function needUpdateNode($node, &$fileName)
  {
    if (!isset(YY::$WORLD->SOURCE->nodeFile[$node])) return false; // Это промежуточный, а не ключевой узел
    $fileName = YY::$WORLD->SOURCE->nodeFile[$node]; // Если не существует, то это ошибка
    return !file_exists($fileName) || filemtime($fileName) !== YY::$WORLD->SOURCE->fileTime[$fileName];
  }

  static private function updateNode($wayFromRoot, $currentNode, &$newNode)
  {
    if (self::needUpdateNode($currentNode, $sourceFileName)) {
      $newNode = self::importNode($wayFromRoot, $sourceFileName);
      return true;
    } else {
      $keysArray = array_merge($currentNode->_scalar_keys(), $currentNode->_object_keys());
      foreach ($keysArray as $key) {
        $val = $currentNode[$key];
        if ($val instanceof YY_Ref && $val->_OWNER) {
          if (is_object($key)) throw new Exception('Object keys not allowed (in ' . $wayFromRoot . ')');
          if (self::updateNode($wayFromRoot . YY_Builder::WAY_SEPARATOR . $key, $val, $newValue)) {
            YY_Cache::UpdateData($newValue); // TODO: Может можно убрать? Все равно потом это вызывается для всего дерева.
            $currentNode[$key] = null; // Уничтожит старый объект (но все дочерние объекты, которые не изменились, уже отобраны у него)
            $currentNode[$key] = $newValue;
          }
        }
      }
      return false;
    }
  }

  static private function updateCache($node)
  {
    YY_Cache::UpdateData($node);
    $keysArray = array_merge($node->_scalar_keys(), $node->_object_keys());
    foreach ($keysArray as $key) {
      $val = $node[$key];
      if ($val instanceof YY_Ref && $val->_OWNER) {
        self::updateCache($val);
      }
    }
  }

  static private function importNode($wayFromRoot, $fullSourceScriptPath)
  {
    $fullDirPath = pathinfo($fullSourceScriptPath, PATHINFO_DIRNAME);
    $oldWay = self::$CURRENT_IMPORT_WAY;
    $oldPath = self::$CURRENT_IMPORT_PATH;
    self::$CURRENT_IMPORT_WAY = $wayFromRoot;
    self::$CURRENT_IMPORT_PATH = $fullDirPath;
    $res = require($fullSourceScriptPath);
    self::$CURRENT_IMPORT_PATH = $oldPath;
    self::$CURRENT_IMPORT_WAY = $oldWay;
    if (is_array($res)) $res = new YY_Data($res);
    $sourceInfo = YY::$WORLD->SOURCE;
    $sourceInfo->fileTime[$fullSourceScriptPath] = filemtime($fullSourceScriptPath);
    $sourceInfo->fileNode[$fullSourceScriptPath] = new YY_Ref($res, false);
    $sourceInfo->nodeFile[$res] = $fullSourceScriptPath;
    return $res;
  }

  static public function updateWorldPart($wayToPart, $worldTemplateDir)
  {
    self::$newUpdateMode = true;
    self::setupWorldSourceInfo();
    self::$ROOT_IMPORT_WAY = $wayToPart;
    $originalSubtree = self::getExistingChild($wayToPart, YY::$WORLD);
    assert(isset($originalSubtree));
    self::$NODE_OWNERS = [];
    self::$NODE_OWNER_KEYS = [];
    self::wake($originalSubtree); // TODO: А здесь, наверное, можно и не загружать все
    if ($originalSubtree instanceof YY_Ref) {
      $originalSubtree = $originalSubtree->_DAT;
    }
    eval('self::$NEW_SUBTREE = new ' . get_class($originalSubtree) . '();');
    self::$WORLD_REAL_PATH = $worldTemplateDir;
    self::$IMPORT_WAITING = new YY_Data();
    self::$CURRENT_IMPORT_PATH = '';
    self::$ALREADY_IMPORTED = [];
    // Сначала обновим измененные узлы, заменяя все невладеющие ссылки на заглушки, помещенные в $IMPORT_WAITING
    self::updateNode($wayToPart, $originalSubtree, $dummy);
    self::updateCache($originalSubtree);
    // Разрешаем все ссылки-заглушки
    $pendingCount = count(self::$IMPORT_WAITING->_object_keys());
    while ($pendingCount) { // TODO: Вообще-то, должно за один раз все разрешаться, вроде как!
      $r = count(self::$IMPORT_WAITING->_object_keys()) . ' (';
      foreach (self::$IMPORT_WAITING->_object_keys() as $k) {
        $r .= self::$IMPORT_WAITING[$k]->wayFromRoot . ', ';
      }
      $r .= ')';
      YY::Log('import', $r);
      self::continuePendingImport($originalSubtree, $originalSubtree);
      $newPendingCount = count(self::$IMPORT_WAITING->_object_keys());
      if ($newPendingCount === $pendingCount) {
        YY::Log('import, error', "Can not load next nodes: \n" . $r);
        // TODO: Разобраться
        break;
        throw new Exception("Can't load next nodes: \n" . $r);
      }
      $pendingCount = $newPendingCount;
    }
    self::$IMPORT_WAITING = null;
  }

  static private function checkNodeOwner($node)
  {
    $id = $node->_YYID;
    if (array_key_exists($id, self::$idList)) return false;
    self::$idList[$id] = true;
    $keysArray = array_merge($node->_scalar_keys(), $node->_object_keys());
    foreach ($keysArray as $key) {
      $val = $node[$key];
      if ($val instanceof YY_Ref && $val->_OWNER) {
        if (!self::checkNodeOwner($val)) return false;
      }
    }
    return true;
  }

  static public function CheckTreeStructure($root)
  {
    self::$idList = [];
    return self::checkNodeOwner($root);
  }

}
