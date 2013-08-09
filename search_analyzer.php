<?php
//
//ПредАнализатор
//
//Большие словосочетания не берем, максимум 3 слова
//    Ссылки не принимаются (!) Нововведение в том что будет улучшен поиск ссылок по названию доменов исключаются все поиски содержащие .com .ru .net .biz и т.д. А так же исключаться ссылки с опечатками (напр. htp://)
//    Не берем запросы короче 3 символов(!)
//    Не берем запросы длиннее 255 символов(! бывают оригиналы)
//    Не анализируем запросы со спецсимволами(вырезаем спецсимволы) - !"№;%:?*'{}[]%()_@#$^&<>/|\. В логах из спецсимволов каша. Берем запросы только с тире.(1)
//    Не анализируем запросы только из цифр и спецсимволов(! например даты и всякий мусор типа 11%)
//    Заменяем повторяющиеся пробелы в запросах одним пробелом(!).
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

/*

$config=array(
    'encoding'  =>'utf-8',      //  кодировка, одна из: 'cp866', 'cp1251', 'koi8-r', 'utf-8'
    'os'        =>'freebsd',    //  операционная система, одна из 'freebsd', 'linux3.0-32bit'
    'script_path'   => './'     //  путь к текущим скриптам
);

*/

class SearchAnalizer
{
    private $debug_mode, $searchQuery;
    private $related;
    private $article;
    private $document_root;
    private $mysql;
    private $stemmer_obj;
    static $encodings=array('cp866', 'cp1251', 'koi8-r', 'utf-8');
    static $oses=array('freebsd', 'linux3.0-32bit');
    private $encoding;
    private $mystem_cmd;
    private $added=array();
    private $removed=array();


    function __construct($news_text, $related=array(), $config=array())
    {
        $this->debug_mode = 1;
        $this->article = $news_text;
        $this->related=$related;
        $this->stemmer_obj = new SearchAnalizer_Stemmer();

        if (isset($config['encoding']) && in_array($config['encoding'], self::$encodings)) {
            $this->encoding=$config['encoding'];
        } else {
            $this->encoding='utf-8';
        }
        if (isset($config['os']) && in_array($config['os'], self::$oses)) {
            $mystem=$config['os'];
        } else {
            $mystem='freebsd';
        }

        if (isset($config['script_path'])) {
            $path=$config['script_path'];
        } elseif (isset($GLOBALS['argv'])) {
            $path=dirname(realpath($GLOBALS['argv'][0]));
        } else {
            $path='./';
        }
        $this->mystem_cmd=$path.'/mystem/mystem.'.$mystem;

    }

    function Analize($searchQuery)
    {
        try {
            if ($this->debug_mode) {
                $this->message("Запрос:" . $searchQuery);
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
    }

    function PreAnalizer($searchQuery)
    {
        $this->message("Запуск проверки пред анализатора поискового запроса");

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
        print "Words in query: $wordsInQuery\n\n";
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
            if (!$this->is_normalized($wordsInQuery[0])) $this->error("Не нормализованные словосочетания и слова не берем 1");
        } else {
            if (!$this->is_normalized($clearQuery)) $this->error("Не нормализованные словосочетания и слова не берем 2");
        }

        $this->message("Ошибок нет. Выражение допущено к поиску в следующей форме:", $searchQuery);
        return $searchQuery;
    }

    function SearchAnalizer($searchQuery)
    {
        $this->message("Запуск поиска вхождений поискового выражения в текст статьи");

        // поиск точных вхождений в текст статьи
        if ($this->encoding!='utf-8') {
            $searchQueryUTF = iconv($this->encoding, 'UTF-8', $searchQuery);
            $articleUTF = iconv($this->encoding, 'UTF-8', $this->article);
        } else {
            $searchQueryUTF=$searchQuery;
            $articleUTF=$this->article;
        }
        $templateOfInsert = "/(^|\s|)(" . preg_quote($searchQueryUTF) . iconv("windows-1251", "UTF-8", ")(\'|\"|\,|\;|\.|\s|\n|\r|\»|$)/isu");
        $r = preg_match($templateOfInsert, $articleUTF, $matches);
        if (!$r) {
            $this->message("Оригинальная форма '" . htmlspecialchars($searchQuery) . "'", "не найдено");
            $normalizedQuery = preg_replace('/[\+\=\!\"\№\;\%\:\?\*\'\»\«\{\}\[\]\%\(\)\_\@\#\$\^\&\<\>\/\|\.]/', ' ', $searchQuery);
            $normalizedQuery = str_replace('\\', '', trim($normalizedQuery));
            $normalizedQuery = preg_replace('/[\s]+/', ' ', $normalizedQuery);

            $normalizedQueryUTF = $this->encoding!='utf-8' ? mb_convert_encoding($normalizedQuery,"UTF-8", $this->encoding) : $normalizedQuery;

            $r = preg_match("/(^|\s|)(" . preg_quote($normalizedQueryUTF) . mb_convert_encoding(")(\'|\"|\,|\;|\.|\s|\n|\r|\»|$)/isu", "UTF-8", "windows-1251"), $articleUTF, $matches);
            if (!$r) {
                $this->message("Чистая форма(без спецсимволов) '" . htmlspecialchars($normalizedQuery) . "'", "не найдено");
            } else {
                $searchQuery = $this->encoding!='utf-8' ? iconv("UTF-8", $this->encoding, $matches[2]) : $matches[2];
            }
        } else {
            $searchQuery = $this->encoding!='utf-8' ? iconv("UTF-8", $this->encoding, $matches[2]) : $matches[2];
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
        $normalizedQueryUTF = $this->encoding!='utf-8' ? mb_convert_encoding($cleanQuery, 'UTF-8', $this->encoding) : $cleanQuery;
        $normalizedQueryUTF = $this->stemmer($normalizedQueryUTF);
        $normalizedQuery = $this->encoding!='utf-8' ? mb_convert_encoding($normalizedQueryUTF, $this->encoding, 'UTF-8') : $normalizedQueryUTF;
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
            $first_letter = mb_substr($one_word, 0, 1, $this->encoding);
            $second_letter = mb_substr($one_word, 1, 1, $this->encoding);
            $is_abbreviature = false;
            $is_name = false;
            if (preg_match('/\\p{Lu}|\\p{Lt}/u', $this->encoding!='utf-8' ? iconv($this->encoding, "UTF-8", $first_letter) : $first_letter) > 0
                && !
                preg_match('/\\p{Lu}|\\p{Lt}/u', $this->encoding!='utf-8' ? iconv($this->encoding, "UTF-8", $second_letter) : $second_letter) > 0
            ) {
                $is_name = true;
            } elseif (preg_match('/\\p{Lu}|\\p{Lt}/u', $this->encoding!='utf-8' ? iconv($this->encoding, "UTF-8", $first_letter) : $first_letter) > 0
                &&
                preg_match('/\\p{Lu}|\\p{Lt}/u', $this->encoding!='utf-8' ? iconv($this->encoding, "UTF-8", $second_letter) : $second_letter) > 0
            ) {
                $is_abbreviature = true;
            }
            if ($is_abbreviature) {
                $normalized_word = $normalizedArray[$key];
                $elt = mb_convert_case($normalized_word, MB_CASE_UPPER, $this->encoding);
                $result_words[] = $elt;
            } elseif ($is_name) {
                $normalized_word = $normalizedArray[$key];
                $elt = mb_convert_case($normalized_word, MB_CASE_TITLE, $this->encoding);
                $result_words[] = $elt;
            } else {
                $normalized_word = $normalizedArray[$key];
                $result_words[] = $normalized_word;
            }
        }
        $normalizedQuery = implode(" ", $result_words);
        $this->message("Чистим и стеммим запрос для сохранения. Преобразованный вариант запроса:", $normalizedQuery);


        $queryForRelativesLink = $cleanQuery;

        $queryForSuggest = mb_convert_case($cleanQuery, MB_CASE_LOWER, $this->encoding);
        $normalizedQuery = mb_convert_case($normalizedQuery, MB_CASE_LOWER, $this->encoding);
        /* Финальный этап сохранения в садджесты и быстрый поиск. */
        $this->message("В быстрый поиск пробуем сохранить:", $queryForRelativesLink);
        $this->save_to_related($queryForRelativesLink);
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
        if (count($this->related) >= 5) {
            $this->message("Статья уже имеет 5 записей в быстром поиске. Ничего не добавляем");
            return false;
        }

        // Проверка может быть наше поисковое выражение уже является частью существующего тогда выходим
        $bur=false;
        foreach ($this->related as $id=>$word) {
            if (false!==mb_strpos(mb_strtolower($word, $this->encoding), mb_strtolower($queryForRelativesLink, $this->encoding))) {
                $bur=true;
                break;
            }
        }
        if ($bur) {
            $this->message("Поисковое выражение уже является частью существущего или такое выражение уже существует. Ничего не меняем");
            return false;
        }

        // Ищем нет ли уже существующих входящих в искомое выражение слов в базе, все совпадения удаляем
        foreach ($relativesWords as $one_relativeWord) {
            if (mb_strlen($one_relativeWord, $this->encoding) < 4) continue; // Слишком короткие слова не анализируем
            $this->message("проверяем $one_relativeWord...");
            foreach ($this->related as $id=>$word) {
                if (false!==mb_strpos(mb_strtolower($one_relativeWord, $this->encoding), mb_strtolower($word, $this->encoding))) {
                    $this->removed[$id]=$word;
                    unset($this->related[$id]);
                    break;
                }
            }
        }

        $this->added[]=$queryForRelativesLink;
        $this->related[]=$queryForRelativesLink;
        $this->message("В таблицу быстрого поиска добавили новое выражение:", $queryForRelativesLink);
    }

    function get_results() {
        return array('related'=>$this->related, 'added'=>$this->added, 'removed'=>$this->removed);
    }

    // проверяет является ли слово нормализованной формой этого слова
    function is_normalized($searchQuery)
    {
        $searchQuery = mb_convert_case($searchQuery, MB_CASE_LOWER, $this->encoding);
        $stemmed_words = $this->lemmatizator($searchQuery);
        print "stemmed words: $stemmed_words\n";
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

        $process = proc_open($this->mystem_cmd." -e ".$this->encoding." -nl", $descriptorspec, $pipes);

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
































class SearchAnalizer_Stemmer
{
    var $Stem_Caching = 0;
    var $kill_predlog = true; //удалять или нет предлоги из фразы
    var $Stem_Cache = array();
    var $VOWEL = 'аеиоуыэюя';
    var $PERFECTIVEGROUND = '((ив|ивши|ившись|ыв|ывши|ывшись)|((?<=[ая])(в|вши|вшись)))$';
    var $REFLEXIVE = '(с[яь])$';
    var $ADJECTIVE = '(ее|ие|ые|ое|ими|ыми|ей|ий|ый|ой|ем|им|ым|ом|его|ого|ему|ому|их|ых|ую|юю|ая|яя|ою|ею)$';
    var $PARTICIPLE = '((ивш|ывш|ующ)|((?<=[ая])(ем|нн|вш|ющ|щ)))$';
    var $VERB = '((ила|ыла|ена|ейте|уйте|ите|или|ыли|ей|уй|ил|ыл|им|ым|ен|ило|ыло|ено|ят|ует|уют|ит|ыт|ены|ить|ыть|ишь|ую|ю)|((?<=[ая])(ла|на|ете|йте|ли|й|л|ем|н|ло|но|ет|ют|ны|ть|ешь|нно)))$';
    var $NOUN = '(а|ев|ов|ие|ье|е|иями|ями|ами|еи|ии|и|ией|ей|ой|ий|й|иям|ям|ием|ем|ам|ом|о|у|ах|иях|ях|ы|ь|ию|ью|ю|ия|ья|я)$';
    var $RVRE = '^(.*?[аеиоуыэюя])(.*)$';
    var $DERIVATIONAL = '[^аеиоуыэюя][аеиоуыэюя]+[^аеиоуыэюя]+[аеиоуыэюя].*(?<=о)сть?$';
    var $PREDLOG = 'и|для|в|на|под|из|с|по';

    function s(&$s, $re, $to)
    {
        $orig = $s;
        $s = mb_ereg_replace($re, $to, $s);
        return $orig !== $s;
    }

    function m($s, $re)
    {
        return mb_ereg_match($re, $s);
    }

    function stem_words($words)
    {

        $word = explode(' ', $words);
        for ($i = 0; $i < count($word); $i++) {
            if ($this->kill_predlog) $word[$i] = mb_ereg_replace('(' . $this->PREDLOG . ')$', '', $word[$i]);
            $word[$i] = $this->stem_word($word[$i]);
        }
        return implode(' ', $word);
    }

    function stem_word($word)
    {
        mb_regex_encoding('UTF-8');
        mb_internal_encoding('UTF-8');
        $word = $word = mb_strtolower($word);
        $word = str_replace('ё', 'е', $word);
        # Check against cache of stemmed words
        if ($this->Stem_Caching && isset($this->Stem_Cache[$word])) {
            return $this->Stem_Cache[$word];
        }
        $stem = $word;
        do {
            if (!mb_ereg($this->RVRE, $word, $p)) break;
            $start = $p[1];
            $RV = $p[2];
            if (!$RV) break;

            # Step 1
            if (!$this->s($RV, $this->PERFECTIVEGROUND, '')) {
                $this->s($RV, $this->REFLEXIVE, '');

                if ($this->s($RV, $this->ADJECTIVE, '')) {
                    $this->s($RV, $this->PARTICIPLE, '');
                } else {
                    if (!$this->s($RV, $this->VERB, ''))
                        $this->s($RV, $this->NOUN, '');
                }
            }

            # Step 2
            $this->s($RV, 'и$', '');

            # Step 3
            if ($this->m($RV, $this->DERIVATIONAL))
                $this->s($RV, 'ость?$', '');

            # Step 4
            if (!$this->s($RV, 'ь$', '')) {
                $this->s($RV, 'ейше?', '');
                $this->s($RV, 'нн$', 'н');
            }

            $stem = $start . $RV;
        } while (false);
        if ($this->Stem_Caching) $this->Stem_Cache[$word] = $stem;
        return $stem;
    }

    function stem_caching($parm_ref)
    {
        $caching_level = @$parm_ref['-level'];
        if ($caching_level) {
            if (!$this->m($caching_level, '^[012]$')) {
                die(__CLASS__ . "::stem_caching() - Legal values are '0','1' or '2'. '$caching_level' is not a legal value");
            }
            $this->Stem_Caching = $caching_level;
        }
        return $this->Stem_Caching;
    }

    function clear_stem_cache()
    {
        $this->Stem_Cache = array();
    }
}