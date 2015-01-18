<?php

class YY_Exporter
{

  static private $PATHS;
  static private $WAYS;

  static public function prepareEmptyDir($dir)
  {
    if (file_exists($dir)) {
      self::clearDir($dir);
    } else {
      mkdir($dir, 0777, true);
    }
  }

  static public function exportSubtree($data, $physicalPath)
  {
    self::prepareEmptyDir($physicalPath);
    self::$PATHS = new YY_Data();
    self::$WAYS = new YY_Data();
    self::buildPathAndWays($data, '', '', '/');
    $path = str_replace('\\', '/', $physicalPath);
    $path = explode('/', $path);
    $subdir = array_pop($path);
    self::exportNode($data, implode('/', $path), $subdir, '/');
    self::$PATHS->_delete();
    self::$PATHS = null;
    self::$WAYS->_delete();
    self::$WAYS = null;
  }

  static private function clearDir($dir)
  {
    $objs = glob($dir . "/*");
    if ($objs) {
      foreach ($objs as $obj) {
        is_dir($obj) ? self::clearDir($obj) : unlink($obj);
      }
    }
  }

  static private function buildPathAndWays($node, $path, $inlinePrefix, $way)
  {
    //    if ($inlinePrefix === '') {
    //      self::$PATHS[$node] = $path;
    //    } else {
    //      // TODO: Неужели нельзя ссылаться на инлайн-объекты???
    //    }
    self::$PATHS[$node] = $path;
    self::$WAYS[$node] = $way;
    foreach (array_merge($node->_scalar_keys(), $node->_object_keys()) as $key) {
      $val = $node[$key];
      if (is_object($val) && $val->_OWNER) {
        $isInline = ($key === 'visual' || isset($val['kind']));
        $newWay = $way . $key . '/';
        if ($isInline) {
          self::buildPathAndWays($val, $path, $inlinePrefix . '.' . $key . '-', $newWay);
        } else {
          self::buildPathAndWays($val, $path . "/" . $inlinePrefix . $key, "", $newWay);
        }
      }
    }
  }

  static private function calculateRelativePath($fromNode, $toNode)
  {
    $fromWay = explode('/', self::$WAYS[$fromNode]);
    array_pop($fromWay);
    $toWay = explode('/', self::$WAYS[$toNode]);
    array_pop($toWay);
    while (count($fromWay) && count($toWay) && $fromWay[0] === $toWay[0]) {
      array_shift($fromWay);
      array_shift($toWay);
    }
    $relPath = str_repeat('../', count($fromWay));
    if (count($toWay)) {
      $relPath .= implode('/', $toWay);
    } else $relPath = substr($relPath, 0, -1);
    return $relPath;
  }

  static private function exportNode($data, $rootPhysicalPath, $subdirName)
  {
    $path = $rootPhysicalPath . '/' . $subdirName;
    if (!file_exists($path)) mkdir($path, 755);
    if ($data instanceof YY_Ref) $data = $data->_DAT;
    $objectText = "<?php\n\n" . "  return " . self::getNodeText($data, $path, '', 2, $data) . ";";
    if (substr($subdirName, 0, 1) === '.') $subdirName = substr($subdirName, 1);
    file_put_contents($path . "/" . $subdirName . ".php", $objectText);
  }

  static private function getNodeText($data, $physicalPath, $inlinePrefix, $indent, $currentImportNode)
  {
    $realData = $data;
    if ($realData instanceof YY_Ref) $realData = $realData->_DAT;
    // Собираем информацию об объекте
    $hasObjectIndex = false;
    $records = [];
    foreach ($data->_all_keys() as $propName) {
      $propValue = $data[$propName];
      if (is_object($propName)) {
        $hasObjectIndex = true;
        if ($propName instanceof YY_Ref) $propName = $propName->_DAT;
        $subdirName = "." . get_class($propName) . "." . $propName->_YYID;
        $propNameView = "self::FS('" . self::calculateRelativePath($currentImportNode, $propName) . "')";
      } else if (substr($propName, 0, 1) !== '_') { // Системные свойства не записываем.
        //      } else if ($propName !== '_path') { // Системные свойства не записываем.
        $propNameView = var_export($propName, true);
        if (is_string($propName)) {
          $subdirName = $propName;
        } else $subdirName = "." . gettype($propName) . "." . $propName;
      } else {
        continue;
      }
      if ($inlinePrefix) {
        $subdirName = '.' . $inlinePrefix . "-" . $subdirName;
      }
      $inline = false;
      if (is_object($propValue)) {
        if (!$propValue->_OWNER) {
          $propValueView = "self::FS('" . self::calculateRelativePath($currentImportNode, $propValue) . "')"; // TODO
        } else {
          if ($propName === 'visual' || $propName === 'translation' || isset($propValue['kind'])) $inline = true;
          // TODO: может еще в каких случаях inline установить?
          if ($inline) {
            $propValueView = self::getNodeText($propValue, $physicalPath, $subdirName, $indent + 2, $currentImportNode);
          } else {
            if ($inlinePrefix) {
              $wayOffset = $inlinePrefix . '/' . $propName;
            } else {
              $wayOffset = $propName;
            }
            self::exportNode($propValue, $physicalPath, $subdirName);
            $propValueView = "self::FS_OWN('" . $subdirName . "', '$wayOffset')";
          }
        }
      } else {
        if (is_string($propValue) && strpos($propValue, "return (require '") === 0) {
          preg_match("#return \(require \'(.*)\'#", $propValue, $a);
          $oldFileName = $a[1];
          $contents = file_get_contents($oldFileName);
          $newFileName = '';
          if ($inlinePrefix) {
            $newFileName .= $inlinePrefix . "-";
          }
          $newFileName .= substr($propNameView, 1, -1) . '.php';
          file_put_contents($physicalPath . '/' . $newFileName, $contents);
          $propValueView = "self::FS_SCRIPT('$newFileName')";
        } else {
          $propValueView = var_export($propValue, true);
        }
      }
      $records[] = array(
        'index' => $propNameView,
        'content' => $propValueView,
        'separate' => is_object($propValue) && $inline,
      );
    }
    // Составляем скрипт, создающий такой объект при выполнении
    $objectClass = get_class($realData);
    if ($hasObjectIndex) {
      $objectText = '';
      foreach ($records as $record) {
        if ($objectText) $objectText .= ",\n";
        if ($record['separate']) $objectText .= "\n";
        $objectText .= str_repeat(" ", $indent + 2) . $record['index'] . ', ' . $record['content'];
        if ($record['separate']) $objectText .= "\n";
      }
      if ($objectText) $objectText .= ",\n";
      $objectText .= str_repeat(" ", $indent + 2) . "'_YYID', '" . $realData->_YYID . "'\n";
      $objectText = 'self::FS_OBJECT(' . ($objectClass === 'YY_Data' ? "" : "'$objectClass',") . "\n"
        . $objectText . "\n" . str_repeat(" ", $indent) . ")";
    } else {
      $objectText = '';
      foreach ($records as $record) {
        if ($record['separate']) $objectText .= "\n";
        $objectText .= str_repeat(" ", $indent + 2) . $record['index'] . ' => ' . $record['content'] . ",\n";
        if ($record['separate']) $objectText .= "\n";
      }
      $objectText .= str_repeat(" ", $indent + 2) . "'_YYID' => '" . $realData->_YYID . "',\n";
      if ($objectClass === 'YY_Data') {
        $objectText = "[\n" . $objectText . str_repeat(" ", $indent) . "]";
      } else {
        $objectText = "new " . $objectClass . "([\n" . $objectText . str_repeat(" ", $indent) . "])";;
      }
    }
    return $objectText;
  }

}