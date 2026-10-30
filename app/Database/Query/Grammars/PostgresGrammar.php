<?php

namespace App\Database\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar as BasePostgresGrammar;

/**
 * Compiles `like` / `not like` as `ilike` / `not ilike`, so searches written for
 * SQLite (where LIKE ignores case) keep matching regardless of letter case.
 */
class PostgresGrammar extends BasePostgresGrammar
{
    protected function whereBasic(Builder $query, $where)
    {
        $operator = strtolower($where['operator']);

        if ($operator === 'like' || $operator === 'not like') {
            $where['operator'] = str_replace('like', 'ilike', $operator);
        }

        return parent::whereBasic($query, $where);
    }
}
