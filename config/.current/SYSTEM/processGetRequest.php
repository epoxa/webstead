<?php

if (YY_Utils::IsSessionValid()) {

  YY::TryRestore();

  YY_Utils::StoreParamsInSession();

  $viewId = isset($_SESSION['request'], $_SESSION['request']['view']) ? $_SESSION['request']['view'] : null;

  YY::DrawEngine("template-engine.php");

} else {

  $checkupPassed = isset($_COOKIE[COOKIE_NAME]) && YY_Utils::CheckTempKey($_COOKIE[COOKIE_NAME]);
  if ($checkupPassed) {
    YY::Log('system', 'Requirements checkup is ok');
    YY_Utils::StartSession();
    YY_Utils::StoreParamsInSession();
    YY_Utils::RedirectRoot();
  } else {
    $qs = $_SERVER['QUERY_STRING'];
    $rq = [];
    parse_str($qs, $rq);
    if (isset($_SERVER['HTTP_REFERER']) && !isset($rq['referer'])) {
      if ($qs) $qs .= '&';
      $qs .= 'referer=' . urlencode($_SERVER['HTTP_REFERER']);
    }
    $newLocation = (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'])) ? 'https://' : 'http://') . ROOT_URL;
    if ($qs) $newLocation .= '?' . $qs; // TODO: Поди надо url_encode?
    setcookie(COOKIE_NAME, "", time() - 3600 * 24 * 30, '/', '.' . DOMAIN_NAME);
    YY::Log('system', 'Draw requirements checkup');
    include TEMPLATES_DIR . 'template-checkup.php';
  }

}


