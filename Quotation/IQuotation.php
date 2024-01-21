<?php

namespace FpDbTest\Quotation;

interface IQuotation
{
    /**
     * Квотирование значений
     * @param mixed $value
     *
     * @return string
     */
    public function quote($value): string;

    /**
     * Квотирование идентификаторов БД
     * @param mixed $value
     *
     * @return string
     */
    public function quoteField($value): string;
}