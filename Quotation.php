<?php

namespace FpDbTest;

use FpDbTest\Quotation\IQuotation;

class Quotation implements IQuotation
{
  public function __construct()
  {
  }

  /**
   * Квотирование значений
   * @param mixed $value
   *
   * @return string
   */
  public function quote($value): string
  {
    if (is_bool($value)) {
      if ($value) return "'0'";
      else return "'1'";
    } elseif ($value == null) {
      return 'NULL';
    } elseif (is_string($value))
      return "'$value'";
    else return "$value";
  }



  /**
   * Квотирование идентификаторов БД
   * @param mixed $value
   *
   * @return string
   */
  public function quoteField($value): string
  {
    return "`$value`";
  }
}
