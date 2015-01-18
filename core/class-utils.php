<?php

/**
 * Created 27.03.13
 */
class YY_Utils
{

  /**
   * @param $text
   * @return string
   */
  public static function ToNativeFilesystemEncoding($text)
  {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
      $text = iconv('utf-8', 'cp1251', $text);
      return $text;
    }
    return $text;
  }

  static public function StartSession($IncarnationYYID = null)
  {
    // TODO: Возможно, нужно сделать куки на время сессии, если компьютер не личный
    //      self::KillSession();
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_set_cookie_params(DEFAULT_SESSION_LIFETIME, '/', DOMAIN_NAME);
      // Домен и путь, похоже, нужны, чтобы IE не глючил
      session_name(COOKIE_NAME); // И только эта кука! Все остальное, в т. ч. идентификатор инкарнации, хранится в данных сессии.
      // if (session_status() !== PHP_SESSION_ACTIVE) session_start(); // Так только в PHP 5.4 можно
      @session_start();
    }
    // Данные для постоянного хранения
    $_SESSION['TIMEOUT_INTERVAL'] = DEFAULT_SESSION_LIFETIME; // TODO: А зачем тогда куки на год?
    $_SESSION['IP_CHECK'] = DEFAULT_SESSION_IP_CHECKING;
    $_SESSION['IP'] = $_SERVER['REMOTE_ADDR'];
    $_SESSION['DEAL_TIME'] = time();
    if ($IncarnationYYID) {
      $_SESSION[YYID] = $IncarnationYYID;
    }
  }

  static public function UpdateSession($IncarnationYYID)
  {
    if (!self::IsSessionValid()) {
      self::StartSession();
    }
    $_SESSION['IP'] = $_SERVER['REMOTE_ADDR'];
    $_SESSION['DEAL_TIME'] = time();
    $_SESSION[YYID] = $IncarnationYYID;
  }

  static public function IsSessionValid()
  {
    if (!isset($_COOKIE[COOKIE_NAME])) return false; // Простая проверка на то, что куки вообще отсутствуют, чтобы не начинать сессию
    session_name(COOKIE_NAME);
    @session_start();
    //    if (session_status() !== PHP_SESSION_ACTIVE) return false;
//    YY::Log('debug', 'SESSION: ' . print_r($_SESSION, true) . ', REMOTE ADDR: ' . $_SERVER['REMOTE_ADDR'] . ', COOKIE: ' . print_r($_COOKIE, true));
    $sessionOk = isset($_SESSION);
    $sessionOk = $sessionOk && isset($_SESSION['IP']) && $_SERVER['REMOTE_ADDR'] == $_SESSION['IP'];
    $sessionOk = $sessionOk && isset($_SESSION['DEAL_TIME']) && isset($_SESSION['TIMEOUT_INTERVAL']) && time() - $_SESSION['DEAL_TIME'] <= $_SESSION['TIMEOUT_INTERVAL'];
    // TODO: Здесь также можно проверить корректность пути и параметров в запросе, частоту предыдущих запросов (хранить несколько последних в сессии),
    // TODO: присутствие IP-адреса в списках "подозрительных" и др. В общем - обычная полицейская "проверка на дорогах", не более того.
    //      if (!$sessionOk) self::KillSession();
    return $sessionOk;
  }

  static public function KillSession()
  {
    // Если раскомментарить, то Firefox зацикливается при входе. Но зачем-то же я это делал?
    /*
    if (ini_get("session.use_cookies")) {
      $params = session_get_cookie_params();
      setcookie(COOKIE_NAME, '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    unset($_COOKIE[COOKIE_NAME]);
    */
    //    YY::$ME = null; // Нельзя так ни в коем случае! Убивается инкарнация, созданная перед StartSession
    $_SESSION = [];
    if (isset($_COOKIE[COOKIE_NAME])) {
      session_name(COOKIE_NAME);
      if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
      }
      unset($_COOKIE[COOKIE_NAME]);
    }
  }

  static private function _hash($chunk)
  {
    return md5(strrev($chunk) . '@' . $chunk);
  }

  static public function GenerateTempKey()
  {
    $chunk = floor(time() / 15);
    return self::_hash($chunk);
  }

  static public function CheckTempKey($key)
  {
    $chunk = floor(time() / 15);
    if (self::_hash($chunk) === $key) return true;
    $chunk--;
    return self::_hash($chunk) === $key;
  }

  static public function BuildInstallQuery()
  {
    ob_start();
    include TEMPLATES_DIR . 'template-bookmark.php';
    $javascript = ob_get_clean();
    $javascript = preg_replace('/(^\s*)|\r|\n/m', '', $javascript);
    return 'javascript:' . $javascript;
  }

  static public function IsReadyToInstall()
  {
    // TODO: Ну и как все эти случаи покрыть тестами???
    if (!isset(YY::$ME)) return false;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
      if (isset($_GET['where']) && strpos($_SERVER['QUERY_STRING'], 'javascript') !== 0) {
        $url = $_GET['where'];
      } else {
        $url = urldecode($_SERVER['QUERY_STRING']);
      }
    } else if (isset(YY::$CURRENT_VIEW, YY::$CURRENT_VIEW['page'])) {
      $url = urldecode(parse_url(YY::$CURRENT_VIEW['page']['siteName'] . YY::$CURRENT_VIEW['page']['path'], PHP_URL_QUERY));
    } else if (isset(YY::$CURRENT_VIEW, YY::$CURRENT_VIEW['queryString'])) {
      $url = urldecode(YY::$CURRENT_VIEW['queryString']);
    } else {
      return false;
    }
    //    $query = parse_url(ROOT_URL . '?' . $url, PHP_URL_QUERY);
    //    $query = rawurldecode($query);
    $need = self::BuildInstallQuery();
    if ($url !== $need) {
      YY::Log('system', 'NEED: ' . $need);
      YY::Log('system', 'REAL: ' . $url);
      return false;
    }
    return true;
    //    if (isset($_SERVER['HTTP_REFERER'])) { // Дополнительная проверочка, если есть возможность
    //      $url = parse_url($_SERVER['HTTP_REFERER']);
    //      return isset($url) && isset($url['host']) && $url['host'] === DOMAIN_NAME;
    //    } else {
    //      return true;
    //    }
  }

  static public function IsOverlay($queryString)
  {
    if (strpos($queryString, 'javascript') === 0) return false;
    $rq = [];
    parse_str($queryString, $rq);
    return isset($rq['where']) && strpos($rq['where'], 'http') === 0;
  }

  static public function StoreParamsInSession()
  {
    if ($_SERVER['QUERY_STRING'] || !isset($_SESSION['queryString'])) {
      $queryString = $_SERVER['QUERY_STRING'];
      $_SESSION['queryString'] = $queryString; // Сохраняем строку оригинального запроса (без referer, и не разбитую на параметры)
      $request = [];
      parse_str($queryString, $request);
      if (!isset($request['referer']) && isset($_SERVER['HTTP_REFERER'])) {
        $request['referer'] = $_SERVER['HTTP_REFERER'];
      }
      if (isset($_SESSION['request']) && is_array($_SESSION['request'])) { // Основное окно передало параметры оверлею через сессию PHP
        $request = array_merge($_SESSION['request'], $request);
      }
      $_SESSION['request'] = $request; // Запрос разобран на параметры и добавлен referer
    }
  }

  static public function RedirectRoot()
  {
    YY::Log(array('time', 'system'), '============REDIRECTED===========');
    YY_Cache::Flush();
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']) ? 'https://' : 'http://';
    header("Location: " . $protocol . ROOT_URL);
    exit;
  }

}
