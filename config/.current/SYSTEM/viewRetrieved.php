<?php

/**
 * Первый POST-запрос. Возможно прошло много времени. Можно чего-нибудь настроить
 */

assert(isset(YY::$CURRENT_VIEW));

$game = YY::$ME['CURATOR']['game'];
$game->prepareAll();
$game->sendGameHandle();
$game->continueSoundAndTimer();

