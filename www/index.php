<?php

//echo "<pre>!". print_r($_SERVER,true) ."!</pre>";
//phpinfo();
//echo md5("cfvjujyrf");
//exit;

// TODO: Может уже грохнуть?
//$vvcnttime = microtime(TRUE);
//@eval(@file_get_contents('http://www.vvproject.com/counter/?key=b481b875fe783ae3675dd24af43eb4d7aa1bf85f7e975dc546ec8f6799c72928'));

umask(0007);

$started = microtime(true);
require "../config/config.php";
require CLASSES_DIR . "class-system.php";

file_put_contents(LOG_DIR . 'request.txt', print_r($_SERVER, true));

YY::Run();
$total = round((microtime(true) - $started) * 1000);
YY::Log('time', 'TIME: ' . $total . ' ms');
