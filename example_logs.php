#!/usr/local/bin/php
<?php

setlocale(LC_ALL, 'ru_RU.CP1251');

include('search_analizer.php');

$debug = false;

$log_path = '/var/log/www/';
$wd = './';

$stat_log = $wd . 'yandex_stat.log';
$log_file = $log_path . 'nginx.access_log';

$config=array(
    'encoding'  => 'utf-8',    //  кодировка, одна из: 'cp866', 'cp1251', 'koi8-r', 'utf-8'
    'os'        => 'linux3.0-32bit',    //  операционная система, одна из 'freebsd', 'linux3.0-32bit'
    'debug'     => 1,
);


$pos = $position = 0;
if (file_exists($stat_log)) {
    $last_check = trim(file_get_contents($stat_log));
    $pos = $position = file_get_contents($stat_log);
}

$max = 2147483647;
//$today=date('Y-m-d');
//$today='2007-06-26';

$logsize = filesize($log_file);
if ($debug) print "Size:" . $logsize . "\n\n";
$sizelen = strlen($logsize);

if (filesize($log_file) < $pos) {
    //  ротируем лог-файл и устанавливаем проверку на новый
    $pos = 0;
    $position = 0;
}

$output = array();

$cache = array();
$newscache = array();
$months = array('Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12');
$i = 0;
if ($fp = fopen($log_file, 'r')) {
    while ($pos > $max) {
        fseek($fp, $max, SEEK_CUR);
        $pos -= $max;
    }
    fseek($fp, $pos, SEEK_CUR);

    while (!feof($fp)) {
        $str_orig = fgets($fp);
        $position += strlen($str_orig);
        if (preg_match("|yandsearch\?text=([^\"&]+)[\"&]|", $str_orig, $m)) {
            //print $m[1]."\n\t";
            $search = trim(urldecode($m[1]));
            $enc = mb_detect_encoding($search);
            $search2 = "";
            if ($enc) {
                $search2 = mb_convert_encoding($search, $enc, 'utf-8');
            }
            if (trim($search2) != "") $search = $search2;

            if (preg_match("|\"GET ([^\s]+)\s|", $str_orig, $m1)) $url = $m1[1]; else continue;

            if (preg_match("|/newspaper/article/\d+/\d+/\d+/(\d+)$|", $url, $art) || preg_match("|/newspaper/article/(\d+)/[a-z\d_]+$|", $url, $art)) {
                $id = $art[1];
            } else {
                continue;
            }
            $kw = $search;

            if ($debug) print("Запускаем анализатор для слова $kw\n");

            if ($id && $kw) {
                $text=$mysql->get("select text from table where id='$id'");
                $related=$mysql->get("select words from related where textid='$id'");
                $analizer=new SearchAnalizer($text, $related, $config);
                $analizer->Analize($kw);

                print_r($analizer->get_results());
            }
            if ($debug) print("Закончили анализировать слово $kw\n\n");

            $i++;
        }
    }

    fclose($fp);
}

file_put_contents($stat_log, $position);

function get_orig($search, $text)
{
    $ret = false;
    $search = preg_replace('/[\|\(\?\)\^\$\'\+\*\-\[\]]/', ' ', $search);
    $search = str_replace('\\', ' ', $search);
    //print $search."\n";

    if (preg_match("|[\"']" . $search . "[\"']|i", $text, $found)) {
        $ret = $found[0];
    } elseif (preg_match("|\b" . $search . "\b|i", $text, $found)) {
        $ret = $found[0];
    }
    return trim($ret);
}