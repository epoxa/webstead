<?php

/**
 * А теперь обеспечиваем куратора для нового сеанса
 */

if (isset(YY::$ME['CURATOR']) && YY::$ME['CURATOR']) {

  $curator = YY::$ME['CURATOR'];

} else { // Создаем куратора

  $curator = new YY_Main();
  YY::$ME['CURATOR'] = $curator;

}

YY::$ME['CURATOR'] = $curator;
YY::$CURRENT_VIEW['ROBOT'] = $curator;

