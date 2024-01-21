<?php

namespace FpDbTest;

use FpDbTest\Quotation\IQuotation;
use mysqli;
use Exception;

class DatabaseEngine extends Database
{

    private $quotation;

    public function __construct(mysqli $mysqli, Quotation $quotation)
    {
        $this->mysqli = $mysqli;
        $this->quotation = $quotation;
    }


    const BIND_SKIP = -INF;  //спец. значение метки-заменителя для удаления условного блока
    const BIND_KEEP = +INF;  //спец. значение метки-заменителя для сохранения условного блока

    const VALUE_PREFIX = '?';
    const FIELD_PREFIX = '@';


    const BLOCK_OPEN_TAG  = '{';
    const BLOCK_CLOSE_TAG = '}';
    const ENCODE_QUOTED = [
        //"\x01" => "\x011\x02", //binary safe!
        //"\x02" => "\x012\x02", //binary safe!
        '?'    => "\x013\x02",
        ':'    => "\x014\x02",
        '@'    => "\x015\x02",
        '{'    => "\x016\x02",
        '}'    => "\x017\x02",
    ];

    const DECODE_QUOTED = [
        //"\x011\x02" => "\x01",
        //"\x012\x02" => "\x02",
        "\x013\x02" => '?',
        "\x014\x02" => ':',
        "\x015\x02" => '@',
        "\x016\x02" => '{',
        "\x017\x02" => '}',
    ];


    // Мэппинг эндпоинтов API на вызовы
    protected $app_endpoints = [
        '?d' => 'bindInt',
        '?f' => 'bindFloat',
        '?a' => 'bindArray',
        '?#' => 'bindId',
        '? ' => 'bindMulti',
    ];

    protected $sql;

    const PLACEHOLDER_PATTERN = '~ [?] [dfa\#\s] ~sxSX';


    public function buildQuery(string $sql, array $args = []): string
    {

        $hasBlocks = is_int($offset = strpos($sql, static::BLOCK_OPEN_TAG))
            && is_int(strpos($sql, static::BLOCK_CLOSE_TAG, $offset));

        //квотированные данные могут содержать спецсимволы, которые не являются частью настоящих меток-заменителей и парных блоков
        //закодируем эти спецсимволы, чтобы корректно работали замены настоящих меток-заменителей и парсинг парных блоков
        // foreach ($args as $key => $value) {
        //     $args[$key] = strtr($value, static::ENCODE_QUOTED);
        // }

        $sql = static::replacePlaceholder($sql, $args);

        if ($hasBlocks) {
            $tokens = static::tokenize($sql, static::BLOCK_OPEN_TAG, static::BLOCK_CLOSE_TAG);
            $tokens = static::removeUnused($tokens);
            $sql = static::unTokenize($tokens);
        }


        //если в SQL запросе остались неиспользуемые метки-заменители, то при его выполнении будет ошибка синтаксиса
        //лучше показать разработчику точную по смыслу ошибку с описанием проблемы
        $placeholder = static::getFirstPlaceholder($sql);
        if (is_string($placeholder)) {
            $openTag  = static::BLOCK_OPEN_TAG;
            $closeTag = static::BLOCK_CLOSE_TAG;
            throw new \Exception("Метка-заменитель '$placeholder' не была заменена (она находится не внутри блоков с парными тегами '$openTag' и '$closeTag'), т.к. в массиве замен ключ '$placeholder' отсутствует");
        }

        //$sql = strtr($sql, static::DECODE_QUOTED);

        return $sql;
    }




    /**
     * Разбивает строку на части по парным тегам, учитывая вложенность
     *
     * @param string $str
     * @param string $openTag
     * @param string $closeTag
     *
     * @return array        Возвращает массив, где каждый элемент -- это массив из части строки и уровня вложенности
     * @throws \Exception
     */
    protected static function tokenize(string $str, string $openTag, string $closeTag): array
    {
        if ($openTag === $closeTag) {
            throw new \Exception("Парные теги '$openTag' и '$closeTag' не должны быть одинаковыми");
        }
        $level = 0;
        $tokens = [];
        $opens = explode($openTag, $str);
        foreach ($opens as $open) {
            $closes = explode($closeTag, $open);
            $tokens[] = [$closes[0], ++$level];
            unset($closes[0]);
            foreach ($closes as $close) {
                $tokens[] = [$close, --$level];
            }
        }
        if ($level !== 1) {
            throw new \Exception("Парность тегов '$openTag' и '$closeTag' не соблюдается, level=$level");
        }
        return $tokens;
    }

    /**
     * Обратная функция по отношению к tokeinze()
     *
     * @param array &$tokens
     *
     * @return string
     */
    protected static function unTokenize(array &$tokens): string
    {
        $str = '';
        foreach ($tokens as $token) {
            $str .= $token[0];
        }
        return $str;
    }

    /**
     * Удаляет из массива элементы, в которых остались неиспользуемые метки-заменители
     *
     * @param array &$tokens
     *
     * @return array    Возвращает массив с той же структурой, что и массив на входе
     */
    protected static function removeUnused(array &$tokens): array
    {
        $return = [];
        $removeLevel = null;
        foreach ($tokens as $index => $token) {
            [$str, $currentLevel] = $token;
            if ($removeLevel !== null && $removeLevel > $currentLevel) {
                for ($i = count($return); $i > 0; $i--) {
                    if ($return[$i - 1][1] < $removeLevel) {
                        break;
                    }
                    unset($return[$i - 1]);
                }
                $removeLevel = null;
            }
            if ($removeLevel === null && $currentLevel > 1 && static::getFirstPlaceholder($str) !== null) {
                $removeLevel = $currentLevel;
                continue;
            }
            if ($removeLevel === null) {
                $return[] = $token;
            }
        }
        return $return;
    }



    /**
     * Возвращает название первой найденной метки-заменителя в строке
     *
     * @param string $str
     *
     * @return string|null  Возвращает null, если ничего не найдено
     */
    protected static function getFirstPlaceholder(string $str): ?string
    {
        $matches = [];
        foreach ([static::VALUE_PREFIX] as $char) {
            //speed improves by strpos()
            $offset = strpos($str, $char);
            if (
                $offset !== false
                && preg_match(static::PLACEHOLDER_PATTERN, $str, $matches, null, $offset) === 1
            ) {
                return $matches[0];
            }
        }
        return null;
    }

    /**
     * Заменяет найденные метки-заменителя в строке
     *
     * @param string $str
     *
     * @return string  Возвращает сформированную строку
     */
    protected function replacePlaceholder(string $str, $value)
    {
        $sql = '';
        $begin = 0;
        do {
            //$matches = [];
            foreach ([static::VALUE_PREFIX] as $char) {
                $offset = strpos($str, $char, $begin);
                if (
                    $offset !== false
                    && preg_match(static::PLACEHOLDER_PATTERN, $str, $matches, null, $offset) === 1
                ) {

                    # Валидация запрошенного эндпоинта
                    if (!array_key_exists($matches[0], $this->app_endpoints)) {
                        throw new Exception('Неизвестная метка заменитель ' . $matches[0]);
                    }
                    $len = strlen($matches[0]);
                    if (($oneData = array_shift($value)) != null) {
                        $sql .= substr($str, $begin, $offset - $begin);
                        if ($oneData === $this->skip())
                            $sql .= $matches[0];
                        else $sql .= $this->{$this->app_endpoints[$matches[0]]}($oneData);
                        $begin = $offset + $len;
                    } else
                        throw new Exception('Недостаточно данных для метки заменителя ' . $matches[0]);
                } elseif (count($value) != 0)
                    throw new Exception('Лишние данные без меток заменителей ' . print_r($value, true));
                else {
                    $sql .= substr($str, $begin);
                    return $sql;
                }
            }
        } while (true);
    }


    /**
     * @param string|integer|float|bool|null|array|DatabaseEngine|\DateTime $value
     * @param bool                                                         $isArray
     * @param string                                                       $prefix
     * @param IQuotation                                                   $quotation
     *
     * @return string
     */
    protected function quoteValue($value, bool $isArray, string $prefix = ''): string
    {
        if ($isArray && is_array($value)) {
            $assoc = $this->is_assoc($value);
            foreach ($value as $k => $v) {
                if ($v instanceof DatabaseEngine) {
                    $value[$k] = $v->__toString();
                    continue;
                }
                $value[$k] = ($prefix === static::FIELD_PREFIX) ? $this->quotation->quoteField($v) : ($assoc ? $this->quotation->quoteField($k) . ' = ' : '') . $this->quotation->quote($v);
            }
            return implode(', ', $value);
        }
        if ($value instanceof DatabaseEngine) {
            return $value->__toString();
        }
        return ($prefix === static::FIELD_PREFIX) ? $this->quotation->quoteField($value) : $this->quotation->quote($value);
    }

    protected function bindInt($value): string
    {
        return $this->quoteValue(intval($value), false);
    }
    protected function bindFloat($value): string
    {
        return $this->quoteValue(float($value), false);
    }
    protected function bindArray($value): string
    {
        return $this->quoteValue($value, true);
    }
    protected function bindid($value): string
    {
        return  $this->quoteValue($value, is_array($value), '@');;
    }
    protected function bindMulti($value): string
    {
        return $this->quoteValue($value, false) . ' ';
    }
    public function skip()
    {
        return static::BIND_SKIP;
    }
    public static function is_assoc(array $array)
    {
        // Keys of the array
        $keys = array_keys($array);

        // If the array keys of the keys match the keys, then the array must
        // not be associative (e.g. the keys array looked like {0:0, 1:1...}).
        return array_keys($keys) !== $keys;
    }
}
