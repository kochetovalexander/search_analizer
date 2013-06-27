<?php
/**
 * Created by JetBrains PhpStorm.
 * User: vadim
 * Date: 21.03.13
 * Time: 12:18
 * To change this template use File | Settings | File Templates.
 */

class Stemmer
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