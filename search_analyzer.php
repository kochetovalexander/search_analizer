<?php
//
//ПредАнализатор
//
//Большие словосочетания не берем, максимум 3 слова
//    Ссылки не принимаются (!) Нововведение в том что будет улучшен поиск ссылок по названию доменов исключаются все поиски содержащие .com .ru .net .biz и т.д. А так же исключаться ссылки с опечатками (напр. htp://)
//    Старые статьи в расчет не берутся
//    Не берем запросы короче 3 символов(!)
//    Не берем запросы длиннее 255 символов(! бывают оригиналы)
//    Не анализируем запросы со спецсимволами(вырезаем спецсимволы) - !"№;%:?*'{}[]%()_@#$^&<>/|\. В логах из спецсимволов каша. Берем запросы только с тире.(1)
//    Не анализируем запросы только из цифр и спецсимволов(! например даты и всякий мусор типа 11%)
//    Заменяем повторяющиеся пробелы в запросах одним пробелом(!). Например "Владимир Путин"
//
//Поисковик
//
//    Поиск в тексте статьи по чистому запросу(поиск по оригинальному тексту запроса без изменений)
//    Поиск в статье по тексту без спецсимволов с заменой на пробелы и без
//    Ищем по нормализованному тексту запроса(существительное именительный падеж)
//    В том виде в котором нашли запоминаем для постанализатора
//
//ПостАнализатор
//
//    Убираем из запроса все лишние символы
//    Преобразуем очищенную форму в нормализованную сохраняя все заглавные буквы и аббревиатуры
//    Поиск по Именам и Компаниям Ведомостей
//    Сохранение в быстрый поиск или чистой формы запроса или найденное имя/название
//    Сохранение в саджесты чистой формы запроса + нормализаванной формы(по нормализованной форме при выдаче будем группировать выражения)
//    В саджесты сохраняются запросы только в нижнем регистре
//
//Сохранение
//
//    Сохранение Быстрого поиска. Проверка на наличие в быстром поиске уже существующих форм выражение(при необходимости заменяем)
//    Сохранение Саджеста. Уже существующим саджестам просто повышается рейтинг запроса и увеличивается время последнего запроса(важно при выдаче)

class Analizer
{
    private $id_news, $debug_mode, $searchQuery, $searchDB, $analize_period;
    private $article;
    private $document_root;
    private $mysql;
    private $logtext, $logfile;
    private $stemmer_obj;
    private $full_url;


    function __construct($news_text, $debug_mode, $searchDB, $analize_period, $document_root, $memcache, $url)
    {
        require_once "stemmer.php";
        $this->id_news = $id_news;
        $this->debug_mode = $debug_mode;
        $this->searchDB = $searchDB;
        $this->analize_period = $analize_period;
        $this->document_root = $document_root;
        $this->memcache = $memcache;
        $this->logtext = "";
        $this->logfile = $document_root . "10/htdocs/search/logsuggest.log";
        $this->mysql = new Mysql('db_search');
        $this->stemmer_obj = new Stemmer();
        $this->full_url = $url;
        //error_reporting(E_ALL);
        //ini_set('display_errors', 1);
    }

    function Analize($searchQuery)
    {
        include_once('/usr/www/inc/date_funcs.inc');

        // Выводим форму упрощения поиска
        if ($this->debug_mode) {
            $this->analize_period = 1000;
            ?>
            <form method="get" action="/search/">
                <input type="text" size="70" name="s" value="<?= htmlspecialchars($_REQUEST['s']) ?>"> Искомое выражение
                <br>
                <input type="text" size="70" name="u" value="<?= $_REQUEST['u'] ?>"> URL статьи(вместе с http://)<br>
                <input type="hidden" name="m" value="u">
                <input type="hidden" name="d" value="1">
                <input type="submit" value="Отправить">
            </form><br><br>
        <?php
        }

        try {
            if ($this->debug_mode) {
                $this->message("Запрос:" . $searchQuery);
                $this->message("Новость:" . $this->id_news);
            }
            $originalsearchQuery = $searchQuery;
            // Запускаем преданализатор
            $searchQuery = $this->PreAnalizer($searchQuery);
            // Запускаем поисковый анализатор
            $searchQuery = $this->SearchAnalizer($searchQuery);
            // Запускаем постанализатор
            $searchQuery = $this->PostAnalizer($searchQuery, $originalsearchQuery);
        } catch (Exception $ex) {
            if ($this->debug_mode) echo $ex->getMessage();
        }
//        try {
//            // логируем все происходящее
//            $this->logSave();
//        } catch (Exception $ex) {
//            // пропускаем ошибки логера
//        }
    }

    function PreAnalizer($searchQuery)
    {
        $this->message("Запуск проверки пред анализатора поискового запроса");

        // Старые статьи в расчет не берутся.
        $news = $this->mysql->get_arrayref("select news_body, news_date from " . $this->searchDB . ".tbl_news where news_id = '" . $this->id_news . "' limit 1");
        if (count($news) == 0) {
            $this->error("Статья не найдена в базу данных поиска. ID новости:", $this->id_news);
        }
        $this->article = $news[0]['news_body'];
        if (inc_date(-$this->analize_period) . ' 00:00:00' > $news[0]['news_date']) {
            $this->error("Статья слишком старая. Допустимый период анализа статьи:", $this->analize_period . " дней");
        }

        // Не берем запросы короче 3 символов
        if (strlen($searchQuery) < 3) {
            $this->error("Запрос слишком короткий. Не обрабатываем запросы короче 3 символов");
        }

        // Не берем запросы длиннее 255 символов
        if (strlen($searchQuery) > 255) {
            $this->error("Запрос слишком длинный. Не обрабатываем запросы длинее 255 символов");
        }

        // Заменяем все повторяющиеся пробельные символы на одиночные пробелы
        $searchQuery = preg_replace('/[\s]+/', ' ', $searchQuery);

        // Большие словосочетания не берем максимум 3 слова
        $wordsInQuery = preg_replace('/[\+\=\!\"\№\;\%\:\?\*\'\»\«\{\}\[\]\%\(\)\_\@\#\$\^\&\<\>\/\|\.0-9]/', ' ', $searchQuery);
        $wordsInQuery = str_replace('\\', '', trim($wordsInQuery));
        $wordsInQuery = preg_replace('/[\s]+/', ' ', $wordsInQuery);
        $wordsInQuery = explode(' ', $wordsInQuery);
        if (count($wordsInQuery) > 3) {
            $this->error("Не обрабатываем запросы больше 3 слов");
        }

        // Запросы содержащие URL не принимаются
        if (preg_match('/(\.[a-z1-9а-я]+|http\:\/\/|https\:\/\/|htp\:\/\/|htps\:\/\/)/i', $searchQuery)) {
            $this->error("Не обрабатываем запросы содержащие URL");
        }

        // Пустые и состоящие из одних символов и чисел выражения не берем
        $clearQuery = preg_replace('/[\!\"\№\;\%\:\?\*\'\»\«\{\}\[\]\%\(\)\_\@\#\$\^\&\<\>\/\|\.0-9]/', '', $searchQuery);
        $clearQuery = str_replace('\\', '', $clearQuery);
        if (strlen(trim($clearQuery)) < 1) {
            $this->error("Пустые, неинформативные и состоящие из одних символов и цифр выражения не берем");
        }

        // Берем только выражения с нормализованной формой первого(или единственного) слова
        if (count($wordsInQuery) > 1) {
            if (!$this->is_normalized($wordsInQuery[0])) $this->error("Не нормализованные словосочетания и слова не берем");
        } else {
            if (!$this->is_normalized($clearQuery)) $this->error("Не нормализованные словосочетания и слова не берем");
        }

        $this->message("Ошибок нет. Выражение допущено к поиску в следующей форме:", $searchQuery);
        return $searchQuery;
    }

    function SearchAnalizer($searchQuery)
    {
        $this->message("Запуск поиска вхождений поискового выражения в текст статьи");

        // поиск точных вхождений в текст статьи
        $searchQueryUTF = iconv('windows-1251', 'UTF-8', $searchQuery);
        $articleUTF = iconv('windows-1251', 'UTF-8', $this->article);
        $templateOfInsert = "/(^|\s|)(" . preg_quote($searchQueryUTF) . iconv("windows-1251", "UTF-8", ")(\'|\"|\,|\;|\.|\s|\n|\r|\»|$)/isu");
        $r = preg_match($templateOfInsert, $articleUTF, $matches);
        if (!$r) {
            $this->message("Оригинальная форма '" . htmlspecialchars($searchQuery) . "'", "не найдено");
            $normalizedQuery = preg_replace('/[\+\=\!\"\№\;\%\:\?\*\'\»\«\{\}\[\]\%\(\)\_\@\#\$\^\&\<\>\/\|\.]/', ' ', $searchQuery);
            $normalizedQuery = str_replace('\\', '', trim($normalizedQuery));
            $normalizedQuery = preg_replace('/[\s]+/', ' ', $normalizedQuery);
            $normalizedQueryUTF = iconv("windows-1251", "UTF-8", $normalizedQuery);
            $r = preg_match("/(^|\s|)(" . preg_quote($normalizedQueryUTF) . iconv("windows-1251", "UTF-8", ")(\'|\"|\,|\;|\.|\s|\n|\r|\»|$)/isu"), $articleUTF, $matches);
            if (!$r) {
                $this->message("Чистая форма(без спецсимволов) '" . htmlspecialchars($normalizedQuery) . "'", "не найдено");
                // Убираем поиск по словоформе, словоформы слишком несовершенны что бы по ним искать
//                $wordsBeforeInQuery = count(explode(' ', $normalizedQuery)); // Количество слов до стемера
//                if ($wordsBeforeInQuery > 1) $this->error("Словосочетания для стеммовского поиска не берем");
//                $lemmatizedWords = $this->lemmatized_word($normalizedQuery);
//                $normalizedQuery = $lemmatizedWords;
//                $normalizedQuery = str_replace('?', '', $normalizedQuery);
//                $normalizedQuery = str_replace('!', '', $normalizedQuery);
//                $normalizedQuery = preg_replace('/[\s]+/', ' ', $normalizedQuery);
//                $wordsAfterInQuery = count(explode(' ', $normalizedQuery)); // Количество слов после стемера
//                if (trim($normalizedQuery) == "" || $wordsBeforeInQuery != $wordsAfterInQuery) {
//                    $this->error("Не удалось грамотно нормализовать запрос");
//                }
//                $normalizedQueryUTF = iconv("windows-1251", "UTF-8", $normalizedQuery);
//                $r = preg_match("/(^|\s|)(" . $normalizedQueryUTF . iconv("windows-1251", "UTF-8", ")(\'|\"|\,|\;|\.|\s|\n|\r|\»|$)/isu"), $articleUTF, $matches);
//                if (!$r) {
//                    $this->message("После-стреммер форма(именительный падеж, единственное число) '" . htmlspecialchars($normalizedQuery) . "'", "не найдено");
//                } else {
//                    $searchQuery = iconv("UTF-8", "windows-1251", $matches[2]);
//                }
            } else {
                $searchQuery = iconv("UTF-8", "windows-1251", $matches[2]);
            }
        } else {
            $searchQuery = iconv("UTF-8", "windows-1251", $matches[2]);
        }

        /* Поиск не удался выходим. */
        if (!$r) {
            $this->error("Не удалось найти в тексте поисковый запрос");
        } else {
            $this->message("Поиск удался. Выражение допущено к анализу в следующей форме:", $searchQuery);
        }

        return $searchQuery;
    }

    function PostAnalizer($searchQuery, $originalsearchQuery)
    {
        $this->message("Запуск пост анализатора поискового выражения");

        // Убираем все спецсимволы
        $cleanQuery = preg_replace('/[\+\=\!\"\№\;\%\:\?\*\'\»\«\{\}\[\]\%\(\)\_\@\#\$\^\&\<\>\/\|\.]/', ' ', $searchQuery);
        $cleanQuery = str_replace('\\', '', trim($cleanQuery));
        $cleanQuery = preg_replace('/[\s]+/', ' ', $cleanQuery);


        // Получаем нормализованную форму
        $wordsBeforeInQuery = count(explode(' ', $cleanQuery)); // Количество слов до стемера
        $normalizedQueryUTF = mb_convert_encoding($cleanQuery, 'UTF-8', 'windows-1251');
        $normalizedQueryUTF = $this->stemmer($normalizedQueryUTF);
        $normalizedQuery = mb_convert_encoding($normalizedQueryUTF, 'windows-1251', 'UTF-8');
        $normalizedQuery = str_replace('?', '', $normalizedQuery);
        $normalizedQuery = str_replace('!', '', $normalizedQuery);
        $normalizedQuery = preg_replace('/[\s]+/', ' ', $normalizedQuery);
        $wordsAfterInQuery = count(explode(' ', $normalizedQuery)); // Количество слов после стемера
        if ($wordsBeforeInQuery != $wordsAfterInQuery) {
            $this->error("Не удалось грамотно нормализовать запрос в пост анализаторе");
        }

        $normalizedArray = explode(" ", $normalizedQuery);
        $cleanArray = explode(" ", $cleanQuery);

        /* Поиск фамилий, аббревиатур и регулярных слов. Замена заглавных букв в нормализованной форме*/
        $result_words = "";
        foreach ($cleanArray as $key => $one_word) {
            $first_letter = mb_substr($one_word, 0, 1, "windows-1251");
            $second_letter = mb_substr($one_word, 1, 1, "windows-1251");
            $is_abbreviature = false;
            $is_name = false;
            if (preg_match('/\\p{Lu}|\\p{Lt}/u', iconv("windows-1251", "UTF-8", $first_letter)) > 0
                && !
                preg_match('/\\p{Lu}|\\p{Lt}/u', iconv("windows-1251", "UTF-8", $second_letter)) > 0
            ) {
                $is_name = true;
            } elseif (preg_match('/\\p{Lu}|\\p{Lt}/u', iconv("windows-1251", "UTF-8", $first_letter)) > 0
                &&
                preg_match('/\\p{Lu}|\\p{Lt}/u', iconv("windows-1251", "UTF-8", $second_letter)) > 0
            ) {
                $is_abbreviature = true;
            }
            if ($is_abbreviature) {
                $normalized_word = $normalizedArray[$key];
                $elt = mb_convert_case($normalized_word, MB_CASE_UPPER, "windows-1251");
                $result_words[] = $elt;
            } elseif ($is_name) {
                $normalized_word = $normalizedArray[$key];
                $elt = mb_convert_case($normalized_word, MB_CASE_TITLE, "windows-1251");
                $result_words[] = $elt;
            } else {
                $normalized_word = $normalizedArray[$key];
                $result_words[] = $normalized_word;
            }
        }
        $normalizedQuery = implode(" ", $result_words);
        $this->message("Чистим и стеммим запрос для сохранения. Преобразованный вариант запроса:", $normalizedQuery);


        /* В быстрый поиск добавляются имена и названия организаций в той форме в которой они есть в статье(если нет добавляются в оригинальной форме). */
        $descriptorspec = array(
            0 => array("pipe", "r"), // stdin is a pipe that the child will read from
            1 => array("pipe", "w"), // stdout is a pipe that the child will write to
            2 => array("pipe", "/dev/null", "w") // stderr is a file to write to
        );
        $cwd = $this->document_root . 'vedomosti/scripts/newsline/crosslinks.pl';
        $env = null;
        $process = proc_open("$cwd", $descriptorspec, $pipes, $cwd, $env);
        if (is_resource($process)) {
            fwrite($pipes[0], $this->article);
            fclose($pipes[0]);

            $article_text_parsed = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($process);
        } else {
            return;
        }
        $findedNames = "";
        $findedCrosslinks = preg_match_all("/%%%([А-Яа-яA-Za-z\s]+)%%%/im", $article_text_parsed, $crossWords);
        if ($findedCrosslinks) {
            foreach ($crossWords[1] as $crossWord) {
                $reg = "/" . iconv("windows-1251", "UTF-8", $normalizedQuery) . "/isu";
                if (preg_match($reg, iconv("windows-1251", "UTF-8", $crossWord))) {
                    $findedNames = $crossWord;
                    break;
                }
            }
        }
        // Если выражение не найдено в именах и названиях статьи то берем оригинальный запрос
        $queryForRelativesLink = "";
        if (!isset($findedNames) || empty($findedNames)) {
            $queryForRelativesLink = $cleanQuery;
            $this->message("В именах и названиях выражение не найдено");
        } else {
            $queryForRelativesLink = $findedNames;
            $this->message("В именах и названиях найдено искомое выражение");
        }

        $queryForSuggest = mb_convert_case($cleanQuery, MB_CASE_LOWER, "windows-1251");
        $normalizedQuery = mb_convert_case($normalizedQuery, MB_CASE_LOWER, "windows-1251");
        /* Финальный этап сохранения в садджесты и быстрый поиск. */
        $this->message("В быстрый поиск пробуем сохранить:", $queryForRelativesLink);
        $this->save_to_related($queryForRelativesLink);
        $this->message("В садджесты пробуем сохранить(безсуфиксная форма - найденная форма):", $normalizedQuery . " - " . $queryForSuggest);
        $this->save_to_suggest($normalizedQuery, $queryForSuggest, $originalsearchQuery);
    }

    // Сохранение в "быстрый поиск"
    function save_to_related($queryForRelativesLink)
    {
        if (!isset($queryForRelativesLink) || empty($queryForRelativesLink)) {
            $this->message("Пустые выражения игнорируем");
            return;
        }
        // Исключаем все спецсимволы
        $relativesWords = explode(" ", $queryForRelativesLink);

        // Больше пяти записей в быстрый поиск не добавляем
        $related = $this->mysql->get_arrayref("select value_varchar from " . $this->searchDB . ".tbl_news_attrs where news_id='" . $this->id_news . "' and attr_nick = 'related'");
        if (count($related) >= 5) {
            $this->message("Статья уже имеет 5 записей в быстром поиске. Ничего не добавляем");
            return false;
        }

        // Проверка может быть наше поисковое выражение уже является частью существующего тогда выходим
        $query = "select * from " . $this->searchDB . ".tbl_news_attrs where ((lower(value_varchar) like lower('%$queryForRelativesLink %') or lower(value_varchar) like lower('% $queryForRelativesLink%') or lower(value_varchar) like lower('% $queryForRelativesLink %')) or (lower(value_varchar) like lower('%$queryForRelativesLink%') and CHAR_LENGTH('$queryForRelativesLink') = CHAR_LENGTH(value_varchar))) and news_id = " . $this->id_news . " and attr_nick ='related'";
        $res = $this->mysql->get_arrayref($query);
        $count1 = count($res);
        if ($count1) {
            $this->message("Поисковое выражение уже является частью существущего или такое выражение уже существует. Ничего не меняем");
            return false;
        }

        if ($this->debug_mode) {
            $this->message("В режиме отладки запрос не сохраняется");
            return;
        }

        // Ищем нет ли уже существующих входящих в искомое выражение слов в базе, все совпадения удаляем
        foreach ($relativesWords as $one_relativeWord) {
            if (strlen($one_relativeWord) < 4) continue; // Слишком короткие слова не анализируем
            $query = "select * from " . $this->searchDB . ".tbl_news_attrs where (lower(value_varchar) like lower('%$one_relativeWord %') or lower(value_varchar) like lower('% $one_relativeWord%') or lower(value_varchar) like lower('% $one_relativeWord %')) and CHAR_LENGTH('$queryForRelativesLink') >= CHAR_LENGTH(value_varchar) and news_id = " . $this->id_news . " and attr_nick ='related'";
            $res = $this->mysql->get_arrayref($query);
            $count = count($res);
            if ($count) {
                /* более длинный запрос поглощает сущ. короткий */
                $query = "delete from " . $this->searchDB . ".tbl_news_attrs where (lower(value_varchar) like lower('%$one_relativeWord %') or lower(value_varchar) like lower('% $one_relativeWord%') or lower(value_varchar) like lower('% $one_relativeWord %')) and CHAR_LENGTH('$queryForRelativesLink') >= CHAR_LENGTH(value_varchar) and news_id = " . $this->id_news . " and attr_nick ='related'";
                $this->mysql->exec_sql($query);
                $this->message("В таблице быстрого поиска есть более короткое выражения включающее это слово. Удаляем его.");
                break;
            }
        }

        $query = "insert into " . $this->searchDB . ".tbl_news_attrs (news_id, attr_nick, value_varchar, attr_type) values ('" . $this->id_news . "', 'related', '$queryForRelativesLink', 'varchar')";
        $this->mysql->exec_sql($query);
        $this->mysql->exec_sql("update " . $this->searchDB . ".tbl_news set news_modif_date = NOW() where news_id = '" . $this->id_news . "' limit 1");
        $this->memcache->delete("news:" . $this->searchDB . ":" . $this->id_news);
        $this->message("В таблицу быстрого поиска добавили новое выражение:", $queryForRelativesLink);
    }

    function save_to_suggest($normalizedQuery, $queryForSuggest, $originalsearchQuery)
    {
        if ($normalizedQuery == "" || $queryForSuggest == "") {
            $this->message("Пустой запрос для саджестов");
            return;
        }

        if ($this->debug_mode) {
            $this->message("В режиме отладки запрос не сохраняется");
            return;
        }
        
        $res = $this->mysql->get_row("select * from tbl_users_requests where lower(request) like lower('{$queryForSuggest}')");
        if (!$res) {
            $this->message("В таблице саджестов нет такого слова, запишем:", $queryForSuggest);
            $this->mysql->exec_sql("insert into tbl_users_requests (original, request, normalized, first_request_date, last_request_date, total_request_count)
                              values('" . mysql_real_escape_string($originalsearchQuery) . "','$queryForSuggest','$normalizedQuery', now(), now() , 1)");
        } else {
            $this->message("В таблице саджестов уже есть такие слова, добавляем количество запросов по слову:", $queryForSuggest);
            $total_count = $res['total_request_count'];
            $this->mysql->exec_sql("update tbl_users_requests set original='" . mysql_real_escape_string($originalsearchQuery) . "',request = '$queryForSuggest',normalized = '$normalizedQuery',  last_request_date =now(),
                              total_request_count =" . ++$total_count . " where request like '{$queryForSuggest}'");
        }

//        $this->mysql->exec_sql("insert into tbl_log_requests (original, request, normalized, request_date, url)
//                              values('" . mysql_real_escape_string($originalsearchQuery) . "','$queryForSuggest','$normalizedQuery', now(), '" . $this->full_url . "')");
    }

    // проверяет является ли слово нормализованной формой этого слова
    function is_normalized($searchQuery)
    {
        $searchQuery = mb_convert_case($searchQuery, MB_CASE_LOWER, "windows-1251");
        $stemmed_words = $this->lemmatizator($searchQuery);
        $stemmed_words_list = explode("|", $stemmed_words);
        foreach ($stemmed_words_list as $stemmed_word) {
            if ($searchQuery == trim($stemmed_word)) return true;
        }
        return false;

    }

    function lemmatized_word($searchQuery)
    {
        if (substr($searchQuery, -1) == "а") $isWoman = true; else $isWoman = false;
        $stemmed_words = $this->lemmatizator($searchQuery);
        $return_word = $stemmed_words;
        $stemmed_words_list = explode("|", $stemmed_words);
        if (count($stemmed_words_list) > 1) {
            foreach ($stemmed_words_list as $stemmed_word) {
                $stemmed_word = trim($stemmed_word, "?\n");
                if ($isWoman) {
                    if (substr($stemmed_word, -1) == "а") return $stemmed_word;
                } else {
                    if (substr($stemmed_word, -1) != "а") return $stemmed_word;
                }
                if ($searchQuery == trim($stemmed_word)) return true;
            }
        }

        return $return_word;
    }

    function lemmatizator($searchQuery)
    {
        $descriptorspec = array(
            0 => array("pipe", "r"), // stdin is a pipe that the child will read from
            1 => array("pipe", "w"), // stdout is a pipe that the child will write to
            2 => array("pipe", "/dev/null", "w") // stderr is a file to write to
        );

        $cwd = '/usr/local/www/export/admin/mystem';
        $env = null;

        $process = proc_open("$cwd/mystem -nl", $descriptorspec, $pipes, $cwd, $env);

        if (is_resource($process)) {
            fwrite($pipes[0], $searchQuery);
            fclose($pipes[0]);

            $stemmed_words = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $return_value = proc_close($process);
            $stemmed_words = str_replace('?', '', $stemmed_words);
            return $stemmed_words;
        }
    }

    function stemmer($words)
    {
        return $this->stemmer_obj->stem_words($words);
    }

    function message($outMessage, $outParam = "")
    {
        if ($this->debug_mode) {
            echo $outMessage . " <b>" . $outParam . "</b><br/>\n";
            //$this->logtext .= $outMessage . " <b>" . $outParam . "</b><br/>\n";
        }
    }

    function error($outMessage, $outParam = "")
    {
        throw new Exception("<span style=\"color:red;\">" . $outMessage . " <b>" . $outParam . "</b></span><br/>\n");
    }

    function logSave()
    {
        //echo $this->logtext . "<br><br>" . $this->logfile;
        file_put_contents($this->logfile, $this->logtext . "<br><br><br>\n\n\n", FILE_APPEND | LOCK_EX);
    }
}
