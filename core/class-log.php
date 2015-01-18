<?php

function _on_shutdown()
{
    YY_Log::finalize();
}

register_shutdown_function('_on_shutdown');

class YY_Log
{

    static private $map = array(
//    'time' => 'profile',
//    'core' => 'debug',
//    'import' => 'import',
//    'system' => 'debug, screen, anonymous',
        'stead' => 'debug, screen',
        'debug' => 'debug, screen, anonymous',
        'error' => 'debug, error, gatekeeper, anonymous, screen',
        'sql' => 'debug',
        'gatekeeper' => 'debug, gatekeeper',
        'game' => 'debug, gatekeeper',
    );

    static private $logDir = LOG_DIR;
    static private $screenDebugText = '';
    static private $started = null;
    static private $buffers = [];
    static private $profiles = [];
    static private $currentProfile = null;
    static private $currentProfileStarted = null;

//  static private $lastHighLevel

    static public function Log($kind, $message)
    {
        $now = microtime(true);
        if (self::$started === null) {
            self::$started = $now;
        }
        if ($kind === null) {
            $kind = ['app'];
        } else if (!is_array($kind)) {
            $kind = explode(',', $kind);
        }
        $logs = [];
        foreach ($kind as $k) {
            if (isset(self::$map[trim($k)])) {
                $log = self::$map[trim($k)];
                if ($log && !is_array($log)) $log = explode(',', $log);
                if ($log) {
                    foreach ($log as $f) {
                        $logs[trim($f)] = null;
                    }
                }
            }
        }
        foreach ($logs as $log => $dummy) {
            $conditionMethod = $log . 'Check';
            $needWrite = method_exists(__CLASS__, $conditionMethod) && self::$conditionMethod();
            if (!$needWrite) continue;
            $writeMethod = $log . 'Write';
            method_exists(__CLASS__, $writeMethod) && self::$writeMethod($message);
        }
    }

    static public function GetScreenOutput()
    {
        $res = self::$screenDebugText;
        self::$screenDebugText = '';
        return $res;
    }

    static public function SetProfile($name)
    {
        if ($name === null) {
            assert(self::$currentProfile !== null);
            self::$profiles[self::$currentProfile] += microtime(true) - self::$currentProfileStarted;
            self::$currentProfile = null;
        } else {
            assert(self::$currentProfile === null);
            self::$currentProfile = $name;
            if (!isset(self::$profiles[self::$currentProfile])) {
                self::$profiles[self::$currentProfile] = 0.0;
            }
            self::$currentProfileStarted = microtime(true);
        }
    }

    static protected function screenCheck()
    {
        return DEBUG_MODE && DEBUG_ALLOWED_IP;
    }

    static protected function screenWrite($message)
    {
        self::$screenDebugText .= $message . PHP_EOL;
    }

    static protected function errorCheck()
    {
        return true; // Могут использоваться при обратной связи пользователей (служба поддержки)
    }

    static protected function errorWrite($message)
    {
        self::directWrite('error', $message);
    }

    static protected function gatekeeperCheck()
    {
        return true; // Могут использоваться при обратной связи пользователей (служба поддержки)
    }

    static protected function gatekeeperWrite($message)
    {
        self::directWrite('', $message);
    }

    static protected function debugCheck()
    {
//    return true; // Пока протоколируем все
        return isset(YY::$CURRENT_VIEW);
    }

    static protected function debugWrite($message)
    {
        self::directWrite('debug', $message);
    }

    static protected function anonymousCheck()
    {
        return !isset(YY::$CURRENT_VIEW);
    }

    static protected function anonymousWrite($message)
    {
        self::directWrite('debug', $message);
    }

    static protected function directWrite($log, $message)
    {
        static $logFileNames = [];
        if (isset($logFileNames[$log])) {
            $logFileName = $logFileNames[$log];
        } else {
            $dirName = isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
            if (isset(YY::$ME, YY::$ME['curatorName'], YY::$ME['id'])) {
                $dirName = YY::$ME['curatorName'] . ' (' . YY::$ME['id'] . ') ' . $dirName;
            }
            $dirName = LOG_DIR . 'users/' . $dirName;
            if ($log) $dirName .= '/' . $log;
            $nativeFsDirName = YY_Utils::ToNativeFilesystemEncoding($dirName);
            if (!file_exists($nativeFsDirName)) {
                umask(0007);
                mkdir($nativeFsDirName, 0770, true);
//                chmod($nativeFsDirName, 0770); // Чтобы не менять umask перед созданием папки
            }
            $logFileName = date('Y-m-d H.i.s', isset(YY::$CURRENT_VIEW, YY::$CURRENT_VIEW['created']) ? YY::$CURRENT_VIEW['created'] : time());
            if (isset(YY::$CURRENT_VIEW, YY::$CURRENT_VIEW['page'])) {
                $page = YY::$CURRENT_VIEW['page'];
                $logFileName .= ' (' . $page['siteName'] . ') ' . $page['title'];
            }
            $logFileName = preg_replace('/[^\p{L}\d\s\-\_\!\.\()]/u', '', $logFileName);
            $logFileName = $nativeFsDirName . '/' . YY_Utils::toNativeFilesystemEncoding(mb_substr($logFileName, 0, 150));
            $logFileNames[$log] = $logFileName;
        }
        file_put_contents(LOG_DIR . 'last-debug-name.txt', $logFileName);
        $f = fopen($logFileName, 'a');
        if ($f) {
            fwrite($f, $message . "\n");
            fclose($f);
        } else {
            throw new Exception('Can not open log file (' . $logFileName . ') to write: ' . $message);
        }
    }

    static private function bufferedWrite($log, $message)
    {
        if (isset(self::$buffers[$log])) {
            $was = self::$buffers[$log];
        } else $was = "";
        self::$buffers[$log] = $was . $message . "\n";
    }

    static public function GetStatistics()
    {
        $kb = round(memory_get_peak_usage(true) / 1024);
        $r = 'max memory: ' . $kb . ' kb';
        if (self::$started !== null) {
            $microseconds = ceil((microtime(true) - self::$started) * 1000);
            $r .= "\n" . 'total time: ' . $microseconds . ' ms';
            foreach (self::$profiles as $profileName => $profileTime) {
                $microseconds = ceil($profileTime * 1000);
                $r .= "\n" . $profileName . ': ' . $microseconds . ' ms';
            }
        }
        return $r;
    }

    static public function finalize()
    {
        // Оптимизация на случай отсутствия протоколов в этом запросе
        if (self::$started === null) return;
        // Сбрасываем на диск буферизованые логи
        $started = date(' - Y-m-d H.i.s', floor(self::$started));
        foreach (self::$buffers as $file => $buffer) {
            if (substr($file, 0, 1) === '^') {
                $file = substr($file, 1) . $started;
                $partialFileName = self::$logDir . '/' . $file;
                $f = @fopen($partialFileName . ".txt", 'a');
                $i = 1;
                while ($f === false) {
                    $i++;
                    $f = @fopen($partialFileName . " (" . $i . ").txt", 'a');
                };
            } else {
                $fullFileName = self::$logDir . '/' . $file . '.txt';
                $f = @fopen($fullFileName, 'a');
                while ($f === false) {
                    usleep(rand(10, 100));
                    $f = @fopen($fullFileName, 'a');
                };
            }
            fwrite($f, trim($buffer) . "\n");
            fclose($f);
        }
        // Окончательный отладочный вывод запроса и статистики
        if (self::debugCheck()) {
            if (!isset(YY::$ME) && function_exists("getallheaders")) {
                self::directWrite('debug', 'HTTP ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI']);
                self::directWrite('debug', print_r(getallheaders(), true));
            }
            self::directWrite('debug', '--------------------');
            self::directWrite('debug', self::GetStatistics());
            self::directWrite('debug', '--------------------');
        }
    }

}