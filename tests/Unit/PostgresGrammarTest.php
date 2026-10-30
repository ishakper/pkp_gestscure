<?php

namespace Tests\Unit;

use App\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostgresGrammarTest extends TestCase
{
    private PostgresGrammar $grammar;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grammar = new PostgresGrammar();
        $this->connection = new Connection(new PDO('sqlite::memory:'));
    }

    public function test_compiles_like_operator_to_ilike(): void
    {
        $builder = new Builder($this->connection, $this->grammar);
        $builder->from('employees')->where('name', 'like', '%john%');

        $sql = $builder->toSql();

        $this->assertStringContainsString('ilike', $sql);
        $this->assertStringNotContainsString('"name"::text like ?', $sql);
    }

    public function test_compiles_not_like_operator_to_not_ilike(): void
    {
        $builder = new Builder($this->connection, $this->grammar);
        $builder->from('employees')->where('name', 'not like', '%john%');

        $sql = $builder->toSql();

        $this->assertStringContainsString('not ilike', $sql);
    }

    public function test_preserves_strict_equality_operator(): void
    {
        $builder = new Builder($this->connection, $this->grammar);
        $builder->from('employees')->where('employee_id', '=', 'USR-1001');

        $sql = $builder->toSql();

        $this->assertStringContainsString('"employee_id" = ?', $sql);
        $this->assertStringNotContainsString('ilike', $sql);
    }

    public function test_preserves_numeric_comparison_operators(): void
    {
        $builder = new Builder($this->connection, $this->grammar);
        $builder->from('doors')->where('id', '>', 100);

        $sql = $builder->toSql();

        $this->assertStringContainsString('"id" > ?', $sql);
    }

    public function test_preserves_case_sensitive_raw_sql(): void
    {
        $builder = new Builder($this->connection, $this->grammar);
        $builder->from('tokens')->whereRaw('"token_hash" LIKE ?', ['AB%']);

        $sql = $builder->toSql();

        $this->assertStringContainsString('"token_hash" LIKE ?', $sql);
        $this->assertStringNotContainsString('ilike', $sql);
    }
}
