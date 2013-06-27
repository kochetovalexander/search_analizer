<?php
//
//��������������
//
//������� �������������� �� �����, �������� 3 �����
//    ������ �� ����������� (!) ������������ � ��� ��� ����� ������� ����� ������ �� �������� ������� ����������� ��� ������ ���������� .com .ru .net .biz � �.�. � ��� �� ����������� ������ � ���������� (����. htp://)
//    ������ ������ � ������ �� �������
//    �� ����� ������� ������ 3 ��������(!)
//    �� ����� ������� ������� 255 ��������(! ������ ���������)
//    �� ����������� ������� �� �������������(�������� �����������) - !"�;%:?*'{}[]%()_@#$^&<>/|\. � ����� �� ������������ ����. ����� ������� ������ � ����.(1)
//    �� ����������� ������� ������ �� ���� � ������������(! �������� ���� � ������ ����� ���� 11%)
//    �������� ������������� ������� � �������� ����� ��������(!). �������� "�������� �����"
//
//���������
//
//    ����� � ������ ������ �� ������� �������(����� �� ������������� ������ ������� ��� ���������)
//    ����� � ������ �� ������ ��� ������������ � ������� �� ������� � ���
//    ���� �� ���������������� ������ �������(��������������� ������������ �����)
//    � ��� ���� � ������� ����� ���������� ��� ���������������
//
//��������������
//
//    ������� �� ������� ��� ������ �������
//    ����������� ��������� ����� � ��������������� �������� ��� ��������� ����� � ������������
//    ����� �� ������ � ��������� ����������
//    ���������� � ������� ����� ��� ������ ����� ������� ��� ��������� ���/��������
//    ���������� � �������� ������ ����� ������� + ��������������� �����(�� ��������������� ����� ��� ������ ����� ������������ ���������)
//    � �������� ����������� ������� ������ � ������ ��������
//
//����������
//
//    ���������� �������� ������. �������� �� ������� � ������� ������ ��� ������������ ���� ���������(��� ������������� ��������)
//    ���������� ��������. ��� ������������ ��������� ������ ���������� ������� ������� � ������������� ����� ���������� �������(����� ��� ������)

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

        // ������� ����� ��������� ������
        if ($this->debug_mode) {
            $this->analize_period = 1000;
            ?>
            <form method="get" action="/search/">
                <input type="text" size="70" name="s" value="<?= htmlspecialchars($_REQUEST['s']) ?>"> ������� ���������
                <br>
                <input type="text" size="70" name="u" value="<?= $_REQUEST['u'] ?>"> URL ������(������ � http://)<br>
                <input type="hidden" name="m" value="u">
                <input type="hidden" name="d" value="1">
                <input type="submit" value="���������">
            </form><br><br>
        <?php
        }

        try {
            if ($this->debug_mode) {
                $this->message("������:" . $searchQuery);
                $this->message("�������:" . $this->id_news);
            }
            $originalsearchQuery = $searchQuery;
            // ��������� ��������������
            $searchQuery = $this->PreAnalizer($searchQuery);
            // ��������� ��������� ����������
            $searchQuery = $this->SearchAnalizer($searchQuery);
            // ��������� ��������������
            $searchQuery = $this->PostAnalizer($searchQuery, $originalsearchQuery);
        } catch (Exception $ex) {
            if ($this->debug_mode) echo $ex->getMessage();
        }
//        try {
//            // �������� ��� ������������
//            $this->logSave();
//        } catch (Exception $ex) {
//            // ���������� ������ ������
//        }
    }

    function PreAnalizer($searchQuery)
    {
        $this->message("������ �������� ���� ����������� ���������� �������");

        // ������ ������ � ������ �� �������.
        $news = $this->mysql->get_arrayref("select news_body, news_date from " . $this->searchDB . ".tbl_news where news_id = '" . $this->id_news . "' limit 1");
        if (count($news) == 0) {
            $this->error("������ �� ������� � ���� ������ ������. ID �������:", $this->id_news);
        }
        $this->article = $news[0]['news_body'];
        if (inc_date(-$this->analize_period) . ' 00:00:00' > $news[0]['news_date']) {
            $this->error("������ ������� ������. ���������� ������ ������� ������:", $this->analize_period . " ����");
        }

        // �� ����� ������� ������ 3 ��������
        if (strlen($searchQuery) < 3) {
            $this->error("������ ������� ��������. �� ������������ ������� ������ 3 ��������");
        }

        // �� ����� ������� ������� 255 ��������
        if (strlen($searchQuery) > 255) {
            $this->error("������ ������� �������. �� ������������ ������� ������ 255 ��������");
        }

        // �������� ��� ������������� ���������� ������� �� ��������� �������
        $searchQuery = preg_replace('/[\s]+/', ' ', $searchQuery);

        // ������� �������������� �� ����� �������� 3 �����
        $wordsInQuery = preg_replace('/[\+\=\!\"\�\;\%\:\?\*\'\�\�\{\}\[\]\%\(\)\_\@\#\$\^\&\<\>\/\|\.0-9]/', ' ', $searchQuery);
        $wordsInQuery = str_replace('\\', '', trim($wordsInQuery));
        $wordsInQuery = preg_replace('/[\s]+/', ' ', $wordsInQuery);
        $wordsInQuery = explode(' ', $wordsInQuery);
        if (count($wordsInQuery) > 3) {
            $this->error("�� ������������ ������� ������ 3 ����");
        }

        // ������� ���������� URL �� �����������
        if (preg_match('/(\.[a-z1-9�-�]+|http\:\/\/|https\:\/\/|htp\:\/\/|htps\:\/\/)/i', $searchQuery)) {
            $this->error("�� ������������ ������� ���������� URL");
        }

        // ������ � ��������� �� ����� �������� � ����� ��������� �� �����
        $clearQuery = preg_replace('/[\!\"\�\;\%\:\?\*\'\�\�\{\}\[\]\%\(\)\_\@\#\$\^\&\<\>\/\|\.0-9]/', '', $searchQuery);
        $clearQuery = str_replace('\\', '', $clearQuery);
        if (strlen(trim($clearQuery)) < 1) {
            $this->error("������, ��������������� � ��������� �� ����� �������� � ���� ��������� �� �����");
        }

        // ����� ������ ��������� � ��������������� ������ �������(��� �������������) �����
        if (count($wordsInQuery) > 1) {
            if (!$this->is_normalized($wordsInQuery[0])) $this->error("�� ��������������� �������������� � ����� �� �����");
        } else {
            if (!$this->is_normalized($clearQuery)) $this->error("�� ��������������� �������������� � ����� �� �����");
        }

        $this->message("������ ���. ��������� �������� � ������ � ��������� �����:", $searchQuery);
        return $searchQuery;
    }

    function SearchAnalizer($searchQuery)
    {
        $this->message("������ ������ ��������� ���������� ��������� � ����� ������");

        // ����� ������ ��������� � ����� ������
        $searchQueryUTF = iconv('windows-1251', 'UTF-8', $searchQuery);
        $articleUTF = iconv('windows-1251', 'UTF-8', $this->article);
        $templateOfInsert = "/(^|\s|)(" . preg_quote($searchQueryUTF) . iconv("windows-1251", "UTF-8", ")(\'|\"|\,|\;|\.|\s|\n|\r|\�|$)/isu");
        $r = preg_match($templateOfInsert, $articleUTF, $matches);
        if (!$r) {
            $this->message("������������ ����� '" . htmlspecialchars($searchQuery) . "'", "�� �������");
            $normalizedQuery = preg_replace('/[\+\=\!\"\�\;\%\:\?\*\'\�\�\{\}\[\]\%\(\)\_\@\#\$\^\&\<\>\/\|\.]/', ' ', $searchQuery);
            $normalizedQuery = str_replace('\\', '', trim($normalizedQuery));
            $normalizedQuery = preg_replace('/[\s]+/', ' ', $normalizedQuery);
            $normalizedQueryUTF = iconv("windows-1251", "UTF-8", $normalizedQuery);
            $r = preg_match("/(^|\s|)(" . preg_quote($normalizedQueryUTF) . iconv("windows-1251", "UTF-8", ")(\'|\"|\,|\;|\.|\s|\n|\r|\�|$)/isu"), $articleUTF, $matches);
            if (!$r) {
                $this->message("������ �����(��� ������������) '" . htmlspecialchars($normalizedQuery) . "'", "�� �������");
                // ������� ����� �� ����������, ���������� ������� ������������ ��� �� �� ��� ������
//                $wordsBeforeInQuery = count(explode(' ', $normalizedQuery)); // ���������� ���� �� �������
//                if ($wordsBeforeInQuery > 1) $this->error("�������������� ��� ������������ ������ �� �����");
//                $lemmatizedWords = $this->lemmatized_word($normalizedQuery);
//                $normalizedQuery = $lemmatizedWords;
//                $normalizedQuery = str_replace('?', '', $normalizedQuery);
//                $normalizedQuery = str_replace('!', '', $normalizedQuery);
//                $normalizedQuery = preg_replace('/[\s]+/', ' ', $normalizedQuery);
//                $wordsAfterInQuery = count(explode(' ', $normalizedQuery)); // ���������� ���� ����� �������
//                if (trim($normalizedQuery) == "" || $wordsBeforeInQuery != $wordsAfterInQuery) {
//                    $this->error("�� ������� �������� ������������� ������");
//                }
//                $normalizedQueryUTF = iconv("windows-1251", "UTF-8", $normalizedQuery);
//                $r = preg_match("/(^|\s|)(" . $normalizedQueryUTF . iconv("windows-1251", "UTF-8", ")(\'|\"|\,|\;|\.|\s|\n|\r|\�|$)/isu"), $articleUTF, $matches);
//                if (!$r) {
//                    $this->message("�����-�������� �����(������������ �����, ������������ �����) '" . htmlspecialchars($normalizedQuery) . "'", "�� �������");
//                } else {
//                    $searchQuery = iconv("UTF-8", "windows-1251", $matches[2]);
//                }
            } else {
                $searchQuery = iconv("UTF-8", "windows-1251", $matches[2]);
            }
        } else {
            $searchQuery = iconv("UTF-8", "windows-1251", $matches[2]);
        }

        /* ����� �� ������ �������. */
        if (!$r) {
            $this->error("�� ������� ����� � ������ ��������� ������");
        } else {
            $this->message("����� ������. ��������� �������� � ������� � ��������� �����:", $searchQuery);
        }

        return $searchQuery;
    }

    function PostAnalizer($searchQuery, $originalsearchQuery)
    {
        $this->message("������ ���� ����������� ���������� ���������");

        // ������� ��� �����������
        $cleanQuery = preg_replace('/[\+\=\!\"\�\;\%\:\?\*\'\�\�\{\}\[\]\%\(\)\_\@\#\$\^\&\<\>\/\|\.]/', ' ', $searchQuery);
        $cleanQuery = str_replace('\\', '', trim($cleanQuery));
        $cleanQuery = preg_replace('/[\s]+/', ' ', $cleanQuery);


        // �������� ��������������� �����
        $wordsBeforeInQuery = count(explode(' ', $cleanQuery)); // ���������� ���� �� �������
        $normalizedQueryUTF = mb_convert_encoding($cleanQuery, 'UTF-8', 'windows-1251');
        $normalizedQueryUTF = $this->stemmer($normalizedQueryUTF);
        $normalizedQuery = mb_convert_encoding($normalizedQueryUTF, 'windows-1251', 'UTF-8');
        $normalizedQuery = str_replace('?', '', $normalizedQuery);
        $normalizedQuery = str_replace('!', '', $normalizedQuery);
        $normalizedQuery = preg_replace('/[\s]+/', ' ', $normalizedQuery);
        $wordsAfterInQuery = count(explode(' ', $normalizedQuery)); // ���������� ���� ����� �������
        if ($wordsBeforeInQuery != $wordsAfterInQuery) {
            $this->error("�� ������� �������� ������������� ������ � ���� �����������");
        }

        $normalizedArray = explode(" ", $normalizedQuery);
        $cleanArray = explode(" ", $cleanQuery);

        /* ����� �������, ����������� � ���������� ����. ������ ��������� ���� � ��������������� �����*/
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
        $this->message("������ � ������� ������ ��� ����������. ��������������� ������� �������:", $normalizedQuery);


        /* � ������� ����� ����������� ����� � �������� ����������� � ��� ����� � ������� ��� ���� � ������(���� ��� ����������� � ������������ �����). */
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
        $findedCrosslinks = preg_match_all("/%%%([�-��-�A-Za-z\s]+)%%%/im", $article_text_parsed, $crossWords);
        if ($findedCrosslinks) {
            foreach ($crossWords[1] as $crossWord) {
                $reg = "/" . iconv("windows-1251", "UTF-8", $normalizedQuery) . "/isu";
                if (preg_match($reg, iconv("windows-1251", "UTF-8", $crossWord))) {
                    $findedNames = $crossWord;
                    break;
                }
            }
        }
        // ���� ��������� �� ������� � ������ � ��������� ������ �� ����� ������������ ������
        $queryForRelativesLink = "";
        if (!isset($findedNames) || empty($findedNames)) {
            $queryForRelativesLink = $cleanQuery;
            $this->message("� ������ � ��������� ��������� �� �������");
        } else {
            $queryForRelativesLink = $findedNames;
            $this->message("� ������ � ��������� ������� ������� ���������");
        }

        $queryForSuggest = mb_convert_case($cleanQuery, MB_CASE_LOWER, "windows-1251");
        $normalizedQuery = mb_convert_case($normalizedQuery, MB_CASE_LOWER, "windows-1251");
        /* ��������� ���� ���������� � ��������� � ������� �����. */
        $this->message("� ������� ����� ������� ���������:", $queryForRelativesLink);
        $this->save_to_related($queryForRelativesLink);
        $this->message("� ��������� ������� ���������(������������ ����� - ��������� �����):", $normalizedQuery . " - " . $queryForSuggest);
        $this->save_to_suggest($normalizedQuery, $queryForSuggest, $originalsearchQuery);
    }

    // ���������� � "������� �����"
    function save_to_related($queryForRelativesLink)
    {
        if (!isset($queryForRelativesLink) || empty($queryForRelativesLink)) {
            $this->message("������ ��������� ����������");
            return;
        }
        // ��������� ��� �����������
        $relativesWords = explode(" ", $queryForRelativesLink);

        // ������ ���� ������� � ������� ����� �� ���������
        $related = $this->mysql->get_arrayref("select value_varchar from " . $this->searchDB . ".tbl_news_attrs where news_id='" . $this->id_news . "' and attr_nick = 'related'");
        if (count($related) >= 5) {
            $this->message("������ ��� ����� 5 ������� � ������� ������. ������ �� ���������");
            return false;
        }

        // �������� ����� ���� ���� ��������� ��������� ��� �������� ������ ������������� ����� �������
        $query = "select * from " . $this->searchDB . ".tbl_news_attrs where ((lower(value_varchar) like lower('%$queryForRelativesLink %') or lower(value_varchar) like lower('% $queryForRelativesLink%') or lower(value_varchar) like lower('% $queryForRelativesLink %')) or (lower(value_varchar) like lower('%$queryForRelativesLink%') and CHAR_LENGTH('$queryForRelativesLink') = CHAR_LENGTH(value_varchar))) and news_id = " . $this->id_news . " and attr_nick ='related'";
        $res = $this->mysql->get_arrayref($query);
        $count1 = count($res);
        if ($count1) {
            $this->message("��������� ��������� ��� �������� ������ ������������ ��� ����� ��������� ��� ����������. ������ �� ������");
            return false;
        }

        if ($this->debug_mode) {
            $this->message("� ������ ������� ������ �� �����������");
            return;
        }

        // ���� ��� �� ��� ������������ �������� � ������� ��������� ���� � ����, ��� ���������� �������
        foreach ($relativesWords as $one_relativeWord) {
            if (strlen($one_relativeWord) < 4) continue; // ������� �������� ����� �� �����������
            $query = "select * from " . $this->searchDB . ".tbl_news_attrs where (lower(value_varchar) like lower('%$one_relativeWord %') or lower(value_varchar) like lower('% $one_relativeWord%') or lower(value_varchar) like lower('% $one_relativeWord %')) and CHAR_LENGTH('$queryForRelativesLink') >= CHAR_LENGTH(value_varchar) and news_id = " . $this->id_news . " and attr_nick ='related'";
            $res = $this->mysql->get_arrayref($query);
            $count = count($res);
            if ($count) {
                /* ����� ������� ������ ��������� ���. �������� */
                $query = "delete from " . $this->searchDB . ".tbl_news_attrs where (lower(value_varchar) like lower('%$one_relativeWord %') or lower(value_varchar) like lower('% $one_relativeWord%') or lower(value_varchar) like lower('% $one_relativeWord %')) and CHAR_LENGTH('$queryForRelativesLink') >= CHAR_LENGTH(value_varchar) and news_id = " . $this->id_news . " and attr_nick ='related'";
                $this->mysql->exec_sql($query);
                $this->message("� ������� �������� ������ ���� ����� �������� ��������� ���������� ��� �����. ������� ���.");
                break;
            }
        }

        $query = "insert into " . $this->searchDB . ".tbl_news_attrs (news_id, attr_nick, value_varchar, attr_type) values ('" . $this->id_news . "', 'related', '$queryForRelativesLink', 'varchar')";
        $this->mysql->exec_sql($query);
        $this->mysql->exec_sql("update " . $this->searchDB . ".tbl_news set news_modif_date = NOW() where news_id = '" . $this->id_news . "' limit 1");
        $this->memcache->delete("news:" . $this->searchDB . ":" . $this->id_news);
        $this->message("� ������� �������� ������ �������� ����� ���������:", $queryForRelativesLink);
    }

    function save_to_suggest($normalizedQuery, $queryForSuggest, $originalsearchQuery)
    {
        if ($normalizedQuery == "" || $queryForSuggest == "") {
            $this->message("������ ������ ��� ���������");
            return;
        }

        if ($this->debug_mode) {
            $this->message("� ������ ������� ������ �� �����������");
            return;
        }
        
        $res = $this->mysql->get_row("select * from tbl_users_requests where lower(request) like lower('{$queryForSuggest}')");
        if (!$res) {
            $this->message("� ������� ��������� ��� ������ �����, �������:", $queryForSuggest);
            $this->mysql->exec_sql("insert into tbl_users_requests (original, request, normalized, first_request_date, last_request_date, total_request_count)
                              values('" . mysql_real_escape_string($originalsearchQuery) . "','$queryForSuggest','$normalizedQuery', now(), now() , 1)");
        } else {
            $this->message("� ������� ��������� ��� ���� ����� �����, ��������� ���������� �������� �� �����:", $queryForSuggest);
            $total_count = $res['total_request_count'];
            $this->mysql->exec_sql("update tbl_users_requests set original='" . mysql_real_escape_string($originalsearchQuery) . "',request = '$queryForSuggest',normalized = '$normalizedQuery',  last_request_date =now(),
                              total_request_count =" . ++$total_count . " where request like '{$queryForSuggest}'");
        }

//        $this->mysql->exec_sql("insert into tbl_log_requests (original, request, normalized, request_date, url)
//                              values('" . mysql_real_escape_string($originalsearchQuery) . "','$queryForSuggest','$normalizedQuery', now(), '" . $this->full_url . "')");
    }

    // ��������� �������� �� ����� ��������������� ������ ����� �����
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
        if (substr($searchQuery, -1) == "�") $isWoman = true; else $isWoman = false;
        $stemmed_words = $this->lemmatizator($searchQuery);
        $return_word = $stemmed_words;
        $stemmed_words_list = explode("|", $stemmed_words);
        if (count($stemmed_words_list) > 1) {
            foreach ($stemmed_words_list as $stemmed_word) {
                $stemmed_word = trim($stemmed_word, "?\n");
                if ($isWoman) {
                    if (substr($stemmed_word, -1) == "�") return $stemmed_word;
                } else {
                    if (substr($stemmed_word, -1) != "�") return $stemmed_word;
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
