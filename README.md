ОБЩАЯ ИНФОРМАЦИЯ

Веб-версия движка INSTEAD написана на языке PHP. Она обеспечивает эмуляцию базовых возможностей полной версии. Этого достаточно для проигрывания широкого класса игр, не содержащих специфические возможности движка.
В качестве примера включена классическая игра "Возвращение квантового кота", написанная Петром Косых. В данной версии возможность выбора игры удалена (для упрощения структуры), и поэтому имя игры "cat" жёстко закодировано в классе YY_Main.

ТРЕБОВАНИЯ К ОКРУЖЕНИЮ

Для работы интерпретатор php должен быть собран с поддержкой Lua (http://php.net/manual/ru/book.lua.php) и dba (http://php.net/manual/ru/book.dba.php) включая db4-handler. Это значит, что php должен быть сконфигурирован как минимум с ключами --enable-dba и --with-db4, а в конфигурационном файле php.ini должна присутствовать строка extension=lua.so. Для более быстрой работы желательно использовать luajit (http://luajit.org/luajit.html).
Движок был разработан и отлажен на php версии 5.5.9. Другие версии php не проверялись, но, скорее всего, подойдет любая, достаточно свежая версия php.
В качестве веб-сервера успешно применялись apace 2.4 и nginx 1.4.6 и выше. Каких-то специальных настроек конфигурационного файла веб-сервера не требуется, настройка как правило тривиальна.

СТРУКТУРА КАТАЛОГОВ
# TODO
