<?php

// Пока делаем все в предположениях, что
// 3) Строки не могут начинаться на символ '['. Он зарезервирован для объектов.

class E_ObjectDestroyed extends Exception {

  public $YYID;

  public function __construct($yyid) {
    parent::__construct("Object destroyed! (" . $yyid . ")");
    $this->YYID = $yyid;
  }

}

class YY_Cache
{

  static private $dataList = [];

  static public function RegisterData(YY_Data $data)
  {
    $YUID = $data->_YYID;
    if (!array_key_exists($YUID, self::$dataList) /* Именно в таком порядке */) {
      self::$dataList[$YUID] = $data;
    }
  }

  static public function UpdateData($dataOrRef)
  {
    $YUID = $dataOrRef->_YYID;
    if ($dataOrRef instanceof YY_Ref) $dataOrRef = $dataOrRef->_DAT;
    // Без проверки что уже содержится, чтобы заменять старое значение при загрузке нового с таким же YYID
    self::$dataList[$YUID] = $dataOrRef;
  }

  static public function Find($YYID)
  {
    if (isset(self::$dataList[$YYID])) {
      return self::$dataList[$YYID];
    } else return null;
  }

  static public function Flush($intermediate = true)
  {
    YY::Log('core', 'flush started');
    foreach (self::$dataList as $data) $data->_delete_if_unasigned();
    $cnt = 0;
    YY_Data::InitializeStorage(true);
    // TODO: По идее в момент сохранения нельзя использовать объекты (а они используются, например, в протоколировании)
    foreach (self::$dataList as $data) {
      if ($data->_flush()) $cnt++;
    }
    YY_Data::FlushTempFiles();
    if ($intermediate) {
      YY_Data::InitializeStorage(false);
    }
    //    self::$dataList = [];
    YY::Log('core', 'flushed ' . $cnt . ' objects');
  }

}

// Ссылки загружают данные автоматически при обращении к любому свойству (в том числе, методу) объекта,
// на который ссылается эта ссылка.

/**
 * @property string _YYID
 * @property YY_Data _DAT
 * @property boolean _EMPTY
 * @property boolean _OWNER
 */
class YY_Ref implements Serializable, Iterator, ArrayAccess, Countable
{

  private $data;
  private $YYID;
  private $_isOwner;

  public function __construct(YY_Data $toData, $lock)
  {
    if ($toData) {
      $this->data = $toData;
      $this->YYID = $toData->_YYID;
      $this->_isOwner = $lock;
    }
  }

  public function __toString()
  {
    return 'R:' . ($this->data ? $this->data : '[' . $this->YYID . ']');
  }

  public function _full_name()
  {
    return 'R:' . $this->_DAT->_full_name();
  }

  public function serialize()
  {
    $str = $this->YYID;
    if ($this->_isOwner) $str = '!' . $str;
    return serialize($str);
  }

  public function unserialize($data)
  {
    $yyid = unserialize($data);
    if (strlen($yyid) > 32) {
      $this->_isOwner = true;
      $yyid = substr($yyid, 1);
    } else {
      $this->_isOwner = false;
    }
    $this->YYID = $yyid;
  }

  public function __get($name)
  {
    if ($name === '_YYID') {
      return $this->YYID;
    } else if ($name === '_DAT') {
      return $this->get_DAT();
    } else if ($name === '_EMPTY') {
      return $this->get_EMPTY();
    } else if ($name === '_OWNER') {
      return $this->_isOwner;
    } else return $this->_DAT->$name; // Используйте динамические (интерпретируемые) языки динамично!
    // Использование свойства, а не индекса массива
    // позволяет использовать обычные (не динамические) публичные свойства через ссылку YY_Ref
    // (например, свойства insertId класса YY_Sql)
  }

  public function __set($name, $value)
  {
    $this->_DAT[$name] = $value; // Используйте динамические (интерпретируемые) языки динамично!
  }

  public function __call($_name, $arg)
  {
    $_result = null;
    $txt = '$_result = $this->_DAT->' . $_name . '(';
    for ($idx = 0; $idx < count($arg); $idx++) {
      if ($idx > 0) $txt .= ',';
      $txt .= '$arg[' . $idx . ']';
    }
    $txt .= ');';
    eval($txt);
    return $_result;
    // Может можно и без "eval", через _DATA->call_user_func_[] ?
  }

  private function get_EMPTY()
  {
    return !isset($this->YYID); // То есть не просто незагружена, а именно - вообще нет даже ссылки // TODO: А откуда бы такие взялись-то? По ходу, надо убрать это
  }

  private function get_DAT()
  {
    if ($this->_EMPTY) throw new Exception("Empty reference!");
    if (!isset($this->data)) {
      $this->data = YY_Data::_load($this->YYID);
    }
    // TODO: Надо отслеживать удаленные объекты и для невладеющих ссылок
    if ($this->_isOwner && ($this->data === null || $this->data->_DELETED)) {
      // Ругаемся только для владеющих ссылок.
      throw new E_ObjectDestroyed($this->YYID);
    }
    return $this->data;
  }

  ///////////////////////
  // Iterator
  ///////////////////////

  public function current()
  {
    return $this->_DAT->current();
  }

  public function key()
  {
    return $this->_DAT->key();
  }

  public function next()
  {
    $this->_DAT->next();
  }

  public function rewind()
  {
    $this->_DAT->rewind();
  }

  public function valid()
  {
    return $this->_DAT->valid();
  }

  ///////////////////////
  // ArrayAccess
  ///////////////////////

  public function offsetExists($offset)
  {
    return $this->_DAT->offsetExists($offset);
  }

  public function offsetGet($offset)
  {
    return $this->_DAT->offsetGet($offset);
  }

  public function offsetSet($offset, $value)
  {
    $this->_DAT->offsetSet($offset, $value);
  }

  public function offsetUnset($offset)
  {
    $this->_DAT->offsetUnset($offset);
  }

  ///////////////////////
  // Countable
  ///////////////////////

  public function count()
  {
    return $this->_DAT->count();
  }

}

function _load_object(&$item, $key)
{
  if (isset($item)) $item = YY_Data::_load($item);
}

// Данные хранят что угодно, но с точки зрения бизнес логики являются пассивными (не имеют методов),
// хотя на системном уровне, совместно с глобальным кэшем обеспечивают прозрачное автосохранение на диск и кэширование.
// Представляют собой ассоциативные массивы. Допускают перебор функцией foreach и обращение как к массиву.
// Может, вообще, унаследоваться от ArrayObject?

// TODO: Сделать событие OnModified (метод, переопределяемый в дочерних классах). 

/**
 * @property string _YYID
 * @property YY_Ref _REF
 * @property boolean _MODIFIED
 * @property boolean _DELETED
 * Iterator реализует только перебор необъектных свойств (индекс - скаляр).
 * Для перебора свойств, индексом в которых является ссылка на объект, можно использовать _object_keys().
 */
class YY_Data implements Serializable, Iterator, ArrayAccess, Countable
{

  const DBA_HANDLER = 'db4';
  /**
   * @var $db resource - локальная dba база открытая на чтение при инициализации класса
   */
  static private $db;
  static private $storageIsWritable = false;

  private $YYID;
  private $modified = false;
  private $ref;
  private $_state;
  protected $properties = array(
    false => [], // Для скалярных индексов
    true => [], // Для объектных индексов
  );

  static public function InitializeStorage($writable = false)
  {
    if (!function_exists('dba_handlers') || !in_array(YY_Data::DBA_HANDLER, dba_handlers())) return;
    if (self::$db && self::$storageIsWritable === $writable) return;
    if (self::$db) {
      dba_close(self::$db);
      self::$db = null;
    }
    $dbPath = DATA_DIR . "DATA.db";
    if (!file_exists($dbPath)) {
      self::$db = @dba_open($dbPath, 'cd', YY_Data::DBA_HANDLER);
      dba_close(self::$db);
      self::$db = null;
    }
    if ($writable) {
      try {
        self::$db = @dba_open($dbPath, 'wdt', YY_Data::DBA_HANDLER);
      } catch(Exception $e) {
        // do nothing
      }
      self::$storageIsWritable = !!self::$db;
      if (!self::$storageIsWritable) {
        self::$db = dba_open($dbPath, 'rd', YY_Data::DBA_HANDLER);
      }
    } else {
      self::$db = dba_open($dbPath, 'rd', YY_Data::DBA_HANDLER);
      self::$storageIsWritable = false;
    }
  }

  static public function FlushTempFiles()
  {
    if (!self::$storageIsWritable) return;
    // Ну раз нам повезло, почистим все временные файлы, сохранив их в локальную БД
    $dir = opendir(DATA_DIR);
    while (($file = readdir($dir)) !== false) {
      if (substr($file, -3) === '.yy') {
        $key = substr($file, 0, -3);
        $data = file_get_contents(DATA_DIR . $file);
        if (unlink(DATA_DIR . $file)) {
          if ($data === '') {
            dba_delete($key, self::$db);
          } else {
            dba_replace($key, $data, self::$db);
          }
        }
      }
    }
    closedir($dir);
  }

  static public function DetachStorage()
  {
    if (self::$db) {
      dba_close(self::$db);
      self::$db = null;
      self::$storageIsWritable = false;
    }
  }

  static public function GenerateNewYYID()
  {
    try {
      do {
        $yyid = md5(uniqid(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '', true));
      } while (
        YY_Cache::Find($yyid) !== null
        || file_exists(self::GetStoredFileName($yyid))
        || self::$db && dba_exists($yyid, self::$db)
      );
    } catch (Exception $e) {
      ob_end_clean();
      echo "KEY: " . $yyid;
    }
    return $yyid;
  }

  static public function GetStoredFileName($YYID)
  {
    return DATA_DIR . $YYID . ".yy";
  }

  static public function GetStatistics()
  {
    $cnt = 0;
    $dataSum = 0;
    $keySum = 0;
    $maxDataSize = null;
    $minDataSize = null;
    if (self::$db && !!$key = dba_firstkey(self::$db)) {
      do {
        $data = dba_fetch($key, self::$db);
        $cnt++;
        $keySum += strlen($key);
        $currDataSize = strlen($data);
        $dataSum += $currDataSize;
        if (!isset($minDataSize) || $currDataSize < $minDataSize) $minDataSize = $currDataSize;
        if (!isset($maxDataSize) || $currDataSize > $maxDataSize) $maxDataSize = $currDataSize;
      } while (!!$key = dba_nextkey(self::$db));
    }

    $dir = opendir(DATA_DIR);
    while (($file = readdir($dir)) !== false) {
      if (substr($file, -3) === '.yy') {
        $key = substr($file, 0, -3);
        $data = file_get_contents(DATA_DIR . $file);
        $cnt++;
        $keySum += strlen($key);
        $currDataSize = strlen($data);
        $dataSum += $currDataSize;
        if (!isset($minDataSize) || $currDataSize < $minDataSize) $minDataSize = $currDataSize;
        if (!isset($maxDataSize) || $currDataSize > $maxDataSize) $maxDataSize = $currDataSize;
      }
    }

    $frmt = function ($sz) {
      if ($sz < 0x400) {
        return sprintf('%d bytes', $sz);
      } else if ($sz < 0x100000) {
        return sprintf('%.1F KB', $sz / 0x400);
      } else if ($sz < 0x40000000) {
        return sprintf('%.1F MB', $sz / 0x100000);
      } else return sprintf('%.1F GB', $sz / 0x40000000);
    };
    if (self::$db) {
      $fileSize = filesize(DATA_DIR . 'DATA.db');
    } else {
      $fileSize = null;
    }
    return [
      'objCount' => $cnt,
      'avgKeySize' => $frmt($keySum / $cnt),
      'avgDataSize' => $frmt($dataSum / $cnt),
      'totalKeySize' => $frmt($keySum),
      'totalDataSize' => $frmt($dataSum),
      'minDataSize' => $minDataSize . ' bytes',
      'maxDataSize' => $maxDataSize . ' bytes',
      'databaseSize' => $fileSize ? $frmt($fileSize) : 'N/A',
      'databaseFill' => $fileSize ? sprintf('%.1F', ($dataSum + $keySum) / $fileSize * 100) . ' %' : 'N/A',
    ];
  }

  public function __construct($init = null)
  {
    $this->modified = true;
    if (isset($init) && isset($init['_YYID'])) {
      $this->YYID = $init['_YYID'];
      unset($init['_YYID']);
    } else {
      $this->YYID = self::GenerateNewYYID();
    }
    YY::Log('core', $this->YYID . ' - ' . get_class($this) . ' created');
    if (isset($init)) {
      if (!is_array($init) && !($init instanceof YY_Ref) && !($init instanceof YY_Data)) throw new Exception("Invalid initialization in data constructor");
      foreach ($init as $name => $value) {
        $this[$name] = $value;
      }
    }
//    YY::Log('core', $this->YYID . ' - initialized: ' . $this->_full_name());
    YY_Cache::RegisterData($this);
  }

  static public function _load($YYID)
  {
    $found_data = YY_Cache::Find($YYID);
    if (isset($found_data)) {
//      YY::Log('core', $found_data . ' - found in cache');
      return $found_data;
    }
    $fName = self::GetStoredFileName($YYID);
    try {
      if (file_exists($fName)) {
        $stored_data = file_get_contents($fName);
      } else if (self::$db && !!($stored_data = dba_fetch($YYID, self::$db))) {
      } else {
        return null;
      };
    } catch (Exception $e) {
      throw $e;
    }
    try {
      $stored_data = @unserialize($stored_data);
    } catch (Exception $e) {
      $stored_data = null;
      YY::Log('error', $YYID . ' - load failed: ' . $e->getMessage());
    }
    if ($stored_data instanceof __PHP_Incomplete_Class) {
      $stored_data = null;
      YY::Log('error', $YYID . ' - load failed: undefined class');
    }
    if ($stored_data) {
      $stored_data->YYID = $YYID;
      YY_Cache::RegisterData($stored_data);
//      YY::Log('core', $stored_data . ' - loaded');
    }
    return $stored_data;
  }

  static public function _fromXml($xml)
  { // Создает новый объект

  }

  public function _toXml()
  {

  }

  public function _short_name() {
    if (!is_object($this)) return print_r($this, true);
    if (isset($this['name'])) {
      return $this['name'];
    } else if (isset($this['_path'])) {
      return $this['_path'];
    } else {
      $name = get_class($this);
      $first = true;
      $cnt = 0;
      foreach ($this as $key => $dummy) {
        if ($cnt++ > 3) {
          $name .= ',...';
          break;
        }
        if ($first) {
          $name .= '(';
          $first = false;
        } else {
          $name .= ',';
        }
        $name .= $key;
      }
      if ($cnt) {
        $name .= ')';
      }
      return $name;
    }

  }

  public function _full_name()
  {
    if (!is_object($this)) return print_r($this, true);
    if (isset($this['name'])) {
      $name = $this['name'];
    } else if (isset($this['_path'])) {
      $name = $this['_path'];
    } else {
      $name = get_class($this);
    }
    $name = $name  . '[' . $this->_YYID . ']';
    $name .= '(';
    $first = true;
    $cnt = 0;
    foreach ($this as $key => $dummy) {
      if ($cnt++ > 30) {
        $name .= ',...';
        break;
      }
      if ($first) {
        $first = false;
      } else {
        $name .= ',';
      }
      $name .= $key;
    }
    $name .= ')';
    return $name;
  }

  public function __toString()
  {
    if (isset($this->properties[false]['_path'])) {
      return '[' . $this->properties[false]['_path'] . ']';
    } else {
      return '[' . $this->YYID . ':' . get_class($this) . ']';
    }
  }

  public function serialize()
  {
    return serialize($this->properties);
  }

  public function unserialize($serialized)
  {
    $this->properties = unserialize($serialized);
    $this->modified = false;
    $this->_state = 'assigned';
  }

  public function _delete()
  {
    if ($this->_state === 'deleted') return;
    $this->modified = true;
    $this->_state = 'deleted';
    YY::Log('core', 'DELETE:' . $this->_full_name());
    $this->_CLEAR();
  }

  public function _delete_if_unasigned()
  {
    if ($this->_state === null) $this->_delete();
  }

  public function _free()
  {
    $this->_state = null;
  }

  public function _scalar_keys()
  {
    return array_keys($this->properties[false]);
  }

  public function _object_keys()
  {
    $objects = array_keys($this->properties[true]);
    array_walk($objects, '_load_object');
    // TODO: Кроме удаления из массива, хорошо бы удалить из индексов объекта. Особенно, где значения - владеющие ссылки
    $objects = array_diff($objects, array(null));
    return $objects;
  }

  public function _all_keys()
  {
    return array_merge($this->_scalar_keys(), $this->_object_keys());
  }

  public static function _isEqual($v1, $v2)
  {
    if (is_object($v1) && is_object($v2) && ($v1->_YYID === $v2->_YYID)) return true;
    return $v1 === $v2;
  }

  public static function _isClass($obj, $className)
  {
    if (!is_object($obj)) return false;
    if ($obj instanceof YY_Ref) $obj = $obj->_DAT;
    if (get_class($obj) === "YY_Shadow") $obj = $obj['_prototype']; // TODO: Получается, что YY_Shadow нужно интегрировать в движок
    return get_class($obj) === $className;
  }

  public function _index_of($value)
  {
    foreach ($this->properties[false] as $key => $val) {
      if (self::_isEqual($val, $value)) return $key;
    }
    foreach ($this->properties[true] as $key => $val) {
      if (self::_isEqual($val, $value)) return self::_load($key)->_REF;
    }
    return null;
  }

  private static function _checkDeleteOwnerRef($old_value, $newValue = null, $reason_obj = null, $reason_prop = null)
  {
    if ($old_value instanceof YY_Ref && $old_value->_OWNER) {
      if ($reason_obj) {
//        YY::Log('core', 'Value of ' . $reason_obj . '->' . $reason_prop . '  (old value: ' . $old_value . ') replaced with (new value: ' . $newValue . ')');
      }
      $old_value->_delete();
    }
  }

  private static function _exists($YYID)
  {
    $found_data = YY_Cache::Find($YYID);
    if (isset($found_data)) {
      return !$found_data->_DELETED;
    } else {
      $fileName = self::GetStoredFileName($YYID);
      return
        file_exists($fileName) && filesize($fileName)
        || self::$db && dba_exists($YYID, self::$db);
    }
  }

  public function __get($name)
  {
    if ($name === '_YYID') {
      return $this->YYID;
    } else if ($name === '_REF') {
      return $this->get_REF();
    } else if ($name === '_MODIFIED') {
      return $this->modified === true;
    } else if ($name === '_DELETED') {
      return $this->_state === 'deleted';
      //    } else if (substr($name, 0, 1) === "_") {
      //      throw new Exception("Can not access system properties");
    } else return $this->offsetGet($name);
  }

  public function __set($name, $value)
  {
    if (substr($name, 0, 1) === "_") {
      throw new Exception("Can not set system properties");
    } else {
      $this->offsetSet($name, $value);
    }
  }

  public function __isset($name)
  {
    if (($name === '_REF') || ($name === '_YYID') || ($name === '_MODIFIED') || ($name === '_DELETED')) return true;
    $is_obj = is_object($name);
    if ($is_obj) $name = $name->_YYID;
    if (!array_key_exists($name, $this->properties[$is_obj])) return false;
    $val = $this->properties[$is_obj][$name];
    if ($val instanceof YY_Ref && !$val->_OWNER) {
      // Разберемся, не удален ли объект
      if (!self::_exists($val->_YYID)) {
        $val = null;
        // Оптимизируем на будущее
        $this->properties[$is_obj][$name] = null;
      }
    }
    return isset($val);
  }

  public function __unset($name)
  {
    YY::Log('core', $this->YYID . ' - unset property $name');
    if (substr($name, 0, 1) === "_") {
      throw new Exception("Can not unset system properties");
    } else {
      $is_obj = is_object($name);
      if ($is_obj) {
        $name = $name->_YYID;
      }
      if (array_key_exists($name, $this->properties[$is_obj])) {
        self::_checkDeleteOwnerRef($this->properties[$is_obj][$name]);
        $this->properties[$is_obj][$name] = null;
      }
    }
  }

  public function __call($name, $arg)
  {
    if (!isset($this[$name])) return null;
    try {
      // TODO: В PHP версии 5.4 можно переделать гораздо элегантнее и безопаснее (в плане разделения переменных) через анонимную функцию.
      if (isset($arg[0])) {
        $_params = $arg[0];
      } else {
        $_params = [];
      }
      $code = $this[$name];
      if ($code) {
        $res = eval($code);
        if ($res === false) {
          throw new Exception('Bad php-code: ' . $code);
        }
      } else {
        $res = null;
      }
      return $res;
    } catch (Exception $e) {
      eval('throw new ' . get_class($e) . '($name . ": " . $e->getMessage());');
    }
  }

  public function _CLEAR()
  {
    if (!count($this->properties[false]) && !count($this->properties[true])) return;
    $this->modified = true;
    foreach (array(false, true) as $is_obj) {
      foreach ($this->properties[$is_obj] as $propValue) {
        self::_checkDeleteOwnerRef($propValue);
      }
    }
    $this->properties = array(
      false => [], // Для скалярных индексов
      true => [], // Для объектных индексов
    );
  }

  public function _DROP($key)
  { // Для владеющего свойства возвращает не ссылку, а сам свободный объект, который можно присвоить новому владельцу
    $is_obj = is_object($key);
    if ($is_obj) {
      $key = $key->_YYID;
    }
    if (array_key_exists($key, $this->properties[$is_obj])) {
      $val = $this->properties[$is_obj][$key];
      if ($val instanceof YY_Ref && $val->_OWNER) {
        //        $val->_isOwner = false; // Чтобы не изменяло состояние на удаленное при присваивании нового значения
        $val = $val->_DAT;
        $this->properties[$is_obj][$key] = new YY_Ref($val, false);
        $this->modified = true;
        $val->_free();
      }
    } else {
      $val = null;
    }
    return $val;
  }

  public function _CLONE()
  {
    $myClass = get_class($this);
//    $clone = new $myClass($this); // Так не получается рекурсивное копирование
    $clone = new $myClass();
    $properties = $this->_all_keys();
    foreach ($properties as $prop) {
      if ($prop === '_path') {
        continue;
      }
      $val = $this[$prop];
      if (is_object($val)) {
        if ($val->_OWNER) {
          $newVal = $val->_CLONE();
        } else {
          $newVal = $val;
        }
      } else {
        $newVal = $val;
      }
      $clone[$prop] = $newVal;
    }
    return $clone;
  }

  /**
   * @param $from YY_Data
   *
   * @throws Exception
   */

  public function _COPY($from)
  {
    if (is_array($from)) {
      foreach ($from as $key => $val) {
        $this[$key] = $val;
      }
    } else if ($from instanceof YY_Ref || $from instanceof YY_Data) {
      $keys = $from->_all_keys();
      // TODO: А итератор сейчас только скаклярные свойства делает. Может объектные не надо копировать?
      foreach ($keys as $key) {
        if (!is_string($key) || substr($key, 0, 1) !== '_') { // Системные свойства не копируем
          $this[$key] = $from[$key];
        }
      }
    } else {
      throw new Exception('Invalid copy source: ' . print_r($from, true));
    }
  }

  private function get_REF()
  {
    if ($this->_state === null) {
      $this->_state = 'assigned';
      return new YY_Ref($this, true);
    } else { // TODO: Стоит ли обрабатывать случай удаленного объекта?
      if (!$this->ref) $this->ref = new YY_Ref($this, false);
      return $this->ref;
    }
  }

  public function _flush()
  {
    umask(0007);
    if ($this->_state === null) return false; // Временные объекты не сохраняем
    if (!($this->modified)) return false;
    $persistFileName = self::GetStoredFileName($this->YYID);
    if ($this->_state === 'deleted') {
      if (self::$storageIsWritable) {
        dba_delete($this->YYID, self::$db);
        if (file_exists($persistFileName)) unlink($persistFileName);
      } else if (self::$db) {
        // Устанавливаем признак того, что объект удален. Когда база будет доступна на запись, он удалится из базы
        if (file_exists($persistFileName)) file_put_contents($persistFileName, '');
      } else {
        if (file_exists($persistFileName)) unlink($persistFileName);
      }
//      YY::Log('core', $this->_full_name() . ' - deleted');
    } else if (self::$storageIsWritable) {
      if (file_exists($persistFileName)) unlink($persistFileName);
      dba_replace($this->YYID, serialize($this), self::$db);
//      YY::Log('core', $this->_full_name() . ' - saved to database');
    } else {
      file_put_contents($persistFileName, serialize($this));
//      YY::Log('core', $this->_full_name() . ' - saved to file');
    }
    $this->modified = false; // Чтобы больше не лезть к файлам после промежуточного _flush (если таковые будут)
    return true;
  }

  ///////////////////////
  // Iterator
  ///////////////////////

  // TODO: Оптимизировать полностью! И разобраться, можно ли включить объектные ключи

  private $iterator_index = null;
  private $object_iterator = null;

  public function current()
  {
    if ($this->valid()) {
      $res = $this->properties[false][$this->iterator_index];
      if ($res instanceof YY_Ref && !$res->_OWNER) {
        // Разберемся, не удален ли объект.
        if (!self::_exists($res->_YYID)) {
          $res = null;
          // Оптимизируем на будущее. Может будет сохранено, а может, оптимизация только на текущий запрос.
          $this->properties[false][$this->iterator_index] = null;
        }
      }
      return $res;
    } else {
      return null;
    }
  }

  public function key()
  {
    if ($this->valid()) {
      return $this->iterator_index;
    } else {
      return null;
    }
  }

  public function next()
  {
    $keys = array_keys($this->properties[false]);
    if (isset($this->iterator_index)) {
      $pos = array_search($this->iterator_index, $keys, true);
      if ($pos === false) {
        $this->iterator_index = null;
      } else {
        if ($pos < count($keys) - 1) {
          $this->iterator_index = $keys[$pos + 1];
          // Временное решение, чтобы пропускать системные свойства
          if (is_string($this->iterator_index) && substr($this->iterator_index, 0, 1) === '_') {
            $this->next();
          }
        } else $this->iterator_index = null;
      }
    } else {
      $this->iterator_index = null;
    }
  }

  public function rewind()
  {
    $keys = array_keys($this->properties[false]);
    if (count($keys)) {
      $this->iterator_index = $keys[0];
      // Временное решение, чтобы пропускать системные свойства
      if (is_string($this->iterator_index) && substr($this->iterator_index, 0, 1) === '_') {
        $this->next();
      }
    } else $this->iterator_index = null;
  }

  public function valid()
  {
    return isset($this->iterator_index) && array_key_exists($this->iterator_index, $this->properties[false]);
  }

  ///////////////////////
  // ArrayAccess
  ///////////////////////

  /**
   * Вызывается при проверке isset(), и поэтому раньше было так:
   * return isset($this->properties[$is_obj][$offset]);
   * А теперь надо иметь ввиду, что isset(YY_Data[key]) работает не так как isset(array[key]),
   * а определяет, есть ли ключ key в этом объекте
   *
   * @param $offset
   *
   * @return bool
   */

  public function offsetExists($offset)
  {

    $is_obj = is_object($offset);
    if ($is_obj) {
      $offset = $offset->_YYID;
    }

    return array_key_exists($offset, $this->properties[$is_obj]);

  }

  public function offsetGet($offset)
  {

    $is_obj = is_object($offset);
    if ($is_obj) {
      $offset = $offset->_YYID;
      // TODO: Надо определять, не удален ли сам этот объект, использующйся в качестве индекса
    }

    if (array_key_exists($offset, $this->properties[$is_obj])) {
      $res = $this->properties[$is_obj][$offset];
      if ($res instanceof YY_Ref && !$res->_OWNER) {
        // Разберемся, не удален ли объект.
        if (!self::_exists($res->_YYID)) {
          $res = null;
          // Оптимизируем на будущее. Может будет сохранено, а может, оптимизация только на текущий запрос.
          $this->properties[$is_obj][$offset] = null;
        }
      }
      return $res;
    } else {
      $msg = "Property '$offset' absent in " . $this . '. Call stack:';
      $stack = debug_backtrace();
      foreach($stack as $ctx) {
        if (isset($ctx['file'])) {
          $msg .= "\n$ctx[file]($ctx[line])";
        }
      }
      throw new Exception($msg);
    }

  }

  public function offsetSet($offset, $value)
  {

    // Разбираемся с присваиваемым значением

    if (is_array($value)) { // Массивы оборачиваем в объекты TODO: может сделать аналогичную сериализацию для сторонних объектов, которые не YY_Data?
      $value = new YY_Data($value);
    }
    if ($value instanceof YY_Data) { // Храним всегда только ссылки на объекты
      $value = $value->_REF;
      // TODO: В случае получения здесь владеющей ссылки ($value->_OWNER === true)
      // TODO: нужно либо убедиться, что нет рекурсии (методом прохода по вводимому новому свойству parent),
      // TODO: либо убедиться, что нет рекурсии (методом полного рекурсивного обхода всех владеющих дочерних ссылок),
      // TODO: либо не делать ничего, но ввести периодический процесс удаления образующихся изолированных циклов.
      // TODO: А лучше всего - показать, что цикл не может возникнуть .
      /*
       * $first = new YY_Data();
       * $second = new YY_Data();
       * $first->prop = $second;
       * $second->prop = $first;
       * TODO: Как избавиться от такой рекурсии?
       */
    } else if ($value instanceof YY_Ref && $value->_OWNER) { // Причем только копии владеющей ссылки
      $value = new YY_Ref($value->_DAT, false);
    } else if (is_object($value) && !($value instanceof YY_Ref)) {
      throw new Exception('Invalid property value: ' . get_class($value));
    }

    // Разбираемся с типом индекса

    $is_obj = is_object($offset);
    if ($is_obj) {
      $offset = $offset->_YYID;
    }

    // Если не добавление к массиву, то учитываем старое значение свойства.
    // Во-первых, если оно уже равно присваиваемому, то незачем модифицировать объект,
    // и, во-вторых, если оно - владеющая ссылка, то надо прибить старый объект.

    if ($offset !== null) {
      $propertyAlreadyExists = array_key_exists($offset, $this->properties[$is_obj]);
      if ($propertyAlreadyExists) {
        $old_value = $this->properties[$is_obj][$offset];
        //          if ($old_value instanceof YY_Ref && !$old_value->_OWNER) { // Нахрена это было в __get, совершенно непонятно
        //            $old_value = $this[$name];
        //          }
        if (self::_isEqual($old_value, $value)) {
          return;
        }
        self::_checkDeleteOwnerRef($old_value, $this, $offset);
      }
    }

    $this->modified = true;
    if ($offset === null) {
      $this->properties[false][] = $value;
    } else {
      $this->properties[$is_obj][$offset] = $value;
    }

  }

  public function offsetUnset($offset)
  {

    $is_obj = is_object($offset);
    if ($is_obj) {
      $offset = $offset->_YYID;
    }

    if (!array_key_exists($offset, $this->properties[$is_obj])) return;

    $old_value = $this->properties[$is_obj][$offset];
    self::_checkDeleteOwnerRef($old_value, $this, $offset);

    unset($this->properties[$is_obj][$offset]);

    $this->modified = true;
  }

  ///////////////////////
  // Countable
  ///////////////////////

  public function count()
  {
    //    return count($this->properties[false]) + count($this->properties[true]);
    return count($this->properties[false]);
  }

}

